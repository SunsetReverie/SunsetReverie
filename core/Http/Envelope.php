<?php

declare(strict_types=1);

namespace SunsetReverie\Http;

use SunsetReverie\Support\Str;

/**
 * 统一响应包络构造器。
 *
 * 固定字段：code / message / data / request_id / timestamp
 * 可选字段：errors（仅字段级错误时出现）
 */
final class Envelope
{
    /**
     * 构造成功包络。
     *
     * @param mixed $data 业务数据
     * @param string $message 提示消息
     * @param string|null $requestId 请求 ID
     * @return array<string, mixed> 包络数组
     */
    public static function success(mixed $data, string $message = 'ok', ?string $requestId = null): array
    {
        return [
            'code' => 0,
            'message' => $message,
            'data' => $data,
            'request_id' => $requestId ?? Str::uuid7(),
            'timestamp' => time(),
        ];
    }

    /**
     * 构造失败包络。
     *
     * @param int $code 业务错误码
     * @param string $message 提示消息
     * @param string|null $requestId 请求 ID
     * @param array<string, list<string>>|null $errors 字段级错误
     * @return array<string, mixed> 包络数组
     */
    public static function error(
        int $code,
        string $message,
        ?string $requestId = null,
        ?array $errors = null,
    ): array {
        $payload = [
            'code' => $code,
            'message' => $message,
            'data' => null,
            'request_id' => $requestId ?? Str::uuid7(),
            'timestamp' => time(),
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return $payload;
    }
}
