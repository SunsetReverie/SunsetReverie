<?php

declare(strict_types=1);

namespace Tests\Unit;

use SunsetReverie\Exception\ErrorCode;
use SunsetReverie\Exception\HttpException;
use SunsetReverie\Http\Middleware\EnsureBodySize;
use SunsetReverie\Http\Request;
use SunsetReverie\Http\Response;
use Tests\TestCase;

/**
 * 安全加固：文档 11.6「输入输出安全清单」里此前缺失的三项。
 *
 * 这三项都是「不加也不会立刻出事」的类型，所以一直没被发现：
 * - 响应头 CRLF 注入：只有在 header 值来自用户输入时才可利用
 * - 分页上限：只有客户端故意传 per_page=100000 才暴露
 * - 请求体大小：只有直连 FPM（不经 Nginx）时才有风险
 */
final class SecurityHardeningTest extends TestCase
{
    /**
     * 响应头字段值里的换行必须被剥掉（防响应拆分）。
     *
     * @return void
     */
    public function testHeaderValueStripsCrlf(): void
    {
        $response = Response::json(['ok' => true])
            ->withHeader('X-User', "alice\r\nSet-Cookie: admin=1");

        $headers = $response->headers();

        $this->assertSame('aliceSet-Cookie: admin=1', $headers['X-User'], '换行应被移除');
        $this->assertFalse(str_contains($headers['X-User'], "\n"), '不能残留换行');
        $this->assertFalse(isset($headers['Set-Cookie']), '不能被注入新头');
    }

    /**
     * 响应头字段名里的非法字符也要清掉。
     *
     * @return void
     */
    public function testHeaderNameIsSanitized(): void
    {
        $response = Response::json(['ok' => true])->withHeader("X-Bad\r\nName:", 'v');

        $headers = $response->headers();

        $this->assertTrue(isset($headers['X-BadName']), '非法字符应从字段名移除，实际：' . json_encode(array_keys($headers)));
    }

    /**
     * perPage 应把客户端传的值夹到配置上限。
     *
     * @return void
     */
    public function testPerPageClampsToMax(): void
    {
        $request = Request::create('GET', '/', ['per_page' => '100000']);

        $this->assertSame(50, $request->perPage(20, 50), '超过上限应被夹住');

        // 用**另一个**没有 per_page 的请求：复用 $request 会拿到夹取后的 50
        $noParam = Request::create('GET', '/');
        $this->assertSame(20, $noParam->perPage(20, 50), '未指定时用默认值');

        $small = Request::create('GET', '/', ['per_page' => '5']);
        $this->assertSame(5, $small->perPage(20, 50));

        $zero = Request::create('GET', '/', ['per_page' => '0']);
        $this->assertSame(1, $zero->perPage(20, 50), '至少 1 条');
    }

    /**
     * page 应保证从 1 开始。
     *
     * @return void
     */
    public function testPageIsAtLeastOne(): void
    {
        $this->assertSame(1, Request::create('GET', '/', ['page' => '-5'])->page());
        $this->assertSame(3, Request::create('GET', '/', ['page' => '3'])->page());
    }

    /**
     * 请求体超限应返回 413。
     *
     * @return void
     */
    public function testBodySizeRejectsOversized(): void
    {
        $original = $_SERVER['CONTENT_LENGTH'] ?? null;
        $_SERVER['CONTENT_LENGTH'] = '10485760';   // 10MB

        try {
            $middleware = (new EnsureBodySize())->withParameters(['1048576']);   // 1MB

            $e = $this->assertThrows(
                HttpException::class,
                static fn () => $middleware->handle(Request::create('POST', '/'), static fn ($r) => Response::json([]))
            );

            $this->assertSame(413, $e->status);
            $this->assertSame(ErrorCode::PayloadTooLarge->value, $e->errorCode);
            $this->assertStringContains('请求体过大', $e->getMessage());
        } finally {
            if ($original === null) {
                unset($_SERVER['CONTENT_LENGTH']);
            } else {
                $_SERVER['CONTENT_LENGTH'] = $original;
            }
        }
    }

    /**
     * 未超限时应放行。
     *
     * @return void
     */
    public function testBodySizeAllowsNormalRequest(): void
    {
        $original = $_SERVER['CONTENT_LENGTH'] ?? null;
        $_SERVER['CONTENT_LENGTH'] = '1024';

        try {
            $middleware = (new EnsureBodySize())->withParameters(['1048576']);
            $response = $middleware->handle(
                Request::create('POST', '/'),
                static fn ($r) => Response::json(['passed' => true])
            );

            $this->assertSame(200, $response->status());
        } finally {
            if ($original === null) {
                unset($_SERVER['CONTENT_LENGTH']);
            } else {
                $_SERVER['CONTENT_LENGTH'] = $original;
            }
        }
    }
}
