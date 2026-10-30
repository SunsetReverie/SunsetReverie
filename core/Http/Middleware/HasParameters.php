<?php

declare(strict_types=1);

namespace SunsetReverie\Http\Middleware;

/**
 * 支持从路由声明接收参数的中间件。
 *
 * 对应文档里的 `throttle:300,1` 写法——冒号后的内容按逗号切分，
 * 交给 withParameters() 自行解释。不实现本接口的中间件收到参数时会报错，
 * 而不是静默忽略（静默忽略最坑：写了 300 次/分钟却按默认值跑）。
 */
interface HasParameters
{
    /**
     * 注入参数。
     *
     * 实现方应返回新实例（保持不可变），便于同一路由多次声明不同参数。
     *
     * @param list<string> $parameters 冒号后按逗号切分的参数
     * @return static 新实例
     */
    public function withParameters(array $parameters): static;
}
