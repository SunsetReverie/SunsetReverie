<?php

declare(strict_types=1);

namespace SunsetReverie\Log\Middleware;

use Closure;
use Throwable;
use SunsetReverie\Config\Config;
use SunsetReverie\Http\Middleware\MiddlewareInterface;
use SunsetReverie\Http\Request;
use SunsetReverie\Http\Response;
use SunsetReverie\Log\Logger;
use SunsetReverie\Log\LogManager;

/**
 * 访问日志中间件。
 *
 * 做两件事：
 * 1. 把请求信息压入日志上下文栈——业务代码写 `Log::info('...')` 时自动带上 request_id、路径、路由名；
 * 2. 请求结束时记录一条访问日志（状态码 + 耗时）。
 *
 * 预检请求（OPTIONS）默认不记录，避免日志被跨域探测刷满。
 */
final class LogRequest implements MiddlewareInterface
{
    /**
     * @param LogManager $manager 日志管理器
     * @param Config $config 配置
     */
    public function __construct(
        private readonly LogManager $manager,
        private readonly Config $config,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->config->get('log.access_log', true)) {
            return $next($request);
        }

        if ($request->method === 'OPTIONS') {
            return $next($request);
        }

        /** @var Logger $logger */
        $logger = $this->manager->channel();
        $startedAt = microtime(true);

        $logger->pushContext([
            'request_id' => $request->requestId,
            'ip' => $request->ip(),
            'method' => $request->method,
            'path' => $request->path,
            'route' => (string) $request->attribute('route.name', ''),
            'domain' => (string) $request->attribute('api.domain', ''),
        ]);

        try {
            $response = $next($request);

            $logger->info('request', [
                'status' => $response->status(),
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            ]);

            return $response;
        } catch (Throwable $e) {
            $logger->error('request failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            ]);

            throw $e;
        } finally {
            $logger->popContext();
        }
    }
}
