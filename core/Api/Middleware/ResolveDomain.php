<?php

declare(strict_types=1);

namespace SunsetReverie\Api\Middleware;

use Closure;
use SunsetReverie\Api\DomainResolver;
use SunsetReverie\Http\Middleware\MiddlewareInterface;
use SunsetReverie\Http\Request;
use SunsetReverie\Http\Response;

/**
 * 域解析中间件：把当前请求归属的 API 域写入请求属性。
 *
 * 位置要求：必须早于日志与认证中间件（日志要记域、守卫要按域取）。
 */
final class ResolveDomain implements MiddlewareInterface
{
    /** 请求属性：域名 */
    public const ATTRIBUTE = 'api.domain';

    /** 请求属性：域前缀 */
    public const PREFIX_ATTRIBUTE = 'api.prefix';

    /**
     * @param DomainResolver $resolver 域解析器
     */
    public function __construct(private readonly DomainResolver $resolver)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function handle(Request $request, Closure $next): Response
    {
        $domain = $this->resolver->resolve($request);

        return $next($request
            ->withAttribute(self::ATTRIBUTE, $domain->name)
            ->withAttribute(self::PREFIX_ATTRIBUTE, $domain->prefix));
    }
}
