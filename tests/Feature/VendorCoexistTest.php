<?php

declare(strict_types=1);

namespace Tests\Feature;

use SunsetReverie\Foundation\Application;
use Tests\TestCase;

/**
 * 与 Composer 依赖共存（验收清单 A-2）。
 *
 * 框架零依赖是卖点，但业务项目几乎一定会装第三方库。
 * 如果框架的 autoload 把 vendor/autoload.php 挡住，
 * 用户会看到"明明 composer require 了却 Class not found"。
 */
final class VendorCoexistTest extends TestCase
{
    /** @var string 临时项目根 */
    private string $project;

    /**
     * 准备最小项目。
     *
     * @return void
     */
    protected function setUp(): void
    {
        Application::reset();
        $this->clearEnv();

        $this->project = sys_get_temp_dir() . '/sr-vendor-' . uniqid('', true);

        foreach (['config', 'routes', 'storage/logs', 'storage/cache', 'storage/compiled'] as $directory) {
            mkdir($this->project . '/' . $directory, 0755, true);
        }

        file_put_contents($this->project . '/.env', "APP_ENV=local\nAPP_DEBUG=false\nAPP_KEY=base64:" . base64_encode(str_repeat('k', 32)) . "\n");
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
     * 存在 vendor/autoload.php 时，第三方类应可直接使用。
     *
     * @return void
     */
    public function testVendorClassesAreLoadable(): void
    {
        $this->installFakeVendor();

        Application::boot($this->project);

        $this->assertTrue(
            class_exists('Acme\\Probe\\Marker'),
            '业务项目 vendor 里的类应可加载（框架不阻断 Composer）'
        );
        // Acme\Probe\Marker 由 installFakeVendor() 在运行时生成，静态分析看不到
        // @phpstan-ignore class.notFound
        $this->assertSame('ok', \Acme\Probe\Marker::value());
    }

    /**
     * 没有 vendor 目录时不应报错（框架零依赖场景）。
     *
     * @return void
     */
    public function testBootWithoutVendor(): void
    {
        $app = Application::boot($this->project);

        $this->assertSame(200, $app->handleRequest(\SunsetReverie\Http\Request::create('GET', '/health'))->status());
    }

    /**
     * 重复 boot 不应因 require_once 报错。
     *
     * @return void
     */
    public function testBootTwiceIsSafe(): void
    {
        $this->installFakeVendor();

        Application::boot($this->project);
        Application::reset();
        Application::boot($this->project);

        $this->assertTrue(class_exists('Acme\\Probe\\Marker'));
    }

    /**
     * 造一个假的 Composer 依赖。
     *
     * @return void
     */
    private function installFakeVendor(): void
    {
        $directory = $this->project . '/vendor/acme/probe/src';

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($directory . '/Marker.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Acme\Probe;

final class Marker
{
    public static function value(): string
    {
        return 'ok';
    }
}
PHP);

        file_put_contents($this->project . '/vendor/autoload.php', <<<'PHP'
<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Acme\\Probe\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/acme/probe/src/' . substr($class, strlen($prefix)) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
PHP);
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
