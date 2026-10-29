<?php

declare(strict_types=1);

namespace SunsetReverie\Http\Middleware;

use Closure;
use SunsetReverie\Http\Request;
use SunsetReverie\Http\Response;

/**
 * 请求追踪：把请求 ID 回写到响应头，保证「请求头 / 响应头 / 包络 / 日志」四处一致。
 *
 * 客户端可通过 X-Request-Id 传入自有 ID（需符合长度与字符约束），
 * 不合法或缺失时由 Request 生成 UUIDv7。
 */
final class TraceRequest implements MiddlewareInterface
{
    /**
     * {@inheritDoc}
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->requestId === '') {
            return $response;
        }

        return $response->withHeader('X-Request-Id', $request->requestId);
    }
}
