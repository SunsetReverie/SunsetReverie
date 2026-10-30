<?php

declare(strict_types=1);

namespace SunsetReverie\Http\Middleware;

use Closure;
use SunsetReverie\Config\Config;
use SunsetReverie\Http\Request;
use SunsetReverie\Http\Response;

/**
 * 跨域（CORS）中间件。
 *
 * 行为：
 * 1. 未配置 `cors.allowed_origins` 时完全透传（零开销，不外发任何 CORS 头）；
 * 2. 预检请求（OPTIONS + Access-Control-Request-Method）直接返回 204，不进入路由；
 * 3. 实际请求在响应上补 CORS 头，并始终带 `Vary: Origin`（避免 CDN 缓存串源）。
 *
 * 安全约束（见规划文档 11.7）：
 * - `allowed_origins` 含 `*` 时**禁止**开启 `supports_credentials`（浏览器本身也会拒绝）；
 * - 不在白名单的 Origin 不返回任何 CORS 头，由浏览器拦截，服务端不报错；
 * - 显式白名单时回显具体 Origin，而不是 `*`。
 */
final class HandleCors implements MiddlewareInterface
{
    /**
     * @param Config $config 配置
     */
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function handle(Request $request, Closure $next): Response
    {
        $allowedOrigins = (array) $this->config->get('cors.allowed_origins', []);

        if ($allowedOrigins === []) {
            return $next($request);
        }

        $origin = (string) $request->header('origin', '');
        $allowed = $this->isAllowedOrigin($origin, $allowedOrigins);

        // 预检：直接短路，不进入路由与鉴权
        if ($request->method === 'OPTIONS' && $request->header('access-control-request-method') !== null) {
            $response = Response::noContent()->withHeader('Vary', 'Origin');

            return $allowed
                ? $response->withHeaders($this->preflightHeaders($origin))
                : $response;
        }

        $response = $next($request);

        if (!$allowed) {
            return $response->withHeader('Vary', 'Origin');
        }

        return $response->withHeaders($this->simpleHeaders($origin));
    }

    /**
     * 判断 Origin 是否在允许列表内。
     *
     * @param string $origin 请求来源
     * @param array<int, mixed> $allowedOrigins 允许列表（来自配置，内部逐项转字符串比较）
     * @return bool 允许返回 true
     */
    private function isAllowedOrigin(string $origin, array $allowedOrigins): bool
    {
        if ($origin === '') {
            return false;
        }

        return in_array('*', $allowedOrigins, true)
            || in_array($origin, $allowedOrigins, true);
    }

    /**
     * 预检响应头。
     *
     * @param string $origin 请求来源
     * @return array<string, string> 响应头
     */
    private function preflightHeaders(string $origin): array
    {
        $headers = [
            'Access-Control-Allow-Origin' => $this->allowOriginValue($origin),
            'Access-Control-Allow-Methods' => implode(', ', (array) $this->config->get(
                'cors.allowed_methods',
                ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']
            )),
            'Access-Control-Allow-Headers' => implode(', ', (array) $this->config->get(
                'cors.allowed_headers',
                ['Content-Type', 'Authorization', 'X-Request-Id', 'Idempotency-Key']
            )),
            'Access-Control-Max-Age' => (string) $this->config->get('cors.max_age', 86400),
        ];

        if ($this->config->get('cors.supports_credentials', false)) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        return $headers;
    }

    /**
     * 实际请求响应头。
     *
     * @param string $origin 请求来源
     * @return array<string, string> 响应头
     */
    private function simpleHeaders(string $origin): array
    {
        $headers = [
            'Access-Control-Allow-Origin' => $this->allowOriginValue($origin),
            'Vary' => 'Origin',
        ];

        $exposed = (array) $this->config->get('cors.exposed_headers', []);

        if ($exposed !== []) {
            $headers['Access-Control-Expose-Headers'] = implode(', ', $exposed);
        }

        if ($this->config->get('cors.supports_credentials', false)) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        return $headers;
    }

    /**
     * 计算 Allow-Origin 的值。
     *
     * 带凭证时必须是具体 Origin（不能是 `*`），否则需要回显来源。
     *
     * @param string $origin 请求来源
     * @return string 头部值
     */
    private function allowOriginValue(string $origin): string
    {
        $wildcard = in_array('*', (array) $this->config->get('cors.allowed_origins', []), true);

        if ($wildcard && !$this->config->get('cors.supports_credentials', false)) {
            return '*';
        }

        return $origin;
    }
}
