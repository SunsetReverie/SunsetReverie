<?php

declare(strict_types=1);

namespace Tests\Unit;

use SunsetReverie\Auth\Exception\AuthenticationException;
use SunsetReverie\Auth\Jwt\JwtCodec;
use Tests\TestCase;

/**
 * JWT 编解码测试。
 */
final class JwtTest extends TestCase
{
    /** @var string 测试密钥 */
    private const SECRET = '0123456789abcdef0123456789abcdef';

    /**
     * 签发后应能正确解码。
     *
     * @return void
     */
    public function testEncodeDecodeRoundTrip(): void
    {
        $codec = new JwtCodec(self::SECRET);
        $token = $codec->encode(['sub' => '42', 'roles' => ['admin']]);

        $payload = $codec->decode($token);

        $this->assertSame('42', $payload['sub']);
        $this->assertSame(['admin'], $payload['roles']);
        $this->assertArrayHasKey('exp', $payload);
        $this->assertArrayHasKey('iat', $payload);
    }

    /**
     * 篡改签名应被拒绝。
     *
     * @return void
     */
    public function testRejectsTamperedSignature(): void
    {
        $codec = new JwtCodec(self::SECRET);
        $token = $codec->encode(['sub' => '42']);
        $tampered = substr($token, 0, -4) . 'aaaa';

        $this->assertThrows(AuthenticationException::class, static fn () => $codec->decode($tampered));
    }

    /**
     * 换密钥后应验签失败。
     *
     * @return void
     */
    public function testRejectsTokenSignedByAnotherSecret(): void
    {
        $token = (new JwtCodec(self::SECRET))->encode(['sub' => '42']);
        $other = new JwtCodec('ffffffffffffffffffffffffffffffff');

        $this->assertThrows(AuthenticationException::class, static fn () => $other->decode($token));
    }

    /**
     * alg 被改成 none 应被拒绝。
     *
     * @return void
     */
    public function testRejectsNoneAlgorithm(): void
    {
        $codec = new JwtCodec(self::SECRET);
        $header = rtrim(strtr(base64_encode('{"typ":"JWT","alg":"none"}'), '+/', '-_'), '=');
        $body = rtrim(strtr(base64_encode('{"sub":"42","exp":9999999999}'), '+/', '-_'), '=');

        $this->assertThrows(
            AuthenticationException::class,
            static fn () => $codec->decode($header . '.' . $body . '.')
        );
    }

    /**
     * 过期 Token 应被拒绝。
     *
     * @return void
     */
    public function testRejectsExpiredToken(): void
    {
        $codec = new JwtCodec(self::SECRET, 0);
        $token = $codec->encode(['sub' => '42'], -100);

        $e = $this->assertThrows(AuthenticationException::class, static fn () => $codec->decode($token));

        $this->assertStringContains('过期', $e->getMessage());
    }

    /**
     * 密钥过短应直接拒绝（弱密钥保护）。
     *
     * @return void
     */
    public function testRejectsShortSecret(): void
    {
        $this->assertThrows(AuthenticationException::class, static fn () => new JwtCodec('short'));
    }

    /**
     * 格式非法应被拒绝。
     *
     * @return void
     */
    public function testRejectsMalformedToken(): void
    {
        $codec = new JwtCodec(self::SECRET);

        $this->assertThrows(AuthenticationException::class, static fn () => $codec->decode('not-a-token'));
    }
}
