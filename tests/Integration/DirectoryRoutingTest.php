<?php

declare(strict_types=1);

namespace Tests\Integration;

use SunsetReverie\Foundation\Application;
use SunsetReverie\Routing\Exception\RouteRegistrationException;
use Tests\TestCase;

/**
 * directory 模式的路由加载与结构校验。
 *
 * 这组测试守的是「二选一」这条约束：
 * single 与 directory 不能混用，配错了**启动就报错**，
 * 而不是静默少加载几个文件让请求 404。
 */
final class DirectoryRoutingTest extends TestCase
{
    /** @var string 临时项目根 */
    private string $project;

    /**
     * 建一个最小项目（只有 config 与 routes）。
     *
     * @return void
     */
    protected function setUp(): void
    {
        Application::reset();

        $this->project = sys_get_temp_dir() . '/sr-dirroute-' . uniqid('', true);

        foreach (['config', 'routes', 'storage/logs', 'storage/cache', 'storage/compiled'] as $dir) {
            mkdir($this->project . '/' . $dir, 0755, true);
        }

        file_put_contents($this->project . '/.env', "APP_ENV=local\nAPP_DEBUG=true\nAPP_KEY=base64:test\n");
    }

    /**
     * 清理临时项目。
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Application::reset();
        $this->remove($this->project);
    }

    /**
     * directory 模式：根目录文件无版本前缀，版本目录自动加。
     *
     * @return void
     */
    public function testDirectoryModeLoadsBothLevels(): void
    {
        $this->writeApiConfig('directory', ['v1', 'v2']);

        mkdir($this->project . '/routes/admin/v1', 0755, true);
        mkdir($this->project . '/routes/admin/v2', 0755, true);

        $this->writeRouteFile('admin/health.php', 'ping');
        $this->writeRouteFile('admin/v1/posts.php', 'posts');
        $this->writeRouteFile('admin/v2/posts.php', 'posts');

        $app = Application::boot($this->project);

        $uris = $this->uris($app);
        $names = $this->names($app);

        $this->assertTrue(in_array('/admin/ping', $uris, true), '根目录文件应挂在 /admin 下');
        $this->assertTrue(in_array('/admin/v1/posts', $uris, true), 'v1 目录应自动加 /v1');
        $this->assertTrue(in_array('/admin/v2/posts', $uris, true), 'v2 目录应自动加 /v2');

        $this->assertTrue(in_array('admin.ping', $names, true), '根目录文件的路由名不带版本段');
        $this->assertTrue(in_array('admin.v1.posts', $names, true), 'v1 的路由名带 v1.');
        $this->assertTrue(in_array('admin.v2.posts', $names, true), 'v2 的路由名带 v2.');
    }

    /**
     * directory 模式下，子文件里不允许再设 prefix。
     *
     * @return void
     */
    public function testDirectoryModeRejectsPrefixInRouteFile(): void
    {
        $this->writeApiConfig('directory', ['v1']);
        mkdir($this->project . '/routes/admin/v1', 0755, true);

        file_put_contents($this->project . '/routes/admin/v1/bad.php', <<<'PHP'
<?php

declare(strict_types=1);

use SunsetReverie\Support\Facades\Route;

Route::group(['prefix' => 'v1'], static function (): void {
    Route::get('/x', static fn (): array => []);
});
PHP
        );

        $e = $this->assertThrows(
            RouteRegistrationException::class,
            fn () => Application::boot($this->project)
        );

        $this->assertStringContains('不允许在路由文件里设置 prefix', $e->getMessage());
    }

    /**
     * directory 模式下，子文件里允许用 as 给路由名分类。
     *
     * @return void
     */
    public function testDirectoryModeAllowsAsWithoutPrefix(): void
    {
        $this->writeApiConfig('directory', []);
        mkdir($this->project . '/routes/admin', 0755, true);

        file_put_contents($this->project . '/routes/admin/legacy.php', <<<'PHP'
<?php

declare(strict_types=1);

use SunsetReverie\Support\Facades\Route;

// 只设 as：URL 不变（/admin/old），名字带 legacy.
Route::group(['as' => 'legacy.'], static function (): void {
    Route::get('/old', static fn (): array => [], 'posts');
});
PHP
        );

        $app = Application::boot($this->project);

        $this->assertTrue(in_array('/admin/old', $this->uris($app), true), 'as 不应改变 URL');
        $this->assertTrue(
            in_array('admin.legacy.posts', $this->names($app), true),
            'as 应加到路由名上'
        );
    }

    /**
     * single 模式下存在同名目录 → 启动报错。
     *
     * @return void
     */
    public function testSingleModeRejectsDirectory(): void
    {
        $this->writeApiConfig('single', []);
        $this->writeSingleFile('admin');
        mkdir($this->project . '/routes/admin', 0755, true);

        $e = $this->assertThrows(
            RouteRegistrationException::class,
            fn () => Application::boot($this->project)
        );

        $this->assertStringContains('routing_mode=single', $e->getMessage());
        $this->assertStringContains('存在目录 routes/admin/', $e->getMessage());
    }

