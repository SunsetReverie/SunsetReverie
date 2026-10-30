<?php

declare(strict_types=1);

namespace SunsetReverie\Api\Exception;

use RuntimeException;

/**
 * 域配置异常：域未定义、默认域非法、前缀重复。
 *
 * 属于启动期错误，直接阻断引导，不做静默回退。
 */
final class DomainNotFoundException extends RuntimeException
{
}
