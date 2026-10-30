<?php

declare(strict_types=1);

namespace Tests\Unit;

use SunsetReverie\Http\Request;
use Tests\TestCase;

/**
 * 请求体读取：表单 / JSON / 合并。
 */
final class RequestBodyTest extends TestCase
{
    /**
     * JSON 请求应能通过 post() 读到字段。
     *
     * @return void
     */
    public function testPostReadsJsonBody(): void
    {
        $request = Request::create('POST', '/login', [], '{"email":"a@b.com","password":"x"}', [
            'Content-Type' => 'application/json',
        ]);

        $this->assertSame('a@b.com', $request->post('email'));
        $this->assertSame(['email' => 'a@b.com', 'password' => 'x'], $request->post());
    }

    /**
     * 表单请求应能通过 post() 读到字段。
     *
     * @return void
     */
    public function testPostReadsFormBody(): void
    {
        $request = Request::create('POST', '/login', [], 'email=a%40b.com&password=x', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);

        $this->assertSame('a@b.com', $request->post('email'));
    }

    /**
     * 缺少字段时返回默认值。
     *
     * @return void
     */
    public function testPostReturnsDefault(): void
    {
        $request = Request::create('POST', '/x', [], '{}', ['Content-Type' => 'application/json']);

        $this->assertSame('fallback', $request->post('missing', 'fallback'));
    }

    /**
     * all() 合并查询参数与请求体，请求体优先。
     *
     * @return void
     */
    public function testAllMergesQueryAndBody(): void
    {
        $request = Request::create(
            'POST',
            '/x',
            ['page' => '2', 'title' => 'from-query'],
            '{"title":"from-body"}',
            ['Content-Type' => 'application/json']
        );

        $all = $request->all();

        $this->assertSame('2', $all['page']);
        $this->assertSame('from-body', $all['title'], '请求体应覆盖同名查询参数');
    }

    /**
     * input() 同样应读 JSON（此前只读表单，前后端分离场景失效）。
     *
     * @return void
     */
    public function testInputReadsJsonBody(): void
    {
        $request = Request::create('POST', '/x', [], '{"name":"夕梦"}', [
            'Content-Type' => 'application/json',
        ]);

        $this->assertSame('夕梦', $request->input('name'));
    }
}
