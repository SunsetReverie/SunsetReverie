<?php

declare(strict_types=1);

namespace SunsetReverie\Upload\Exception;

use SunsetReverie\Exception\ErrorCode;
use SunsetReverie\Exception\HttpException;

/**
 * 上传失败异常（400 / 413 / 415，可按场景指定）。
 *
 * 消息面向用户，**不得**包含服务器路径或内部实现细节。
 */
final class UploadException extends HttpException
{
    /**
     * @param string $message 面向用户的消息
     * @param int $status HTTP 状态码
     * @param ErrorCode $code 业务错误码
     */
    public function __construct(string $message, int $status = 500, ErrorCode $code = ErrorCode::UploadFailed)
    {
        parent::__construct($status, $code->value, $message);
    }

    /**
     * 文件过大（413）。
     *
     * @param int $maxSize 允许的最大字节数
     * @return self 异常实例
     */
    public static function tooLarge(int $maxSize): self
    {
        return new self(
            sprintf('文件超过允许的大小（最大 %s）', self::humanSize($maxSize)),
            413,
            ErrorCode::PayloadTooLarge
        );
    }

    /**
     * 类型不被允许（415）。
     *
     * @param string $mime 检测到的 MIME
     * @return self 异常实例
     */
    public static function invalidType(string $mime): self
    {
        return new self(sprintf('不支持的文件类型：%s', $mime), 415, ErrorCode::UnsupportedMediaType);
    }

    /**
     * 字节数转可读文本。
     *
     * @param int $bytes 字节数
     * @return string 可读大小
     */
    private static function humanSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / 1024 / 1024, 1) . ' MB';
        }

        return round($bytes / 1024, 1) . ' KB';
    }
}
