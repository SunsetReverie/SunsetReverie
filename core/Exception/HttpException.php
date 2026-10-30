<?php

declare(strict_types=1);

namespace SunsetReverie\Exception;

use RuntimeException;

/**
 * 可安全暴露给客户端的异常：携带 HTTP 状态码与业务错误码。
 *
 * 与普通异常的区别：本异常的 message 会原样进入响应体，因此不得包含
 * 文件路径、SQL、内部类名等敏感信息。
 *
 * 注意：业务码使用 $errorCode 而非 $code，避免与 Exception 内置的 $code 冲突。
 */
class HttpException extends RuntimeException
{
    /**
     * @param int $status HTTP 状态码
     * @param int|null $errorCode 业务错误码；null 表示沿用 HTTP 状态
     * @param string|null $message 面向用户的消息
     * @param array<string, list<string>>|null $errors 字段级错误（校验失败场景）
     */
    public function __construct(
        public readonly int $status,
        public readonly ?int $errorCode = null,
        ?string $message = null,
        public readonly ?array $errors = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message ?? '请求处理失败', 0, $previous);
    }

    /**
     * 由错误码枚举构造异常。
     *
     * @param ErrorCode $code 内置错误码
     * @param string|null $message 覆盖默认消息
     * @param array<string, list<string>>|null $errors 字段级错误
     * @return self 异常实例
     */
    public static function fromCode(
        ErrorCode $code,
        ?string $message = null,
        ?array $errors = null,
    ): self {
        return new self(
            status: $code->httpStatus(),
            errorCode: $code->value,
            message: $message ?? $code->message(),
            errors: $errors,
        );
    }
}
