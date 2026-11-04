<?php

declare(strict_types=1);

namespace Tests\Unit;

use SunsetReverie\Auth\Exception\AuthenticationException;
use SunsetReverie\Auth\Guard\JwtGuard;
use SunsetReverie\Auth\Jwt\JwtCodec;
use SunsetReverie\Cache\Driver\ArrayStore;
use SunsetReverie\Http\Request;
use Tests\TestCase;

/**
 * 双 Token：access + refresh。
 *
 * 背景：早期只签发单个 access token（默认 2 小时）。用户要么频繁重新登录，
 * 要么把 ttl 调得很长——前者体验差，后者泄露后窗口太大。
 * 标准做法是「短 access + 长 refresh」，配合轮换和撤销。
 *
 * 这里覆盖的都是**容易写错且后果严重**的点：
 *
 *   - 轮换：refresh 用过一次就该作废，否则泄露后可无限续期
 *   - 宽限期：并发刷新时不能把用户踢下线
 *   - 类型隔离：refresh token 不能当 access 用（否则 30 天有效期等于没有）
 *   - 撤销：登出后 refresh 必须真的失效
 */
final class AuthRefreshTest extends TestCase
{
    /**
     * 造一个带缓存的守卫。
     *
     * @param int $grace 宽限期秒数
     * @param bool $enforceVersion 是否启用版本号
     * @param ArrayStore|null $store 复用同一个 store（测多守卫场景）
     * @return JwtGuard 守卫
     */
    private function guard(int $grace = 30, bool $enforceVersion = false, ?ArrayStore $store = null): JwtGuard
    {
        return new JwtGuard(
            codec: new JwtCodec(str_repeat('test-secret-', 4), 0),
            ttl: 7200,
            /*
             * ArrayStore 的数据是 static 的——所有实例共享同一份。
             * 每个测试用唯一 prefix，否则上一个用例的 refresh token
             * 会漏到下一个用例里（表现为「本该失效的 token 还能用」）。
             */
            cache: $store ?? new ArrayStore('t' . bin2hex(random_bytes(6)) . ':', 3600),
            refreshTtl: 2592000,
            refreshGrace: $grace,
            enforceVersion: $enforceVersion,
        );
    }

    /**
     * 把 token 塞进请求头。
     *
     * @param string $token token
     * @return Request 请求
     */
    private function requestWith(string $token): Request
    {
        return Request::create(headers: ['authorization' => 'Bearer ' . $token]);
    }

    /**
     * issueTokens 返回标准四字段。
     *
     * @return void
     */
    public function testIssueTokensReturnsPair(): void
    {
        $pair = $this->guard()->issueTokens('42', ['role' => 'admin']);

        $this->assertTrue(isset($pair['access_token']), '应有 access_token');
        $this->assertTrue(isset($pair['refresh_token']), '应有 refresh_token');
        $this->assertSame('Bearer', $pair['token_type']);
        $this->assertSame(7200, $pair['expires_in']);
        $this->assertTrue($pair['access_token'] !== $pair['refresh_token'], '两种 Token 不应相同');
    }

    /**
     * refresh 换新的一对，且旧的立即作废（轮换）。
     *
     * @return void
     */
    public function testRefreshRotatesToken(): void
    {
        // 关掉宽限期，这样旧 token 是真的作废而不是走重放分支
        $guard = $this->guard(grace: 0);
        $pair = $guard->issueTokens('42');

        $new = $guard->refresh($pair['refresh_token']);

        $this->assertTrue($new['refresh_token'] !== $pair['refresh_token'], 'refresh token 应轮换');

        // 新的能继续往下滚
        $third = $guard->refresh($new['refresh_token']);
        $this->assertTrue($third['refresh_token'] !== $new['refresh_token'], '第二轮也应轮换');

        // 最初那个已经作废
        $this->assertThrows(
            AuthenticationException::class,
            static fn () => $guard->refresh($pair['refresh_token']),
            '旧 refresh token 应已失效'
        );
    }

