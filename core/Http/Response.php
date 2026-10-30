<?php

declare(strict_types=1);

namespace SunsetReverie\Http;

use Closure;

use JsonException;

/**
 * HTTP 响应对象。
 *
 * 设计要点：
 * - 默认 JSON 编码带 JSON_UNESCAPED_UNICODE，中文不转义；
 * - 编码失败必须抛异常（禁止静默输出空响应）；
 * - HEAD 请求由 Application 统一去除响应体，本类不感知请求方法。
 */
final class Response
{
    /** @var array<string, string> 响应头 */
    private array $headers = [];

    /**
     * 流式输出回调（null 表示普通响应）。
     *
     * 大文件/大结果集导出必须走这里：`file()` 与 `json()` 都会把内容
     * 一次性读进内存，10 万行 CSV 足够把进程打爆。
     */
    private ?Closure $stream = null;

    /**
     * @param string $body 响应体
     * @param int $status HTTP 状态码
     * @param array<string, string> $headers 响应头
     */
    public function __construct(
        private string $body = '',
        private int $status = 200,
        array $headers = [],
    ) {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
        }
    }

    /**
     * 构造 JSON 响应。
     *
     * @param mixed $data 待编码数据
     * @param int $status HTTP 状态码
     * @param array<string, string> $headers 额外响应头
     * @return self 响应实例
     * @throws JsonException 编码失败时抛出
     */
    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $body = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        return new self($body, $status, ['Content-Type' => 'application/json; charset=utf-8'] + $headers);
    }

    /**
     * 构造纯文本响应。
     *
     * @param string $text 文本内容
     * @param int $status HTTP 状态码
     * @return self 响应实例
     */
    public static function text(string $text, int $status = 200): self
    {
        return new self($text, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * 构造文件响应（用于私有文件下载/预览）。
     *
     * 说明：当前实现一次性读入内存，适用于受 `upload.max_size` 限制的文件；
     * 超大文件（>50MB）应改用流式输出。
     *
     * @param string $absolutePath 文件绝对路径
     * @param string $downloadName 展示文件名（为空则用路径 basename）
     * @param string $mime 强制 MIME（为空则按内容检测）
     * @return self 响应实例
     */
    public static function file(string $absolutePath, string $downloadName = '', string $mime = ''): self
    {
        $contents = @file_get_contents($absolutePath);

        if ($contents === false) {
            return self::text('文件读取失败', 500);
        }

        if ($mime === '') {
            $detected = @mime_content_type($absolutePath);
            $mime = is_string($detected) && $detected !== '' ? $detected : 'application/octet-stream';
        }

        $name = $downloadName !== '' ? $downloadName : basename($absolutePath);

        return new self($contents, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) strlen($contents),
            'Content-Disposition' => 'inline; filename="' . rawurlencode($name) . '"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * 流式响应：内容边生成边发送，内存占用与数据量无关。
     *
     * 用法（CSV 导出）：
     *
     *   return Response::stream(function (): void {
     *       $out = fopen('php://output', 'wb');
     *       fputcsv($out, ['id', 'title']);
     *
     *       foreach ($rows as $row) {          // $rows 建议用生成器分批查
     *           fputcsv($out, $row);
     *       }
     *
     *       fclose($out);
     *   }, ['Content-Type' => 'text/csv; charset=utf-8']);
     *
     * 注意：回调里**不要**再调用 `Response` 的构建方法——此时响应头已发送。
     *
     * @param Closure(): void $producer 输出回调
     * @param array<string, string> $headers 响应头
     * @param int $status HTTP 状态码
     * @return self 响应实例
     */
    public static function stream(Closure $producer, array $headers = [], int $status = 200): self
    {
        $response = new self('', $status, $headers);
        $response->stream = $producer;

        return $response;
    }

    /**
     * 是否为流式响应。
     *
     * @return bool 流式返回 true
     */
    public function isStreamed(): bool
    {
        return $this->stream !== null;
    }

    /**
     * 构造空响应（204 无响应体）。
     *
     * @return self 响应实例
     */
    public static function noContent(): self
    {
        return new self('', 204);
    }

    /**
     * 追加响应头（返回新实例，保持不可变语义）。
     *
     * @param string $name 头名称
     * @param string $value 头值
     * @return self 新响应实例
     */
    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    /**
     * 批量追加响应头（返回新实例）。
     *
     * @param array<string, string> $headers 头列表
     * @return self 新响应实例
     */
    public function withHeaders(array $headers): self
    {
        $clone = clone $this;

        foreach ($headers as $name => $value) {
            $clone->headers[$name] = $value;
        }

        return $clone;
    }

    /**
     * 获取状态码。
     *
     * @return int HTTP 状态码
     */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * 获取响应体。
     *
     * @return string 响应体
     */
    public function body(): string
    {
        return $this->body;
    }

    /**
     * 获取全部响应头。
     *
     * @return array<string, string> 头列表
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * 发送响应到客户端。
     *
     * @param bool $sendBody 是否输出响应体（HEAD 请求传 false）
     * @return void
     */
    public function send(bool $sendBody = true): void
    {
        if (!headers_sent()) {
            // 不向客户端暴露 PHP 版本（安全默认值）
            header_remove('X-Powered-By');
            http_response_code($this->status);

            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        if (!$sendBody || $this->status === 204) {
            return;
        }

        if ($this->stream !== null) {
            // 关掉输出缓冲：否则每一块内容都先堆在 PHP 缓冲里，等于没省内存
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            ($this->stream)();

            return;
        }

        echo $this->body;
    }
}
