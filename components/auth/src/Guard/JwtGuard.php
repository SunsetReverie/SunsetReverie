<?php

declare(strict_types=1);

namespace SunsetReverie\Auth\Guard;

use SunsetReverie\Api\DomainContext;
use SunsetReverie\Auth\AuthenticatedUser;
use SunsetReverie\Auth\Exception\AuthenticationException;
use SunsetReverie\Auth\Jwt\JwtCodec;
use SunsetReverie\Cache\CacheStoreInterface;
use SunsetReverie\Exception\ErrorCode;
use SunsetReverie\Http\Request;

/**
 * JWT 守卫：签发与校验双 Token（access + refresh）。
 *
 * 设计要点：
 *
 * - 只认 `Authorization: Bearer <token>`，不支持 query 传 token（避免泄露进日志）；
 * - 校验失败抛 AuthenticationException，不做「匿名降级」；
 * - **双 Token**：access 短期（默认 2 小时）用于日常请求，refresh 长期（默认 30 天）
 *   用于换新 access，避免用户频繁重新登录；
 * - **轮换**：每次 refresh 生成新的 refresh token，旧的立即作废——refresh token
 *   被窃取后最多用一次；
 * - **宽限期**：并发刷新时（多个请求同时带同一个 refresh token），第一个成功后
 *   旧的会失效，第二个就会失败。给一个短暂宽限期，期内重放返回同一对新 Token，
 *   避免前端被踢下线；
 * - **类型隔离**：Token 里带 `typ`，refresh token 不能当 access 用；
 * - **版本号**：可选，开启后改密码等操作调 revokeAll() 可让该用户所有旧 Token 失效。
 *
 * 为什么 refresh token 要存缓存：JWT 本身无法撤销（无状态），
 * 要支持「登出即时生效」「轮换」就必须有个地方记录哪些还有效。
 * 存的是 **sha256 摘要**，不是 token 原文——即使缓存泄露也无法直接使用。
 */
final class JwtGuard
{
    /** refresh token 在缓存里的键前缀 */
    private const REFRESH_PREFIX = 'auth:refresh:';

    /** 宽限期映射的键前缀（旧 token → 换出的新 token） */
    private const GRACE_PREFIX = 'auth:grace:';

    /** 用户 token 版本号的键前缀 */
    private const VERSION_PREFIX = 'auth:ver:';

    /**
     * @param JwtCodec $codec 编解码器
     * @param int $ttl access token 有效期（秒）
     * @param string $claimKey 主体标识所在的声明名
     * @param DomainContext|null $domainContext 域上下文（多域时用于 aud 校验）
     * @param bool $enforceAudience 是否强制校验 aud
     * @param CacheStoreInterface|null $cache 缓存存储（存 refresh token；为 null 时 refresh 相关方法不可用）
     * @param int $refreshTtl refresh token 有效期（秒）
     * @param int $refreshGrace 并发刷新的宽限期（秒），0 表示不启用
     * @param bool $enforceVersion 是否校验 token 版本号（开启后 user() 每次查缓存）
     */
    public function __construct(
        private readonly JwtCodec $codec,
        private readonly int $ttl = 7200,
        private readonly string $claimKey = 'sub',
        private readonly ?DomainContext $domainContext = null,
        private readonly bool $enforceAudience = true,
        private readonly ?CacheStoreInterface $cache = null,
        private readonly int $refreshTtl = 2592000,
        private readonly int $refreshGrace = 30,
        private readonly bool $enforceVersion = false,
    ) {
    }

    /**
     * 签发单个 access token。
     *
     * 保留这个方法是向后兼容——老代码用 `issue()` 拿一个字符串。
     * 需要「无感刷新」时用 issueTokens()。
     *
     * @param string $subject 主体标识（用户 ID）
     * @param array<string, mixed> $claims 附加声明（如 roles、dom）
     * @return string JWT 字符串
     */
    public function issue(string $subject, array $claims = []): string
    {
        return $this->codec->encode($this->withBaseClaims($subject, self::TYPE_ACCESS, $claims), $this->ttl);
    }

    /**
     * 签发一对 Token（登录接口用这个）。
     *
     * 返回结构直接可以当响应体：
     *
     * ```json
     * {
     *   "access_token": "eyJ...",
     *   "refresh_token": "eyJ...",
     *   "token_type": "Bearer",
     *   "expires_in": 7200
     * }
     * ```
     *
     * @param string $subject 主体标识
     * @param array<string, mixed> $claims 附加声明
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int} Token 对
     * @throws AuthenticationException 未配置缓存时抛出
     */
    public function issueTokens(string $subject, array $claims = []): array
    {
        $this->assertCacheAvailable();

        $access = $this->codec->encode($this->withBaseClaims($subject, self::TYPE_ACCESS, $claims), $this->ttl);

        [$refresh, $jti] = $this->makeRefreshToken($subject, $claims);

        $this->storeRefresh($refresh, $subject, $jti);

        return $this->tokenPair($access, $refresh);
    }

