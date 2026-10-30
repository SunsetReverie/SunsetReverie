<?php

declare(strict_types=1);

namespace SunsetReverie\Log\Formatter;

use SunsetReverie\Log\Level;

/**
 * JSON Lines 格式化器（生产环境默认）。
 *
 * 每行一条 JSON，便于 `grep`、`jq` 与日志采集器（Filebeat / Loki）解析。
 *
 * 输出结构：
 * {"time":"2026-10-30T10:00:00+08:00","level":"info","channel":"app",
 *  "message":"...","context":{...},"request_id":"...","..."}
 *
 * 上下文里的标量键会被提升到顶层（如 request_id / route / ip），
 * 避免出现 `context.request_id` 这种需要二次解析的嵌套。
 */
final class JsonFormatter implements FormatterInterface
{
    /** 提升到顶层的上下文键 */
    private const PROMOTED_KEYS = ['request_id', 'route', 'ip', 'user_id', 'domain', 'duration_ms'];

    /**
     * {@inheritDoc}
     */
    public function format(Level $level, string $message, array $context, string $channel): string
    {
        $record = [
            'time' => date(DATE_ATOM),
            'level' => $level->value,
            'channel' => $channel,
            'message' => $message,
        ];

        $remaining = [];

        foreach ($context as $key => $value) {
            if (in_array($key, self::PROMOTED_KEYS, true) && !is_array($value)) {
                $record[$key] = $value;

                continue;
            }

            $remaining[$key] = $value;
        }

        if ($remaining !== []) {
            $record['context'] = $remaining;
        }

        $json = json_encode(
            $record,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        return $json === false ? '{"level":"error","message":"日志序列化失败"}' : $json;
    }
}
