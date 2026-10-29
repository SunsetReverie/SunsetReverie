<?php

declare(strict_types=1);

namespace SunsetReverie\Exception;

use Throwable;

/**
 * 调试信息渲染器：仅在 debug 模式使用。
 *
 * 输出内容包含异常类、消息、位置与调用链摘要，**绝不可进入生产响应**。
 */
final class DebugRenderer
{
    /** 调用链保留的最大帧数 */
    private const MAX_FRAMES = 10;

    /**
     * 渲染异常为调试数组。
     *
     * @param Throwable $e 异常
     * @return array<string, mixed> 调试信息
     */
    public function render(Throwable $e): array
    {
        return [
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'location' => $e->getFile() . ':' . $e->getLine(),
            'trace' => $this->frames($e),
        ];
    }

    /**
     * 提取调用链摘要（去除框架内部噪音）。
     *
     * @param Throwable $e 异常
     * @return list<array{file: string, line: int, call: string}> 调用链
     */
    private function frames(Throwable $e): array
    {
        $frames = [];

        foreach (array_slice($e->getTrace(), 0, self::MAX_FRAMES) as $frame) {
            $frames[] = [
                'file' => $frame['file'] ?? '[internal]',
                'line' => (int) ($frame['line'] ?? 0),
                'call' => ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? ''),
            ];
        }

        return $frames;
    }
}
