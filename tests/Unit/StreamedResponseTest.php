<?php

declare(strict_types=1);

namespace Tests\Unit;

use SunsetReverie\Http\Response;
use Tests\TestCase;

/**
 * 流式响应测试。
 *
 * 为什么需要它在框架里：`file()` 与 `json()` 都把内容一次性放进内存，
 * 导出 10 万行 CSV 会让进程内存随数据量线性增长。
 * 流式响应的内存占用应当与数据量无关。
 */
final class StreamedResponseTest extends TestCase
{
    /**
     * stream() 构造的响应不应持有响应体。
     *
     * @return void
     */
    public function testStreamHasNoBody(): void
    {
        $response = Response::stream(static function (): void {
            echo 'ignored';
        }, ['Content-Type' => 'text/csv']);

        $this->assertTrue($response->isStreamed());
        $this->assertSame('', $response->body(), '流式响应不应把内容存进 body');
        $this->assertSame('text/csv', $response->headers()['Content-Type']);
    }

    /**
     * 普通响应不应被误判为流式。
     *
     * @return void
     */
    public function testNormalResponseIsNotStreamed(): void
    {
        $this->assertFalse(Response::json(['ok' => true])->isStreamed());
        $this->assertFalse(Response::text('hello')->isStreamed());
    }

    /**
     * send() 应执行回调（回调副作用可观察）。
     *
     * 注意：send() 内部会 flush 输出缓冲，所以这里用文件作为观察点，
     * 而不是 ob_start 捕获。
     *
     * @return void
     */
    public function testSendRunsProducer(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'sr-stream-');
        $this->assertTrue(is_string($file));

        $response = Response::stream(static function () use ($file): void {
            file_put_contents($file, 'streamed-content');
        });

        ob_start();
        $response->send();
        // send() 内部已 flush 掉缓冲，这里兜底清理剩余层级
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $this->assertSame('streamed-content', (string) file_get_contents($file));

        @unlink($file);
    }

    /**
     * 204 与 HEAD（sendBody=false）时不应执行回调。
     *
     * @return void
     */
    public function testSendWithoutBodySkipsProducer(): void
    {
        $called = false;

        $response = Response::stream(static function () use (&$called): void {
            $called = true;
        });

        ob_start();
        $response->send(false);
        // send() 内部已 flush 掉缓冲，这里兜底清理剩余层级
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $this->assertFalse($called, '不发送响应体时不应执行生成逻辑');
    }

    /**
     * 流式响应可自定义状态码（如 206）。
     *
     * @return void
     */
    public function testStreamAcceptsStatus(): void
    {
        $response = Response::stream(static function (): void {
        }, [], 206);

        $this->assertSame(206, $response->status());
    }
}
