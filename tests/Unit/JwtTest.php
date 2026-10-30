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

    /**
     * 多域：A 域签发的 Token 在 B 域应被拒绝（20010）。
     *
     * @return void
     */
    public function testRejectsTokenFromAnotherDomain(): void
    {
        $context = new \SunsetReverie\Api\DomainContext();

        $context->set(new \SunsetReverie\Api\ApiDomain('admin'));
        $adminGuard = new \SunsetReverie\Auth\Guard\JwtGuard(new JwtCodec(self::SECRET), 3600, 'sub', $context);
        $token = $adminGuard->issue('42');

        $context->set(new \SunsetReverie\Api\ApiDomain('web'));
        $webGuard = new \SunsetReverie\Auth\Guard\JwtGuard(new JwtCodec(self::SECRET), 3600, 'sub', $context);

        $request = \SunsetReverie\Http\Request::create('GET', '/', [], '', ['Authorization' => 'Bearer ' . $token]);

        $e = $this->assertThrows(
            \SunsetReverie\Auth\Exception\AuthenticationException::class,
            static fn () => $webGuard->user($request)
        );

        $this->assertSame(20010, $e->errorCode);
    }

    /**
     * 多域：没有 aud 声明的 Token 同样拒绝（防"去掉 aud 绕过"）。
     *
     * @return void
     */
    public function testRejectsTokenWithoutAudienceInMultiDomain(): void
    {
        $codec = new JwtCodec(self::SECRET);
        $token = $codec->encode(['sub' => '42']);

        $context = new \SunsetReverie\Api\DomainContext();
        $context->set(new \SunsetReverie\Api\ApiDomain('web'));
        $guard = new \SunsetReverie\Auth\Guard\JwtGuard($codec, 3600, 'sub', $context);

        $request = \SunsetReverie\Http\Request::create('GET', '/', [], '', ['Authorization' => 'Bearer ' . $token]);

        $this->assertThrows(
            \SunsetReverie\Auth\Exception\AuthenticationException::class,
            static fn () => $guard->user($request)
        );
    }

    /**
     * 单域（无域上下文）不校验 aud，向后兼容。
     *
     * @return void
     */
    public function testSingleDomainSkipsAudienceCheck(): void
    {
        $codec = new JwtCodec(self::SECRET);
        $token = $codec->encode(['sub' => '42']);
        $guard = new \SunsetReverie\Auth\Guard\JwtGuard($codec);

        $request = \SunsetReverie\Http\Request::create('GET', '/', [], '', ['Authorization' => 'Bearer ' . $token]);

        $this->assertSame('42', $guard->user($request)->id);
    }
}
