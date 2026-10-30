<?php

declare(strict_types=1);

namespace Tests\Feature;

use Closure;
use SunsetReverie\Foundation\Application;
use SunsetReverie\Http\Middleware\MiddlewareInterface;
use SunsetReverie\Http\Request;
use SunsetReverie\Http\Response;
use SunsetReverie\Validation\FormRequest;
use Tests\TestCase;

/**
 * 文档 API 补齐后的回归测试。
 *
 * 覆盖 4 项此前「文档写了、代码没有」的能力：
 * FormRequest 自动校验、Builder 分页与 findOrFail、terminate、中间件别名端到端。
 */
final class ApiSurfaceTest extends TestCase
{
    /** @var string 临时项目根 */
    private string $project;

    /**
     * 准备最小项目（sqlite 内存库）。
     *
     * @return void
     */
    protected function setUp(): void
    {
        Application::reset();
        $this->clearEnv();

        $this->project = sys_get_temp_dir() . '/sr-surface-' . uniqid('', true);

        foreach (['config', 'routes', 'storage/logs', 'storage/cache', 'storage/compiled'] as $directory) {
            mkdir($this->project . '/' . $directory, 0755, true);
        }

        file_put_contents($this->project . '/.env', implode("\n", [
            'APP_ENV=staging',
            'APP_DEBUG=false',
            'APP_KEY=base64:' . base64_encode(str_repeat('k', 32)),
            '',
        ]));

        file_put_contents($this->project . '/config/database.php', <<<'PHP'
<?php

return [
    'default' => 'sqlite',
    'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']],
];
PHP);

        file_put_contents($this->project . '/config/log.php', <<<'PHP'
<?php

return [
    'default' => 'null',
    'access_log' => false,
    'channels' => ['null' => ['driver' => 'null']],
];
PHP);

        file_put_contents($this->project . '/config/api.php', <<<'PHP'
<?php

return [
    'default' => 'web',
    'middleware_aliases' => ['marker' => \Tests\Feature\MarkerMiddleware::class],
];
PHP);

        file_put_contents($this->project . '/config/cache.php', <<<'PHP'
<?php

return [
    'default' => 'array',
    'prefix' => 'surface:' . uniqid('', true) . ':',
    'stores' => ['array' => ['driver' => 'array']],
];
PHP);
    }

    /**
     * 清理。
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->clearEnv();
        Application::reset();
        $this->remove($this->project);
    }

    /**
     * FormRequest：合法请求自动校验，validated() 只返回规则字段。
     *
     * @return void
     */
    public function testFormRequestAutoValidation(): void
    {
        $app = Application::boot($this->project);

        $app->router()->post('/items', static fn (DemoRequest $request): array => $request->validated(), 'item.store');

        $response = $app->handleRequest(Request::create(
            'POST',
            '/items',
            [],
            '{"title":"标题","extra":"客户端多塞的字段"}',
            ['Content-Type' => 'application/json']
        ));

        $this->assertSame(200, $response->status());

        $data = (array) ((array) json_decode($response->body(), true))['data'];

        $this->assertSame('标题', $data['title']);
        $this->assertTrue(!array_key_exists('extra', $data), '未在规则中声明的字段不应出现在 validated() 里');
    }

    /**
     * FormRequest：缺字段时自动 422，控制器体不执行。
     *
     * @return void
     */
    public function testFormRequestRejectsInvalid(): void
    {
        $app = Application::boot($this->project);

        $executed = false;

        $app->router()->post('/items', static function (DemoRequest $request) use (&$executed): array {
            $executed = true;

            return [];
        }, 'item.store2');

        $response = $app->handleRequest(Request::create(
            'POST',
            '/items',
            [],
            '{}',
            ['Content-Type' => 'application/json']
        ));

        $this->assertSame(422, $response->status());
        $this->assertTrue($executed === false, '校验失败时控制器不应执行');
    }

    /**
     * FormRequest：authorize 返回 false 时 403。
     *
     * @return void
     */
    public function testFormRequestAuthorize(): void
    {
        $app = Application::boot($this->project);

        $app->router()->post('/denied', static fn (DeniedRequest $request): array => [], 'denied');

        $response = $app->handleRequest(Request::create(
            'POST',
            '/denied',
            [],
            '{"title":"x"}',
            ['Content-Type' => 'application/json']
        ));

        $this->assertSame(403, $response->status());
    }

    /**
     * Builder::paginate 返回完整分页元信息。
     *
     * @return void
     */
    public function testBuilderPaginate(): void
    {
        $app = Application::boot($this->project);
        $this->seed($app, 12);

        $page = $app->db()->table('items')->orderBy('id')->paginate(5, 2);

        $this->assertSame(12, $page['total']);
        $this->assertSame(2, $page['page']);
        $this->assertSame(5, $page['per_page']);
        $this->assertSame(3, $page['last_page']);
        $this->assertSame(5, count($page['items']));
        $this->assertSame(6, (int) $page['items'][0]['id'], '第 2 页首条应为第 6 条');
    }

    /**
     * Builder::findOrFail 存在返回记录，不存在 404。
     *
     * @return void
     */
    public function testBuilderFindOrFail(): void
    {
        $app = Application::boot($this->project);
        $this->seed($app, 3);

        $row = $app->db()->table('items')->findOrFail(2);
        $this->assertSame(2, (int) $row['id']);

        $this->assertThrows(
            \SunsetReverie\Exception\HttpException::class,
            static fn () => $app->db()->table('items')->findOrFail(999)
        );
    }

    /**
     * Builder::when 与 whereBetween。
     *
     * @return void
     */
    public function testBuilderWhenAndWhereBetween(): void
    {
        $app = Application::boot($this->project);
        $this->seed($app, 10);

        $all = $app->db()->table('items')->when(false, static fn ($q) => $q->where('id', 1))->get();
        $this->assertSame(10, count($all), 'when(false) 不应应用条件');

        $filtered = $app->db()->table('items')->when(true, static fn ($q) => $q->where('id', 1))->get();
        $this->assertSame(1, count($filtered));

        $between = $app->db()->table('items')->whereBetween('id', [3, 5])->get();
        $this->assertSame(3, count($between));
    }

    /**
     * terminate 应逆序执行回调，且回调异常不影响其他回调。
     *
     * @return void
     */
    public function testTerminateRunsCallbacksInReverse(): void
    {
        $app = Application::boot($this->project);
        $order = [];

        $app->terminating(static function () use (&$order): void {
            $order[] = 'first';
        });

        $app->terminating(static function (): void {
            throw new \RuntimeException('收尾异常不应外抛');
        });

        $app->terminating(static function () use (&$order): void {
            $order[] = 'last';
        });

        $app->terminate();

        $this->assertSame(['last', 'first'], $order, '应逆序执行，且异常被吞掉');
    }

    /**
     * environment 应读取 APP_ENV。
     *
     * @return void
     */
    public function testEnvironment(): void
    {
        $app = Application::boot($this->project);

        $this->assertSame('staging', $app->environment());
    }

    /**
     * 中间件别名的端到端效果：字符串别名可注册、可带参数。
     *
     * @return void
     */
    public function testMiddlewareAliasEndToEnd(): void
    {
        $app = Application::boot($this->project);

        $app->router()->get('/aliased', static fn (): array => ['ok' => true], 'aliased.demo')
            ->middleware(['marker:42']);

        $response = $app->handleRequest(Request::create('GET', '/aliased'));

        $this->assertSame(200, $response->status());
        $this->assertSame('42', $response->headers()['X-Marker'] ?? null, '别名参数应传给中间件');
    }

    /**
     * 造数据。
     *
     * @param Application $app 应用
     * @param int $count 条数
     * @return void
     */
    private function seed(Application $app, int $count): void
    {
        $app->db()->statement('CREATE TABLE items (id INTEGER PRIMARY KEY, name VARCHAR(20))');

        for ($index = 1; $index <= $count; $index++) {
            $app->db()->table('items')->insert(['id' => $index, 'name' => 'item-' . $index]);
        }
    }

    /**
     * 清空环境变量。
     *
     * @return void
     */
    private function clearEnv(): void
    {
        foreach (['APP_ENV', 'APP_DEBUG', 'APP_KEY'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    /**
     * 递归删除。
     *
     * @param string $directory 目录
     * @return void
     */
    private function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (glob($directory . '/*') ?: [] as $file) {
            is_dir($file) ? $this->remove($file) : @unlink($file);
        }

        @rmdir($directory);
    }
}

/**
 * 示例 FormRequest。
 */
final class DemoRequest extends FormRequest
{
    public function rules(): array
    {
        return ['title' => 'required|string|max:200'];
    }
}

/**
 * 拒绝所有请求的 FormRequest。
 */
final class DeniedRequest extends FormRequest
{
    public function rules(): array
    {
        return ['title' => 'required|string'];
    }

    public function authorize(Request $request): bool
    {
        return false;
    }
}

/**
 * 带参数的测试中间件（用于验证别名参数语法）。
 */
final class MarkerMiddleware implements MiddlewareInterface, \SunsetReverie\Http\Middleware\HasParameters
{
    private string $marker = '';

    public function withParameters(array $parameters): static
    {
        $clone = clone $this;
        $clone->marker = (string) ($parameters[0] ?? '');

        return $clone;
    }

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request)->withHeader('X-Marker', 'marker:' === '' ? '' : $this->marker);
    }

    /**
     * AuthManager::user()：无登录态时返回 null，不抛异常。
     *
     * @return void
     */
    public function testAuthUserWithoutLogin(): void
    {
        $app = Application::boot($this->project);

        $this->assertNull($app->auth()->user(), '无请求上下文应安全返回 null');
        $this->assertFalse($app->auth()->check());
        $this->assertNull($app->auth()->id());
    }
}
