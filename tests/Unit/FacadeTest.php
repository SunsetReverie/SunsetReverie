<?php

declare(strict_types=1);

namespace Tests\Unit;

use BadMethodCallException;
use RuntimeException;
use SunsetReverie\Foundation\Application;
use SunsetReverie\Support\Facades\Cache;
use SunsetReverie\Support\Facades\Config;
use SunsetReverie\Support\Facades\DB;
use SunsetReverie\Support\Facades\Log;
use SunsetReverie\Support\Facades\Route;
use Tests\TestCase;

/**
 * 静态门面测试。
 *
 * 验证"文档里写的 `Route::get()` / `DB::table()` 能真的跑起来"，
 * 以及未引导、方法不存在时的报错是否清晰。
 */
final class FacadeTest extends TestCase
{
    /** @var string 临时项目根 */
    private string $project;

    /**
     * 创建最小项目。
     *
     * @return void
     */
    protected function setUp(): void
    {
        Application::reset();
        $this->clearEnv();

        $this->project = sys_get_temp_dir() . '/sr-facade-' . uniqid('', true);

        foreach (['config', 'routes', 'storage/logs', 'storage/cache', 'storage/compiled'] as $directory) {
            mkdir($this->project . '/' . $directory, 0755, true);
        }

        file_put_contents($this->project . '/.env', implode("\n", [
            'APP_ENV=local',
            'APP_DEBUG=false',
            'APP_KEY=base64:' . base64_encode(str_repeat('k', 32)),
            '',
        ]));

        file_put_contents($this->project . '/config/app.php', "<?php\n\nreturn ['name' => 'Facade 测试项目'];\n");

        file_put_contents($this->project . '/config/database.php', <<<'PHP'
<?php

return [
    'default' => 'sqlite',
    'connections' => [
        'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
    ],
];
PHP);

        file_put_contents($this->project . '/config/cache.php', <<<'PHP'
<?php

return [
    'default' => 'array',
    'prefix' => 'facade:',
    'stores' => ['array' => ['driver' => 'array']],
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
     * 未引导时应给出明确提示，而不是"容器为 null"的模糊错误。
     *
     * @return void
     */
    public function testThrowsBeforeBoot(): void
    {
        Application::reset();

        $e = $this->assertThrows(RuntimeException::class, static fn () => Config::get('app.name'));

        $this->assertStringContains('Application::boot()', $e->getMessage());
    }

    /**
     * Route 门面应能注册路由。
     *
     * @return void
     */
    public function testRouteFacade(): void
    {
        $app = Application::boot($this->project);

        Route::get('/facade-demo', static fn (): array => ['ok' => true], 'facade.demo');

        $uris = array_map(static fn ($route): string => $route->uri, $app->router()->routes());
        $this->assertTrue(in_array('/facade-demo', $uris, true), 'Route::get 应真的注册进路由器');

        $response = $app->handleRequest(\SunsetReverie\Http\Request::create('GET', '/facade-demo'));
        $this->assertSame(200, $response->status());
    }

    /**
     * Route 门面注册的路由也能带中间件。
     *
     * @return void
     */
    public function testRouteFacadeSupportsMiddleware(): void
    {
        $app = Application::boot($this->project);

        Route::get('/secure', static fn (): array => ['ok' => true], 'facade.secure')
            ->middleware([\SunsetReverie\Auth\Middleware\Authenticate::class]);

        $response = $app->handleRequest(\SunsetReverie\Http\Request::create('GET', '/secure'));

        $this->assertSame(401, $response->status(), '未带 Token 应被拦下');
    }

    /**
     * DB 门面应能查询（依赖 Connection 已被容器绑定）。
     *
     * @return void
     */
    public function testDbFacade(): void
    {
        Application::boot($this->project);

        DB::statement('CREATE TABLE demo (id INTEGER PRIMARY KEY, name VARCHAR(20))');
        $id = DB::table('demo')->insertGetId(['name' => '夕梦']);

        $this->assertSame(1, $id);
        $this->assertSame('夕梦', DB::table('demo')->where('id', 1)->value('name'));
        $this->assertSame(1, DB::table('demo')->count());
    }

    /**
     * Cache 门面未定义的方法应转发到默认驱动。
     *
     * @return void
     */
    public function testCacheFacade(): void
    {
        Application::boot($this->project);

        Cache::put('user', '夕梦', 60);

        $this->assertSame('夕梦', Cache::get('user'));
        $this->assertTrue(Cache::has('user'));

        Cache::forget('user');
        $this->assertNull(Cache::get('user'));
    }

    /**
     * Log / Config 门面。
     *
     * @return void
     */
    public function testLogAndConfigFacade(): void
    {
        Application::boot($this->project);

        Log::info('门面测试', ['k' => 'v']);
        Log::channel('null')->debug('指定通道');

        $this->assertSame('Facade 测试项目', Config::get('app.name'));
        $this->assertSame('默认值', Config::get('app.missing', '默认值'));
    }

    /**
     * 调用实例上不存在的方法应抛出清晰异常（而不是致命错误）。
     *
     * @return void
     */
    public function testUnknownMethodThrows(): void
    {
        Application::boot($this->project);

        $e = $this->assertThrows(BadMethodCallException::class, static fn () => Route::noSuchMethod());

        $this->assertStringContains('不支持 noSuchMethod()', $e->getMessage());
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