    /**
     * 用 refresh token 换新的一对（无感刷新）。
     *
     * 行为：
     *
     * - 有效 → 返回新的一对，旧 refresh 立即作废（轮换）
     * - 已用过但在宽限期内 → 返回**同一对**新 Token（并发刷新不会互相踢掉）
     * - 不存在 / 已过期 / 已撤销 → 抛 AuthenticationException（前端应跳登录）
     *
     * @param string $refreshToken 客户端带的 refresh token
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int} 新 Token 对
     * @throws AuthenticationException 无效或已撤销时抛出
     */
    public function refresh(string $refreshToken): array
    {
        $this->assertCacheAvailable();

        $payload = $this->decodeRefresh($refreshToken);

        $subject = $this->subjectOf($payload);
        $hash = $this->hashOf($refreshToken);

        $stored = $this->cacheStore()->get(self::REFRESH_PREFIX . $hash);

        // 正常路径：这个 refresh token 还在有效列表里
        if (is_array($stored)) {
            $claims = $this->extraClaims($payload);

            $access = $this->codec->encode($this->withBaseClaims($subject, self::TYPE_ACCESS, $claims), $this->ttl);
            [$newRefresh, $jti] = $this->makeRefreshToken($subject, $claims);

            // 轮换：旧的立即删掉
            $this->cache?->forget(self::REFRESH_PREFIX . $hash);
            $this->storeRefresh($newRefresh, $subject, $jti);

            $pair = $this->tokenPair($access, $newRefresh);

            // 宽限期：让并发请求拿到同一对，而不是被踢
            if ($this->refreshGrace > 0) {
                $this->cacheStore()->put(self::GRACE_PREFIX . $hash, $pair, $this->refreshGrace);
            }

            return $pair;
        }

        /*
         * 已用过：看是否在宽限期内。
         *
         * 场景是「前端并发刷新」——两个请求几乎同时带着同一个 refresh token，
         * 第一个走完轮换把旧的删了，第二个到这里。返回同一对即可，
         * 否则用户会被莫名踢下线。
         */
        $replayed = $this->cacheStore()->get(self::GRACE_PREFIX . $hash);

        if (is_array($replayed) && is_string($replayed['access_token'] ?? null)
            && is_string($replayed['refresh_token'] ?? null)) {
            /** @var array{access_token: string, refresh_token: string, token_type: string, expires_in: int} $replayed */
            return $replayed;
        }

        throw new AuthenticationException(
            ErrorCode::TokenExpired->message(),
            ErrorCode::TokenExpired->value,
        );
    }

    /**
     * 登出：撤销这个 refresh token。
     *
     * 只删 refresh——access token 会自然过期（JWT 无法单独撤销）。
     * 要「立刻让 access 也失效」得开 enforceVersion，然后调 revokeAll()。
     *
     * 传入非法 token 不抛异常（幂等），避免登出接口因为 token 已过期而失败。
     *
     * @param string $refreshToken refresh token
     * @return void
     */
    public function logout(string $refreshToken): void
    {
        if ($this->cache === null || $refreshToken === '') {
            return;
        }

        $hash = $this->hashOf($refreshToken);

        $this->cacheStore()->forget(self::REFRESH_PREFIX . $hash);
        $this->cacheStore()->forget(self::GRACE_PREFIX . $hash);
    }

    /**
     * 让某个用户的所有 Token 失效（改密码、封号、强制下线）。
     *
     * 依赖 `enforceVersion = true`——开启后 user() 每次会比对 Token 里的 `ver`
     * 与缓存里的当前版本号，不一致即拒绝。
     *
     * 代价：每个带认证的请求多一次缓存读取。不开启时该方法只清版本号，
     * 对已签发的 Token 无效（JWT 无状态的本性）。
     *
     * @param string $subject 主体标识
     * @return void
     */
    public function revokeAll(string $subject): void
    {
        if ($this->cache === null) {
            return;
        }

        $key = self::VERSION_PREFIX . $subject;

        $current = $this->cacheStore()->get($key);
        $next = is_int($current) ? $current + 1 : 1;

        $this->cacheStore()->put($key, $next, $this->refreshTtl);
    }

