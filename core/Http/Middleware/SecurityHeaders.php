<?php

declare(strict_types=1);

namespace SunsetReverie\Http\Middleware;

use Closure;
use SunsetReverie\Http\Request;
use SunsetReverie\Http\Response;

/**
 * 安全响应头（纯 API 场景的默认值）。
 *
 * 只加"对 JSON 接口无副作用"的头：
 * - nosniff：禁止浏览器猜测 MIME；
 * - DENY：禁止被 iframe 嵌入；
 * - no-referrer：不向第三方泄露来源；
 * - CSP default-src 'none'：纯 API 不加载任何资源。
 *
 * 如需放宽（例如允许被嵌入），应显式覆盖而不是删除本中间件。
 */
final class SecurityHeaders implements MiddlewareInterface
{
    /**
     * @param array<string, string> $headers 覆盖或追加的头
     */
    public function __construct(private readonly array $headers = [])
    {
    }

    /**
     * {@inheritDoc}
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
        ];

        foreach (array_merge($defaults, $this->headers) as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
