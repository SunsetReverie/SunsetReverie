<?php

declare(strict_types=1);

namespace SunsetReverie\Database\Exception;

use RuntimeException;

/**
 * 连接异常：配置缺失、驱动不支持、连接失败。
 */
final class ConnectionException extends RuntimeException
{
}
