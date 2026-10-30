<?php

declare(strict_types=1);

namespace SunsetReverie\RateLimit\Middleware;

use Closure;
use SunsetReverie\Config\Config;
use SunsetReverie\Exception\ErrorCode;
use SunsetReverie\Http\Envelope;
use SunsetReverie\Http\Middleware\HasParameters;
use SunsetReverie\Http\Middleware\MiddlewareInterface;
use SunsetReverie\Http\Request;
use SunsetReverie\Http\Response;
use SunsetReverie\RateLimit\RateLimiter;

/**
 * 限流中间件。
 *
 * 规则查找顺序：按路由名匹配 `ratelimit.routes`，未命中则用 `ratelimit.default`。
 * 超限时**直接短路返回 429**（不抛异常），以便精确控制响应头。
 */
final class ThrottleRequests implements MiddlewareInterface, HasParameters
{
    /**
     * @param RateLimiter $limiter 限流器
     * @param Config $config 配置
     */
    /** @var int|null 路由声明的上限（null 表示用配置） */
    private ?int $maxAttempts = null;

    /** @var int|null 路由声明的窗口秒数 */
    private ?int $decaySeconds = null;

    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly Config $config,
    ) {
    }

    /**
     * 接收路由上的参数：`throttle:300,1` → [300, 1]。
     *
     * @param list<string> $parameters 参数
     * @return static 新实例
     */
    public function withParameters(array $parameters): static
    {
        $clone = clone $this;

        if (isset($parameters[0]) && (int) $parameters[0] > 0) {
            $clone->maxAttempts = (int) $parameters[0];
        }

        if (isset($parameters[1]) && (int) $parameters[1] > 0) {
            $clone->decaySeconds = (int) $parameters[1];
        }

        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->config->get('ratelimit.enabled', true)) {
            return $next($request);
        }

        $rule = $this->resolveRule($request);
        $limit = $this->limiter->attempt(
            $this->resolveKey($request, $rule),
            (int) ($rule['max_attempts'] ?? 60),
            (int) ($rule['decay_seconds'] ?? 60),
        );

        if (!$limit->allowed) {
            $payload = Envelope::error(
                ErrorCode::TooManyRequests->value,
                ErrorCode::TooManyRequests->message(),
                $request->requestId,
            );

            return Response::json($payload, 429)
                ->withHeader('Retry-After', (string) $limit->retryAfter)
                ->withHeader('X-RateLimit-Limit', (string) $limit->limit)
                ->withHeader('X-RateLimit-Remaining', '0')
                ->withHeader('X-RateLimit-Reset', (string) (time() + $limit->retryAfter));
        }

        return $next($request)
            ->withHeader('X-RateLimit-Limit', (string) $limit->limit)
            ->withHeader('X-RateLimit-Remaining', (string) $limit->remaining)
            ->withHeader('X-RateLimit-Reset', (string) (time() + $limit->retryAfter));
    }

    /**
     * 解析当前请求适用的限流规则。
     *
     * 匹配优先级：精确路由名 → 前缀通配（最长优先）→ default。
     * 通配写法：`admin.*`、`admin.v1.auth.*`（尾部 `*` 表示前缀）。
     *
     * @param Request $request 当前请求
     * @return array<string, mixed> 规则（max_attempts / decay_seconds / by）
     */
    private function resolveRule(Request $request): array
    {
        // 路由上显式声明的参数优先级最高：throttle:5,60
        if ($this->maxAttempts !== null) {
            return [
                'max_attempts' => $this->maxAttempts,
                'decay_seconds' => $this->decaySeconds ?? (int) $this->config->get('ratelimit.default.decay_seconds', 60),
            ];
        }

        $routes = (array) $this->config->get('ratelimit.routes', []);
        $name = (string) $request->attribute('route.name', '');

        if ($name !== '') {
            if (isset($routes[$name]) && is_array($routes[$name])) {
                return $routes[$name];
            }

            $matched = null;
            $matchedLength = -1;

            foreach ($routes as $pattern => $rule) {
                if (!is_array($rule) || !str_ends_with((string) $pattern, '*')) {
                    continue;
                }

                $prefix = substr((string) $pattern, 0, -1);

                // 取最长匹配，保证 `admin.v1.*` 优先于 `admin.*`
                if (str_starts_with($name, $prefix) && strlen($prefix) > $matchedLength) {
                    $matched = $rule;
                    $matchedLength = strlen($prefix);
                }
            }

            if ($matched !== null) {
                return $matched;
            }
        }

        return (array) $this->config->get('ratelimit.default', ['max_attempts' => 60, 'decay_seconds' => 60]);
    }

    /**
     * 构造限流键。
     *
     * @param Request $request 当前请求
     * @param array<string, mixed> $rule 规则（by: ip / route / global）
     * @return string 限流键
     */
    private function resolveKey(Request $request, array $rule): string
    {
        $route = (string) $request->attribute('route.name', '')
            ?: $request->method . ':' . $request->path;

        return match ((string) ($rule['by'] ?? $this->config->get('ratelimit.by', 'ip'))) {
            'route' => $route,
            'global' => 'global',
            default => $request->ip() . '|' . $route,
        };
    }
}