    /**
     * directory 模式下存在单文件 → 启动报错。
     *
     * @return void
     */
    public function testDirectoryModeRejectsSingleFile(): void
    {
        $this->writeApiConfig('directory', []);
        $this->writeSingleFile('admin');
        mkdir($this->project . '/routes/admin', 0755, true);

        $e = $this->assertThrows(
            RouteRegistrationException::class,
            fn () => Application::boot($this->project)
        );

        $this->assertStringContains('routing_mode=directory', $e->getMessage());
        $this->assertStringContains('存在单文件 routes/admin.php', $e->getMessage());
    }

    /**
     * directory 模式但目录不存在 → 启动报错。
     *
     * @return void
     */
    public function testDirectoryModeRequiresDirectory(): void
    {
        $this->writeApiConfig('directory', []);

        $e = $this->assertThrows(
            RouteRegistrationException::class,
            fn () => Application::boot($this->project)
        );

        $this->assertStringContains('缺少目录 routes/admin/', $e->getMessage());
    }

    /**
     * 出现未在 versions 里声明的版本目录 → 启动报错。
     *
     * @return void
     */
    public function testUndeclaredVersionDirectoryIsRejected(): void
    {
        $this->writeApiConfig('directory', ['v1']);
        mkdir($this->project . '/routes/admin/v1', 0755, true);
        mkdir($this->project . '/routes/admin/v3', 0755, true);

        $this->writeRouteFile('admin/v1/posts.php', 'posts');
        $this->writeRouteFile('admin/v3/posts.php', 'posts');

        $e = $this->assertThrows(
            RouteRegistrationException::class,
            fn () => Application::boot($this->project)
        );

        $this->assertStringContains('routes/admin/v3/', $e->getMessage());
        $this->assertStringContains('不是已声明的版本', $e->getMessage());
    }

    /**
     * 非法的 routing_mode 值 → 启动报错。
     *
     * @return void
     */
    public function testInvalidRoutingModeIsRejected(): void
    {
        $this->writeApiConfig('nested', []);
        $this->writeSingleFile('admin');

        $e = $this->assertThrows(
            RouteRegistrationException::class,
            fn () => Application::boot($this->project)
        );

        $this->assertStringContains('只能是 single 或 directory', $e->getMessage());
    }

    /**
     * 未命名路由的 name 应为 null，而不是退化成组前缀。
     *
     * 旧实现会让同一组里所有未命名路由都叫 'admin'，
     * 而路由名是限流配置与访问日志的 key，重名会命中错误规则。
     *
     * @return void
     */
    public function testUnnamedRouteHasNullName(): void
    {
        $this->writeApiConfig('single', []);

        file_put_contents($this->project . '/routes/admin.php', <<<'PHP'
<?php

declare(strict_types=1);

use SunsetReverie\Support\Facades\Route;

return function (): void {
    Route::get('/a', static fn (): array => []);
    Route::get('/b', static fn (): array => []);
};
PHP
        );

        $app = Application::boot($this->project);

        foreach ($app->router()->routes() as $route) {
            if (in_array($route->uri, ['/admin/a', '/admin/b'], true)) {
                $this->assertSame(null, $route->name, '未命名路由的 name 应为 null，实际：' . var_export($route->name, true));
            }
        }
    }

    /**
     * 写 config/api.php。
     *
     * @param string $mode 路由模式
     * @param list<string> $versions 版本列表
     * @return void
     */
    private function writeApiConfig(string $mode, array $versions): void
    {
        $versionList = $versions === []
            ? '[]'
            : "['" . implode("', '", $versions) . "']";

        file_put_contents($this->project . '/config/api.php', sprintf(
            "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n"
            . "    'default' => 'admin',\n"
            . "    'allow_header_detection' => false,\n"
            . "    'routing_mode' => '%s',\n"
            . "    'domains' => [\n"
            . "        'admin' => [\n"
            . "            'prefix' => 'admin',\n"
            . "            'versions' => %s,\n"
            . "        ],\n"
            . "    ],\n];\n",
            $mode,
            $versionList
        ));
    }

    /**
     * 写一个目录模式下的路由文件（直接写路由，不 return 闭包）。
     *
     * @param string $relative 相对 routes/ 的路径
     * @param string $name 路由名
     * @return void
     */
    private function writeRouteFile(string $relative, string $name): void
    {
        $path = $this->project . '/routes/' . $relative;

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, sprintf(
            "<?php\n\ndeclare(strict_types=1);\n\n"
            . "use SunsetReverie\\Support\\Facades\\Route;\n\n"
            . "Route::get('/%s', static fn (): array => [], '%s');\n",
            $name,
            $name
        ));
    }

    /**
     * 写单文件模式的路由文件。
     *
     * @param string $domain 域名
     * @return void
     */
    private function writeSingleFile(string $domain): void
    {
        file_put_contents($this->project . '/routes/' . $domain . '.php', <<<'PHP'
<?php

declare(strict_types=1);

return function (): void {
};
PHP
        );
    }

    /**
     * 所有路由 URI。
     *
     * @param Application $app 应用
     * @return list<string> URI 列表
     */
    private function uris(Application $app): array
    {
        return array_map(static fn ($route): string => $route->uri, $app->router()->routes());
    }

    /**
     * 所有路由名。
     *
     * @param Application $app 应用
     * @return list<string|null> 名字列表
     */
    private function names(Application $app): array
    {
        return array_map(static fn ($route): ?string => $route->name, $app->router()->routes());
    }

    /**
     * 递归删除目录。
     *
     * @param string $directory 目录
     * @return void
     */
    private function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;

            is_dir($path) ? $this->remove($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
