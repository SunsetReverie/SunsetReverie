<?php

declare(strict_types=1);

namespace SunsetReverie\Database\Exception;

use RuntimeException;
use Throwable;

/**
 * 查询异常：携带 SQL 与绑定值，便于定位。
 *
 * 注意：本异常的 message 含 SQL 原文，**只能进日志**，不得直接返回给客户端。
 * 统一异常处理（ErrorHandler）会把它降级为通用 500 响应。
 */
final class QueryException extends RuntimeException
{
    /**
     * @param string $sql SQL 语句
     * @param array<int|string, mixed> $bindings 绑定值
     * @param Throwable $previous 原始 PDO 异常
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $bindings,
        Throwable $previous,
    ) {
        parent::__construct(
            sprintf('SQL 执行失败：%s（%s）', $previous->getMessage(), $sql),
            0,
            $previous
        );
    }
}
