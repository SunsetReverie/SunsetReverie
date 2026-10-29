<?php

declare(strict_types=1);

namespace SunsetReverie\Auth\Jwt;

use SunsetReverie\Auth\Exception\AuthenticationException;

/**
 * JWT 编解码（HS256，零依赖）。
 *
 * 安全约束：
 * 1. **只接受 HS256**：header 的 alg 不在白名单直接拒绝（防 `alg: none` 攻击）；
 * 2. 签名比对用 `hash_equals`（恒定时间，防时序侧信道）；
 * 3. 强制校验 `exp`，可选校验 `nbf` / `iat`；
 * 4. 密钥长度不足 32 字节直接拒绝，避免弱密钥。
 */
final class JwtCodec
{
    /** 允许的算法白名单 */
    private const ALGORITHM = 'HS256';

    /** 密钥最小字节数 */
    private const MIN_SECRET_LENGTH = 32;

    /**
     * @param string $secret 签名密钥（≥32 字节）
     * @param int $leeway 时间校验容差（秒）
     * @throws AuthenticationException 密钥过短时抛出
     */
    public function __construct(
        private readonly string $secret,
        private readonly int $leeway = 30,
    ) {
        if (strlen($this->secret) < self::MIN_SECRET_LENGTH) {
            throw new AuthenticationException(sprintf(
                'JWT 密钥至少需要 %d 字节，当前 %d 字节；可用 sr jwt:secret 生成',
                self::MIN_SECRET_LENGTH,
                strlen($this->secret)
            ));
        }
    }

    /**
     * 签发 Token。
     *
     * @param array<string, mixed> $claims 载荷声明
     * @param int $ttl 有效期秒数
     * @return string JWT 字符串
     */
    public function encode(array $claims, int $ttl = 7200): string
    {
        $now = time();
        $payload = $claims + [
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
            'jti' => bin2hex(random_bytes(8)),
        ];

        $header = $this->base64UrlEncode((string) json_encode(['typ' => 'JWT', 'alg' => self::ALGORITHM]));
        $body = $this->base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $signature = $this->sign($header . '.' . $body);

        return $header . '.' . $body . '.' . $signature;
    }

    /**
     * 校验并解码 Token。
     *
     * @param string $token JWT 字符串
     * @return array<string, mixed> 载荷
     * @throws AuthenticationException 格式错误、签名不符或已过期时抛出
     */
    public function decode(string $token): array
    {
        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            throw new AuthenticationException('Token 格式不合法');
        }

        [$headerSegment, $bodySegment, $signature] = $segments;

        $header = json_decode($this->base64UrlDecode($headerSegment), true);

        if (!is_array($header) || ($header['alg'] ?? '') !== self::ALGORITHM) {
            throw new AuthenticationException('Token 算法不受支持');
        }

        // 签名校验（恒定时间比较）
        if (!hash_equals($this->sign($headerSegment . '.' . $bodySegment), $signature)) {
            throw new AuthenticationException('Token 签名校验失败');
        }

        $payload = json_decode($this->base64UrlDecode($bodySegment), true);

        if (!is_array($payload)) {
            throw new AuthenticationException('Token 载荷不合法');
        }

        $this->assertNotExpired($payload);

        return $payload;
    }

    /**
     * 计算签名。
     *
     * @param string $data 待签数据
     * @return string base64url 编码的签名
     */
    private function sign(string $data): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', $data, $this->secret, true));
    }

    /**
     * 校验时间声明。
     *
     * @param array<string, mixed> $payload 载荷
     * @return void
     * @throws AuthenticationException 过期或尚未生效时抛出
     */
    private function assertNotExpired(array $payload): void
    {
        $now = time();

        if (!isset($payload['exp'])) {
            throw new AuthenticationException('Token 缺少过期时间');
        }

        if ((int) $payload['exp'] + $this->leeway < $now) {
            throw new AuthenticationException('登录已过期，请重新登录');
        }

        if (isset($payload['nbf']) && (int) $payload['nbf'] - $this->leeway > $now) {
            throw new AuthenticationException('Token 尚未生效');
        }
    }

    /**
     * base64url 编码。
     *
     * @param string $value 原始数据
     * @return string 编码结果
     */
    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * base64url 解码。
     *
     * @param string $value 编码数据
     * @return string 原始数据
     */
    private function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;

        if ($remainder !== 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}
