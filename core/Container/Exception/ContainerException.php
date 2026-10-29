<?php

declare(strict_types=1);

namespace SunsetReverie\Container\Exception;

use RuntimeException;

/**
 * 容器解析失败异常（含无法实例化、标量参数未提供等）。
 */
class ContainerException extends RuntimeException
{
}
