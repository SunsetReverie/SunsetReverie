<?php

declare(strict_types=1);

namespace SunsetReverie\Auth\Guard;

use SunsetReverie\Api\DomainContext;
use SunsetReverie\Auth\AuthenticatedUser;
use SunsetReverie\Auth\Exception\AuthenticationException;
use SunsetReverie\Auth\Jwt\JwtCodec;
use SunsetReverie\Exception\ErrorCode;
use SunsetReverie\Http\Request;

/**
 * JWT 守卫：从 Authorization 头解析 Token 并校验。
 *
 * 设计要点：
 * - 只认 `Authorization: Bearer <token>`，不支持 query 传 token（避免泄露进日志）；
 * - 校验失败抛 AuthenticationException，不做"匿名降级"；
 * - 提供 issue() 用于登录接口签发。
 */
final class JwtGuard
{
    /**
     * @param JwtCodec $codec 编解码器
     * @param int $ttl 签发有效期（秒）
     * @param string $claimKey 主体标识所在的声明名
     * @param DomainContext|null $domainContext 域上下文（多域时用于 aud 校验）
     * @param bool $enforceAudience 是否强制校验 aud
     */
    public function __construct(
        private readonly JwtCodec $codec,
        private readonly int $ttl = 7200,
        private readonly string $claimKey = 'sub',
        private readonly ?DomainContext $domainContext = null,
        private readonly bool $enforceAudience = true,
    ) {
    }

    /**
     * 签发 Token。
     *
     * @param string $subject 主体标识（用户 ID）
     * @param array<string, mixed> $claims 附加声明（如 roles、dom）
     * @return string JWT 字符串
     */
    public function issue(string $subject, array $claims = []): string
    {
        // 多域项目：把当前域写进 aud，另一个域拿到这个 Token 会验不过
        $audience = $this->domainContext?->name() ?? '';

        if ($audience !== '' && $this->enforceAudience && !isset($claims['aud'])) {
            $claims['aud'] = $audience;
        }

        return $this->codec->encode([$this->claimKey => $subject] + $claims, $this->ttl);
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

        $this->assertAudience($payload);

        $subject = $payload[$this->claimKey] ?? null;

        if (!is_string($subject) && !is_int($subject)) {
            throw new AuthenticationException('Token 缺少主体标识');
        }

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
     * 校验 Token 的受众（aud）是否与当前域一致。
     *
     * 单域项目（无域配置）不校验；多域项目**没有 aud 的 Token 同样拒绝**，
     * 否则等于留下"去掉 aud 就能跨域使用"的后门。
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
