<?php

declare(strict_types=1);

namespace SunsetReverie\Database\Migration\Exception;

use RuntimeException;

/**
 * 迁移异常：文件非法、执行失败、目录不可写。
 */
final class MigrationException extends RuntimeException
{
}
