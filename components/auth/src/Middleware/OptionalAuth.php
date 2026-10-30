<?php

declare(strict_types=1);

namespace SunsetReverie\Auth\Middleware;

use Closure;
use SunsetReverie\Auth\AuthManager;
use SunsetReverie\Auth\Exception\AuthenticationException;
use SunsetReverie\Http\Middleware\MiddlewareInterface;
use SunsetReverie\Http\Request;
use SunsetReverie\Http\Response;

/**
 * 可选认证中间件：有 Token 就解析，没有也放行。
 *
 * 解决的场景：公开接口需要知道"当前用户是谁"，但不能强制登录。
 * 例如文章列表要返回 `liked`（我是否点过赞）、商品详情要返回"已收藏"，
 * 游客访问时这些字段就是 false。
 *
 * 与 Authenticate 的区别：
 * - Authenticate：无 Token / Token 无效 → 401，**阻断**
 * - OptionalAuth：无 Token / Token 无效 → 放行，属性不写入
 *
 * 注意：Token 无效时**静默忽略**而不是报错，否则前端拿过期 Token 访问公开
 * 页面会整页 401——这类体验问题在真实项目里很常见。需要严格校验时用 Authenticate。
 */
final class OptionalAuth implements MiddlewareInterface
{
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
        try {
            $user = $this->auth->jwt($this->guard)->user($request);

            return $next($request->withAttribute(Authenticate::ATTRIBUTE, $user));
        } catch (AuthenticationException) {
            // 没有 Token 或已失效：当作游客，不阻断请求
            return $next($request);
        }
    }
}