    /**
     * 宽限期内重放同一个 refresh token，返回**同一对**新 Token。
     *
     * 场景：前端两个请求几乎同时刷新。
     *
     * @return void
     */
    public function testGracePeriodReturnsSamePair(): void
    {
        $guard = $this->guard(grace: 30);
        $pair = $guard->issueTokens('42');

        $first = $guard->refresh($pair['refresh_token']);
        $second = $guard->refresh($pair['refresh_token']);

        $this->assertSame(
            $first['access_token'],
            $second['access_token'],
            '宽限期内重放应返回同一对，否则并发刷新会把用户踢下线'
        );
        $this->assertSame($first['refresh_token'], $second['refresh_token']);
    }

    /**
     * 宽限期关闭时，重放直接失败（明确告诉前端要重新登录）。
     *
     * @return void
     */
    public function testReplayFailsWithoutGrace(): void
    {
        $guard = $this->guard(grace: 0);
        $pair = $guard->issueTokens('42');

        $guard->refresh($pair['refresh_token']);

        $this->assertThrows(
            AuthenticationException::class,
            static fn () => $guard->refresh($pair['refresh_token'])
        );
    }

    /**
     * 登出后 refresh token 立即失效。
     *
     * @return void
     */
    public function testLogoutRevokesRefresh(): void
    {
        $guard = $this->guard(grace: 0);
        $pair = $guard->issueTokens('42');

        $guard->logout($pair['refresh_token']);

        $this->assertThrows(
            AuthenticationException::class,
            static fn () => $guard->refresh($pair['refresh_token'])
        );
    }

    /**
     * 登出是幂等的：传非法/空 token 不抛异常。
     *
     * 否则「token 已过期时点登出」会报错，前端体验很差。
     *
     * @return void
     */
    public function testLogoutIsIdempotent(): void
    {
        $guard = $this->guard();

        $guard->logout('');
        $guard->logout('not-a-token');

        $this->assertTrue(true, '登出不应因为 token 无效而抛异常');
    }

    /**
     * refresh token 不能当 access token 用。
     *
     * 否则 30 天有效期的 refresh 就等于 30 天的访问权限，
     * 「短 access」的设计完全失去意义。
     *
     * @return void
     */
    public function testRefreshTokenIsRejectedAsAccess(): void
    {
        $guard = $this->guard();
        $pair = $guard->issueTokens('42');

        $this->assertThrows(
            AuthenticationException::class,
            fn () => $guard->user($this->requestWith($pair['refresh_token'])),
            'refresh token 不能用于访问接口'
        );
    }

    /**
     * access token 能正常通过 user()。
     *
     * @return void
     */
    public function testAccessTokenWorks(): void
    {
        $guard = $this->guard();
        $pair = $guard->issueTokens('42', ['role' => 'admin']);

        $user = $guard->user($this->requestWith($pair['access_token']));

        $this->assertSame('42', $user->id);
        $this->assertSame('admin', $user->claim('role'));
    }

    /**
     * access token 不能拿来刷新。
     *
     * @return void
     */
    public function testAccessTokenCannotRefresh(): void
    {
        $guard = $this->guard();
        $pair = $guard->issueTokens('42');

        $this->assertThrows(
            AuthenticationException::class,
            static fn () => $guard->refresh($pair['access_token'])
        );
    }

    /**
     * 刷新后自定义声明要保留（role、昵称之类不该丢）。
     *
     * @return void
     */
    public function testRefreshKeepsCustomClaims(): void
    {
        $guard = $this->guard();
        $pair = $guard->issueTokens('42', ['role' => 'admin', 'name' => '夕梦']);

        $new = $guard->refresh($pair['refresh_token']);
        $user = $guard->user($this->requestWith($new['access_token']));

        $this->assertSame('admin', $user->claim('role'));
        $this->assertSame('夕梦', $user->claim('name'));
    }