    /**
     * 解析当前请求的认证主体（失败抛异常）。
     *
     * @param Request $request 当前请求
     * @return AuthenticatedUser 认证主体
     * @throws AuthenticationException 未携带或校验失败时抛出
     */
    public function user(Request $request): AuthenticatedUser
    {
        $token = $this->extractToken($request);

        if ($token === null) {
            throw new AuthenticationException();
        }

        try {
            $payload = $this->codec->decode($token);
        } catch (AuthenticationException $e) {
            // 过期与签名失败区分错误码，便于前端决定是否跳登录
            $code = str_contains($e->getMessage(), '过期') ? 20002 : 20001;

            throw new AuthenticationException($e->getMessage(), $code);
        }

        $this->assertTokenType($payload, self::TYPE_ACCESS);
        $this->assertAudience($payload);

        $subject = $payload[$this->claimKey] ?? null;

        if (!is_string($subject) && !is_int($subject)) {
            throw new AuthenticationException('Token 缺少主体标识');
        }

        $this->assertVersion((string) $subject, $payload);

        return new AuthenticatedUser((string) $subject, $payload);
    }

    /**
     * 解析当前请求的认证主体（失败返回 null）。
     *
     * @param Request $request 当前请求
     * @return AuthenticatedUser|null 认证主体
     */
    public function tryUser(Request $request): ?AuthenticatedUser
    {
        try {
            return $this->user($request);
        } catch (AuthenticationException) {
            return null;
        }
    }

    /**
     * access token 的类型标识。
     */
    private const TYPE_ACCESS = 'access';

    /**
     * refresh token 的类型标识。
     */
    private const TYPE_REFRESH = 'refresh';

    /**
     * 组装基础声明（主体、类型、域、版本号）。
     *
     * @param string $subject 主体
     * @param string $type token 类型
     * @param array<string, mixed> $claims 调用方传的声明
     * @return array<string, mixed> 完整声明
     */
    private function withBaseClaims(string $subject, string $type, array $claims): array
    {
        $base = [
            $this->claimKey => $subject,
            'typ' => $type,
        ];

        // 多域项目：把当前域写进 aud，另一个域拿到这个 Token 会验不过
        $audience = $this->domainContext?->name() ?? '';

        if ($audience !== '' && $this->enforceAudience) {
            $base['aud'] = $audience;
        }

        if ($this->enforceVersion) {
            $base['ver'] = $this->currentVersion($subject);
        }

        // 调用方的声明优先（可以覆盖 aud / typ，但不建议）
        return $claims + $base;
    }

    /**
     * 生成 refresh token 并返回 jti。
     *
     * @param string $subject 主体
     * @param array<string, mixed> $claims 附加声明
     * @return array{0: string, 1: string} [token, jti]
     */
    private function makeRefreshToken(string $subject, array $claims): array
    {
        $jti = bin2hex(random_bytes(16));

        $token = $this->codec->encode(
            $this->withBaseClaims($subject, self::TYPE_REFRESH, ['jti' => $jti] + $claims),
            $this->refreshTtl,
        );

        return [$token, $jti];
    }

    /**
     * 把 refresh token 写入缓存（存摘要，不存原文）。
     *
     * @param string $token refresh token
     * @param string $subject 主体
     * @param string $jti 唯一标识
     * @return void
     */
    private function storeRefresh(string $token, string $subject, string $jti): void
    {
        $this->cacheStore()->put(
            self::REFRESH_PREFIX . $this->hashOf($token),
            ['sub' => $subject, 'jti' => $jti, 'at' => time()],
            $this->refreshTtl,
        );
    }

    /**
     * 解码并校验 refresh token。
     *
     * @param string $token refresh token
     * @return array<string, mixed> 载荷
     * @throws AuthenticationException 无效时抛出
     */
    private function decodeRefresh(string $token): array
    {
        try {
            $payload = $this->codec->decode($token);
        } catch (AuthenticationException $e) {
            $code = str_contains($e->getMessage(), '过期') ? 20002 : 20001;

            throw new AuthenticationException($e->getMessage(), $code);
        }

        $this->assertTokenType($payload, self::TYPE_REFRESH);
        $this->assertAudience($payload);

        return $payload;
    }

    /**
     * 校验 token 类型。
     *
     * 老版本签发的 access token 没有 `typ`，按 access 处理以保持兼容。
     *
     * @param array<string, mixed> $payload 载荷
     * @param string $expected 期望类型
     * @return void
     * @throws AuthenticationException 类型不符时抛出
     */
    private function assertTokenType(array $payload, string $expected): void
    {
        $type = $payload['typ'] ?? null;

        if ($type === null) {
            if ($expected === self::TYPE_REFRESH) {
                // 没有 typ 的一定是老的 access token，不能拿来刷新
                throw new AuthenticationException('该 Token 不是 refresh token');
            }

            return;
        }

        if ($type !== $expected) {
            throw new AuthenticationException(sprintf(
                'Token 类型不匹配：需要 %s，实际 %s',
                $expected,
                is_string($type) ? $type : gettype($type)
            ));
        }
    }

