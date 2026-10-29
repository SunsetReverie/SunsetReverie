<?php

declare(strict_types=1);

namespace SunsetReverie\Http\Middleware;

use Closure;
use SunsetReverie\Http\Request;
use SunsetReverie\Http\Response;

/**
 * 中间件契约。
 *
 * 实现方必须：
 * 1. 要么返回 $next($request) 的结果（继续管道），要么直接返回 Response（短路）；
 * 2. 不得修改 $request（请求对象只读），需要派生请创建新对象；
 * 3. 不得吞掉异常，除非明确声明为"可降级"能力。
 */
interface MiddlewareInterface
{
    /**
     * 处理请求。
     *
     * @param Request $request 当前请求
     * @param Closure(Request): Response $next 下一个处理器
     * @return Response 响应对象
     */
    public function handle(Request $request, Closure $next): Response;
}