    /**
     * revokeAll 后旧 access token 失效（需要 enforceVersion）。
     *
     * @return void
     */
    public function testRevokeAllInvalidatesAccess(): void
    {
        $store = new ArrayStore('t' . bin2hex(random_bytes(6)) . ':', 3600);
        $guard = $this->guard(enforceVersion: true, store: $store);

        $pair = $guard->issueTokens('42');

        // 撤销前能用
        $this->assertSame('42', $guard->user($this->requestWith($pair['access_token']))->id);

        $guard->revokeAll('42');

        // 撤销后同一个 token 就失效了
        $this->assertThrows(
            AuthenticationException::class,
            fn () => $guard->user($this->requestWith($pair['access_token']))
        );
    }

    /**
     * 未开 enforceVersion 时 revokeAll 不影响已签发的 access。
     *
     * 这是 JWT 无状态的必然结果——文档里要讲清楚，
     * 否则有人会以为调了 revokeAll 所有 token 立刻失效。
     *
     * @return void
     */
    public function testRevokeAllDoesNothingWithoutVersionCheck(): void
    {
        $guard = $this->guard(enforceVersion: false);
        $pair = $guard->issueTokens('42');

        $guard->revokeAll('42');

        $this->assertSame(
            '42',
            $guard->user($this->requestWith($pair['access_token']))->id,
            '未开版本号校验时 access token 仍然有效（JWT 无状态）'
        );
    }

    /**
     * 没有缓存时，issueTokens 给出明确错误而不是静默降级。
     *
     * @return void
     */
    public function testIssueTokensFailsWithoutCache(): void
    {
        $guard = new JwtGuard(
            codec: new JwtCodec(str_repeat('test-secret-', 4), 0),
            ttl: 7200,
            cache: null,
        );

        $e = $this->assertThrows(
            AuthenticationException::class,
            static fn () => $guard->issueTokens('42')
        );

        $this->assertStringContains('缓存', $e->getMessage());
    }

    /**
     * 没有缓存时，单个 access token 仍然可用（降级但不断掉）。
     *
     * @return void
     */
    public function testIssueStillWorksWithoutCache(): void
    {
        $guard = new JwtGuard(
            codec: new JwtCodec(str_repeat('test-secret-', 4), 0),
            ttl: 7200,
            cache: null,
        );

        $token = $guard->issue('42');

        $this->assertSame('42', $guard->user($this->requestWith($token))->id);
    }

    /**
     * 老版本签发的 token（没有 typ 字段）仍能用。
     *
     * 兼容性：升级框架后不该让所有在线用户立刻掉线。
     *
     * @return void
     */
    public function testLegacyTokenWithoutTypeStillWorks(): void
    {
        $codec = new JwtCodec(str_repeat('test-secret-', 4), 0);

        $guard = new JwtGuard(codec: $codec, ttl: 7200, cache: new ArrayStore('t' . bin2hex(random_bytes(6)) . ':', 3600));

        // 模拟老版本：不带 typ
        $legacy = $codec->encode(['sub' => '42', 'role' => 'admin'], 7200);

        $user = $guard->user($this->requestWith($legacy));

        $this->assertSame('42', $user->id);
        $this->assertSame('admin', $user->claim('role'));
    }

    /**
     * 老版本 token 不能被当成 refresh token。
     *
     * @return void
     */
    public function testLegacyTokenCannotRefresh(): void
    {
        $codec = new JwtCodec(str_repeat('test-secret-', 4), 0);

        $guard = new JwtGuard(codec: $codec, ttl: 7200, cache: new ArrayStore('t' . bin2hex(random_bytes(6)) . ':', 3600));

        $legacy = $codec->encode(['sub' => '42'], 7200);

        $this->assertThrows(
            AuthenticationException::class,
            static fn () => $guard->refresh($legacy)
        );
    }
}
