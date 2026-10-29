<?php

declare(strict_types=1);

namespace SunsetReverie\Config\Exception;

use RuntimeException;

/**
 * 配置异常：类型不符、必填缺失、解析失败等。
 */
class ConfigException extends RuntimeException
{
}
