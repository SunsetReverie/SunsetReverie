<?php

declare(strict_types=1);

namespace Tests\Feature;

use SunsetReverie\Foundation\Application;
use Tests\TestCase;

/**
 * 运维命令测试（验证副作用，不校验终端输出）。
 *
 * 说明：命令输出直接写 STDOUT，无法被 output buffer 捕获，
 * 因此这里只验证"命令做了什么"，而非"打印了什么"。
 */
final class ConsoleTest extends TestCase
{
    /** @var string 临时项目目录 */
    private string $project;

    /**
     * 准备一个最小项目目录。
     *
     * @return void
     */
    protected function setUp(): void
    {
        Application::reset();
        $this->clearEnv();

        $this->project = sys_get_temp_dir() . '/sr-console-' . uniqid('', true);
        mkdir($this->project . '/storage/logs', 0755, true);
        mkdir($this->project . '/storage/cache', 0755, true);
        mkdir($this->project . '/storage/compiled', 0755, true);
        mkdir($this->project . '/storage/uploads', 0755, true);

        file_put_contents(
            $this->project . '/.env.example',
            "APP_NAME=Test\nAPP_ENV=local\nAPP_DEBUG=false\nAPP_KEY=\n"
        );
    }

    /**
     * 清理临时目录。
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->clearEnv();

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->project, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }

        @rmdir($this->project);
    }

    /**
     * 清理测试涉及的环境变量。
     *
     * EnvLoader 遵循"真实环境变量优先"原则，不会覆盖已存在的值，
     * 因此同一进程中多次 boot 不同项目时必须手动清理。
     *
     * @return void
     */
    private function clearEnv(): void
    {
        foreach (['APP_ENV', 'APP_DEBUG', 'APP_KEY', 'JWT_SECRET'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    /**
     * key:generate 应生成 base64 密钥并写入 .env。
     *
     * @return void
     */
    public function testKeyGenerateWritesKey(): void
    {
        $app = Application::boot($this->project);

        $this->assertSame(0, $app->runConsole(['sr', 'key:generate']));

        $content = (string) file_get_contents($this->project . '/.env');

        $this->assertSame(1, preg_match('/^APP_KEY=base64:[A-Za-z0-9+\/=]{44}$/m', $content), '.env 应含合法 APP_KEY');
        $this->assertStringContains('APP_NAME=Test', $content, '其余变量应保留');
    }

    /**
     * key:generate --show 不应写文件。
     *
     * @return void
     */
    public function testKeyGenerateShowDoesNotWrite(): void
    {
        $app = Application::boot($this->project);

        $this->assertSame(0, $app->runConsole(['sr', 'key:generate', '--show']));
        $this->assertFalse(is_file($this->project . '/.env'));
    }

    /**
     * 缺少 .env 时 env:check 应失败。
     *
     * @return void
     */
    public function testEnvCheckFailsWithoutEnvFile(): void
    {
        $app = Application::boot($this->project);

        $this->assertSame(1, $app->runConsole(['sr', 'env:check']));
    }

    /**
     * 配置完整时 env:check 应通过。
     *
     * @return void
     */
    public function testEnvCheckPassesWithValidEnv(): void
    {
        file_put_contents(
            $this->project . '/.env',
            "APP_ENV=local\nAPP_DEBUG=false\nAPP_KEY=base64:" . base64_encode(str_repeat('k', 32)) . "\n"
        );

        $app = Application::boot($this->project);

        $this->assertSame(0, $app->runConsole(['sr', 'env:check']));
    }

    /**
     * 生产环境开启 debug 应被拦下。
     *
     * @return void
     */
    public function testEnvCheckRejectsDebugInProduction(): void
    {
        file_put_contents(
            $this->project . '/.env',
            "APP_ENV=production\nAPP_DEBUG=true\nAPP_KEY=base64:" . base64_encode(str_repeat('k', 32)) . "\n"
        );

        $app = Application::boot($this->project);

        $this->assertSame(1, $app->runConsole(['sr', 'env:check']));
    }

    /**
     * optimize 应生成路由与配置缓存，optimize:clear 应删除。
     *
     * @return void
     */
    public function testOptimizeAndClear(): void
    {
        $app = Application::boot($this->project);

        $this->assertSame(0, $app->runConsole(['sr', 'optimize']));

        $routes = $this->project . '/storage/compiled/routes.php';
        $config = $this->project . '/storage/compiled/config.php';

        $this->assertTrue(is_file($routes), '应生成路由缓存');
        $this->assertTrue(is_file($config), '应生成配置缓存');
        $this->assertTrue(is_array(require $routes));

        $this->assertSame(0, $app->runConsole(['sr', 'optimize:clear']));

        $this->assertFalse(is_file($routes));
        $this->assertFalse(is_file($config));
    }

    /**
     * 未知命令应返回 2。
     *
     * @return void
     */
    public function testUnknownCommandReturnsUsageError(): void
    {
        $app = Application::boot($this->project);

        $this->assertSame(2, $app->runConsole(['sr', 'no-such-command']));
    }
}
