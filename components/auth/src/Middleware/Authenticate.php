<?php

declare(strict_types=1);

namespace SunsetReverie\Auth\Middleware;

use Closure;
use SunsetReverie\Auth\AuthManager;
use SunsetReverie\Auth\Guard\JwtGuard;
use SunsetReverie\Http\Middleware\MiddlewareInterface;
use SunsetReverie\Http\Request;
use SunsetReverie\Http\Response;

/**
 * 认证中间件：校验 Token 并把认证主体写入请求属性。
 *
 * 用法（路由级）：
 *   ->middleware([Authenticate::class])
 *
 * 控制器读取：
 *   $user = $request->attribute('auth.user');   // AuthenticatedUser
 */
final class Authenticate implements MiddlewareInterface
{
    /** 请求属性名 */
    public const ATTRIBUTE = 'auth.user';

    /**
     * @param AuthManager $auth 认证管理器
     * @param string|null $guard 守卫名（null 表示默认）
     */
    public function __construct(
        private readonly AuthManager $auth,
        private readonly ?string $guard = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function handle(Request $request, Closure $next): Response
    {
        $guard = $this->auth->jwt($this->guard);
        $user = $guard->user($request);

        return $next($request->withAttribute(self::ATTRIBUTE, $user));
    }
}
