<?php

declare(strict_types=1);

namespace SunsetReverie\Cache\Exception;

use RuntimeException;

/**
 * 缓存异常：目录不可写、驱动缺失、序列化失败。
 */
final class CacheException extends RuntimeException
{
}
