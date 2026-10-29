<?php

declare(strict_types=1);

namespace Tests\Unit;

use SunsetReverie\Config\Config;
use SunsetReverie\Config\Exception\ConfigException;
use Tests\TestCase;

/**
 * 配置仓库测试：点语法、合并、导出。
 */
final class ConfigTest extends TestCase
{
    /** @var string 临时配置目录 */
    private string $temporaryDirectory;

    /**
     * 创建临时配置目录。
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/sr-config-' . uniqid('', true);
        mkdir($this->temporaryDirectory . '/production', 0755, true);

        file_put_contents($this->temporaryDirectory . '/app.php', "<?php return [
            'name' => 'SunsetReverie',
            'debug' => false,
            'database' => ['host' => '127.0.0.1', 'port' => 3306],
        ];");

        file_put_contents($this->temporaryDirectory . '/production/app.php', "<?php return [
            'debug' => false,
            'database' => ['host' => 'db.prod'],
        ];");
    }

    /**
     * 清理临时目录。
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach (['/production/app.php', '/app.php'] as $file) {
            $path = $this->temporaryDirectory . $file;

            if (is_file($path)) {
                unlink($path);
            }
        }

        foreach (['/production', ''] as $dir) {
            $path = $this->temporaryDirectory . $dir;

            if (is_dir($path)) {
                rmdir($path);
            }
        }
    }

    /**
     * 点语法应能逐层读取。
     *
     * @return void
     */
    public function testReadsWithDotNotation(): void
    {
        $config = Config::fromDirectory($this->temporaryDirectory);

        $this->assertSame('SunsetReverie', $config->get('app.name'));
        $this->assertSame(3306, $config->get('app.database.port'));
        $this->assertSame('fallback', $config->get('app.missing', 'fallback'));
    }

    /**
     * 环境覆盖应深度合并（标量覆盖、数组保留未覆盖键）。
     *
     * @return void
     */
    public function testMergesEnvironmentOverrides(): void
    {
        $config = Config::fromDirectory($this->temporaryDirectory, 'production');

        $this->assertSame('db.prod', $config->get('app.database.host'));
        $this->assertSame(3306, $config->get('app.database.port'), '未被覆盖的键应保留');
        $this->assertSame('SunsetReverie', $config->get('app.name'));
    }

    /**
     * set / has 应生效。
     *
     * @return void
     */
    public function testSetAndHas(): void
    {
        $config = new Config();
        $this->assertFalse($config->has('app.name'));

        $config->set('app.name', 'Demo');

        $this->assertTrue($config->has('app.name'));
        $this->assertSame('Demo', $config->get('app.name'));
    }

    /**
     * 导出应生成可 require 的 PHP 代码。
     *
     * @return void
     */
    public function testExportsToPhpArray(): void
    {
        $config = new Config(['app' => ['name' => 'Demo']]);
        $file = $this->temporaryDirectory . '/cached.php';

        file_put_contents($file, $config->export());
        $restored = Config::fromCache($file);

        $this->assertSame('Demo', $restored->get('app.name'));

        unlink($file);
    }

    /**
     * 含闭包的配置不可导出。
     *
     * @return void
     */
    public function testExportRejectsClosure(): void
    {
        $config = new Config(['app' => ['resolver' => static fn (): string => 'x']]);

        $this->assertThrows(ConfigException::class, static fn () => $config->export());
    }
}