    /**
     * 校验 Token 的受众（aud）是否与当前域一致。
     *
     * 单域项目（无域配置）不校验；多域项目**没有 aud 的 Token 同样拒绝**，
     * 否则等于留下「去掉 aud 就能跨域使用」的后门。
     *
     * @param array<string, mixed> $payload Token 载荷
     * @return void
     * @throws AuthenticationException 域不匹配时抛出
     */
    private function assertAudience(array $payload): void
    {
        if (!$this->enforceAudience) {
            return;
        }

        $current = $this->domainContext?->name() ?? '';

        if ($current === '') {
            return;
        }

        $audience = $payload['aud'] ?? null;

        if ($audience !== $current) {
            throw new AuthenticationException(
                ErrorCode::TokenDomainMismatch->message(),
                ErrorCode::TokenDomainMismatch->value,
            );
        }
    }

    /**
     * 校验 token 版本号（revokeAll 后旧 Token 失效）。
     *
     * @param string $subject 主体
     * @param array<string, mixed> $payload 载荷
     * @return void
     * @throws AuthenticationException 版本不一致时抛出
     */
    private function assertVersion(string $subject, array $payload): void
    {
        if (!$this->enforceVersion) {
            return;
        }

        $inToken = $payload['ver'] ?? 0;
        $inToken = is_int($inToken) ? $inToken : 0;

        if ($inToken !== $this->currentVersion($subject)) {
            throw new AuthenticationException('登录状态已失效，请重新登录');
        }
    }

    /**
     * 当前版本号。
     *
     * @param string $subject 主体
     * @return int 版本号（未设置时为 0）
     */
    private function currentVersion(string $subject): int
    {
        if ($this->cache === null) {
            return 0;
        }

        $value = $this->cacheStore()->get(self::VERSION_PREFIX . $subject);

        return is_int($value) ? $value : 0;
    }

    /**
     * 从载荷里取主体标识。
     *
     * @param array<string, mixed> $payload 载荷
     * @return string 主体
     * @throws AuthenticationException 缺失时抛出
     */
    private function subjectOf(array $payload): string
    {
        $subject = $payload[$this->claimKey] ?? null;

        if (!is_string($subject) && !is_int($subject)) {
            throw new AuthenticationException('Token 缺少主体标识');
        }

        return (string) $subject;
    }

    /**
     * 取出调用方自己放的声明（刷新后要带到新 Token 里）。
     *
     * 排除框架自己维护的那些，否则会出现 iat/exp 被旧值覆盖的问题。
     *
     * @param array<string, mixed> $payload 旧载荷
     * @return array<string, mixed> 需要保留的声明
     */
    private function extraClaims(array $payload): array
    {
        $reserved = [
            $this->claimKey, 'typ', 'aud', 'iat', 'nbf', 'exp', 'jti', 'ver',
        ];

        return array_diff_key($payload, array_flip($reserved));
    }

    /**
     * 组装返回结构。
     *
     * @param string $access access token
     * @param string $refresh refresh token
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int} Token 对
     */
    private function tokenPair(string $access, string $refresh): array
    {
        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'token_type' => 'Bearer',
            'expires_in' => $this->ttl,
        ];
    }

    /**
     * 计算 token 的缓存键摘要。
     *
     * @param string $token token
     * @return string sha256 十六进制
     */
    private function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * 确认缓存可用。
     *
     * @return void
     * @throws AuthenticationException 没有缓存时抛出
     */
    private function assertCacheAvailable(): void
    {
        if ($this->cache === null) {
            throw new AuthenticationException(
                '双 Token 需要缓存支持：请确认 config/cache.php 已配置可用驱动，'
                . '或不用 refresh 相关方法、只用 issue()'
            );
        }
    }

    /**
     * 取缓存存储（已确认非 null）。
     *
     * @return CacheStoreInterface 缓存存储
     * @throws AuthenticationException 未配置缓存时抛出
     */
    private function cacheStore(): CacheStoreInterface
    {
        $this->assertCacheAvailable();

        // 上面的断言已排除 null，这里再判一次是为了让静态分析通过
        if ($this->cache === null) {
            throw new AuthenticationException('缓存未配置');
        }

        return $this->cache;
    }

    /**
     * 从请求头提取 Bearer Token。
     *
     * @param Request $request 当前请求
     * @return string|null Token
     */
    private function extractToken(Request $request): ?string
    {
        $header = (string) $request->header('authorization', '');

        if ($header === '' || !str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token === '' ? null : $token;
    }
}
