<?php

declare(strict_types=1);

namespace Tests\Feature;

use SunsetReverie\Foundation\Application;
use SunsetReverie\Http\Request;
use SunsetReverie\Http\Response;
use SunsetReverie\Validation\Validator;
use Tests\TestCase;

/**
 * 控制器参数注入的测试用控制器。
 */
final class InjectionController
{
    /**
     * 展示注入结果（Request + 标量路由参数）。
     *
     * @param Request $request 当前请求
     * @param string $id 资源 ID
     * @param int $page 页码
     * @return array<string, mixed> 注入结果
     */
    public function show(Request $request, string $id, int $page): array
    {
        return [
            'id' => $id,
            'page' => $page,
            'page_type' => get_debug_type($page),
            'method' => $request->method,
        ];
    }

    /**
     * 创建资源（依赖注入 Validator）。
     *
     * @param Request $request 当前请求
     * @param Validator $validator 校验器
     * @return array<string, mixed> 校验后的数据
     */
    public function store(Request $request, Validator $validator): array
    {
        return $validator->validate($request->json(), [
            'title' => 'required|string|max:20',
        ]);
    }
}

/**
 * 端到端验证控制器参数注入。
 */
final class ControllerInjectionTest extends TestCase
{
    /**
     * 每个测试前重置应用并注册测试路由。
     *
     * @return void
     */
    protected function setUp(): void
    {
        Application::reset();
        $app = Application::boot(dirname(__DIR__, 2));

        $app->router()->get('/inject/{id}/{page}', [InjectionController::class, 'show']);
        $app->router()->post('/inject', [InjectionController::class, 'store']);
    }

    /**
     * Request 与标量路由参数应被正确注入，且标量按类型转换。
     *
     * @return void
     */
    public function testInjectsRequestAndScalarRouteParams(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));
        $response = $app->handleRequest(Request::create('GET', '/inject/42/3'));
        $payload = (array) json_decode($response->body(), true);

        $this->assertSame(200, $response->status());
        $this->assertSame('42', $payload['data']['id']);
        $this->assertSame(3, $payload['data']['page']);
        $this->assertSame('int', $payload['data']['page_type'], '路由参数应按声明类型转换');
        $this->assertSame('GET', $payload['data']['method']);
    }

    /**
     * 容器服务（Validator）应被自动注入。
     *
     * @return void
     */
    public function testInjectsContainerService(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));
        $request = Request::create('POST', '/inject', [], json_encode(['title' => 'Hello'], JSON_THROW_ON_ERROR));
        $response = $app->handleRequest($request);

        $this->assertSame(200, $response->status());
        $payload = (array) json_decode($response->body(), true);
        $this->assertSame('Hello', $payload['data']['title']);
    }

    /**
     * 校验失败应返回 422 与字段错误。
     *
     * @return void
     */
    public function testValidationFailureReturns422(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));
        $response = $app->handleRequest(Request::create('POST', '/inject', [], '{}'));

        $this->assertSame(422, $response->status());

        $payload = (array) json_decode($response->body(), true);
        $this->assertSame(30001, $payload['code']);
        $this->assertArrayHasKey('title', $payload['errors'] ?? []);
    }

    /**
     * 非法 JSON 应返回 400。
     *
     * @return void
     */
    public function testInvalidJsonReturns400(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));
        $response = $app->handleRequest(Request::create('POST', '/inject', [], '{not json'));

        $this->assertSame(400, $response->status());
    }
}
