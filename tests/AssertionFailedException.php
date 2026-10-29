<?php

declare(strict_types=1);

namespace Tests;

use RuntimeException;

/**
 * 断言失败异常：由极简测试基类抛出，测试运行器捕获后记为失败。
 */
final class AssertionFailedException extends RuntimeException
{
}
