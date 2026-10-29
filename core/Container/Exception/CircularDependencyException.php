<?php

declare(strict_types=1);

namespace SunsetReverie\Container\Exception;

/**
 * 循环依赖异常：携带完整解析链，便于定位。
 */
final class CircularDependencyException extends ContainerException
{
    /**
     * @param list<string> $chain 解析链（按进入顺序）
     */
    public function __construct(public readonly array $chain)
    {
        parent::__construct(
            '检测到循环依赖：' . implode(' → ', $chain)
        );
    }
}
