<?php

declare(strict_types=1);

namespace SunsetReverie\Log;

use Stringable;
use SunsetReverie\Log\Handler\HandlerInterface;

/**
 * 日志记录器。
 *
 * 设计要点：
 * - 方法集对齐 PSR-3（debug/info/...），可直接作为 PSR-3 实现使用；
 * - **上下文栈**：请求开始时由中间件 push 请求信息，业务代码无需每处传 request_id；
 * - **自动脱敏**：密码、令牌等键的值统一替换为 `***`，避免敏感信息落盘。
 */
final class Logger
{
    /** 脱敏的键名（小写） */
    private const SENSITIVE_KEYS = [
        'password', 'password_confirmation', 'old_password', 'new_password',
        'token', 'access_token', 'refresh_token', 'secret', 'api_key',
        'authorization', 'cookie', 'set-cookie',
    ];

    /** @var list<array<string, mixed>> 上下文栈（后进先出） */
    private array $contextStack = [];

    /**
     * @param list<HandlerInterface> $handlers 处理器
     * @param string $channel 通道名
     */
    public function __construct(
        private readonly array $handlers,
        private readonly string $channel = 'app',
    ) {
    }

    /**
     * 压入上下文（请求开始）。
     *
     * @param array<string, mixed> $context 上下文
     * @return void
     */
    public function pushContext(array $context): void
    {
        $this->contextStack[] = $context;
    }

    /**
     * 弹出最近一层上下文（请求结束）。
     *
     * @return void
     */
    public function popContext(): void
    {
        array_pop($this->contextStack);
    }

    /**
     * 记录 debug 日志。
     *
     * @param string|Stringable $message 消息
     * @param array<string, mixed> $context 上下文
     * @return void
     */
    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->log(Level::Debug, $message, $context);
    }

    /**
     * 记录 info 日志。
     *
     * @param string|Stringable $message 消息
     * @param array<string, mixed> $context 上下文
     * @return void
     */
    public function info(string|Stringable $message, array $context = []): void
    {
        $this->log(Level::Info, $message, $context);
    }

    /**
     * 记录 notice 日志。
     *
     * @param string|Stringable $message 消息
     * @param array<string, mixed> $context 上下文
     * @return void
     */
    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->log(Level::Notice, $message, $context);
    }

    /**
     * 记录 warning 日志。
     *
     * @param string|Stringable $message 消息
     * @param array<string, mixed> $context 上下文
     * @return void
     */
    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->log(Level::Warning, $message, $context);
    }

    /**
     * 记录 error 日志。
     *
     * @param string|Stringable $message 消息
     * @param array<string, mixed> $context 上下文
     * @return void
     */
    public function error(string|Stringable $message, array $context = []): void
    {
        $this->log(Level::Error, $message, $context);
    }

    /**
     * 记录 critical 日志。
     *
     * @param string|Stringable $message 消息
     * @param array<string, mixed> $context 上下文
     * @return void
     */
    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->log(Level::Critical, $message, $context);
    }

    /**
     * 记录 alert 日志。
     *
     * @param string|Stringable $message 消息
     * @param array<string, mixed> $context 上下文
     * @return void
     */
    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->log(Level::Alert, $message, $context);
    }

    /**
     * 记录 emergency 日志。
     *
     * @param string|Stringable $message 消息
     * @param array<string, mixed> $context 上下文
     * @return void
     */
    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->log(Level::Emergency, $message, $context);
    }

    /**
     * 记录任意级别日志。
     *
     * @param Level $level 级别
     * @param string|Stringable $message 消息
     * @param array<string, mixed> $context 上下文
     * @return void
     */
    public function log(Level $level, string|Stringable $message, array $context = []): void
    {
        $merged = $this->mergeContext($context);

        foreach ($this->handlers as $handler) {
            if ($handler->isHandling($level)) {
                $handler->handle($level, (string) $message, $merged);
            }
        }
    }

    /**
     * 获取通道名。
     *
     * @return string 通道名
     */
    public function channel(): string
    {
        return $this->channel;
    }

    /**
     * 合并上下文栈并脱敏。
     *
     * @param array<string, mixed> $context 本次调用的上下文
     * @return array<string, mixed> 合并后的上下文
     */
    private function mergeContext(array $context): array
    {
        $merged = [];

        foreach ($this->contextStack as $frame) {
            $merged = array_merge($merged, $frame);
        }

        return $this->sanitize(array_merge($merged, $context));
    }

    /**
     * 递归脱敏敏感键。
     *
     * @param array<string, mixed> $context 上下文
     * @return array<string, mixed> 脱敏后的上下文
     */
    private function sanitize(array $context): array
    {
        foreach ($context as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)) {
                $context[$key] = '***';

                continue;
            }

            if (is_array($value)) {
                $context[$key] = $this->sanitize($value);
            }
        }

        return $context;
    }
}
