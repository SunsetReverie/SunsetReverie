<?php

declare(strict_types=1);

namespace Tests\Unit;

use SunsetReverie\Console\Lint\ArchitectureChecker;
use Tests\TestCase;

/**
 * 架构检查器测试。
 */
final class ArchitectureCheckerTest extends TestCase
{
    /** @var string 临时框架根 */
    private string $root;

    /**
     * 准备目录。
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/sr-arch-' . uniqid('', true);
        mkdir($this->root . '/core/Foundation', 0755, true);
        mkdir($this->root . '/components/alpha/src', 0755, true);
        mkdir($this->root . '/components/beta/src', 0755, true);
    }

    /**
     * 清理。
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    /**
     * 框架自身应通过全部检查（回归保护）。
     *
     * @return void
     */
    public function testFrameworkItselfPasses(): void
    {
        $results = (new ArchitectureChecker(dirname(__DIR__, 2)))->run();

        foreach ($results as $result) {
            $this->assertTrue($result['ok'], sprintf(
                '%s 未通过：%s',
                $result['id'],
                implode(' | ', $result['violations'])
            ));
        }
    }

    /**
     * 内核引用 App\ 应被 ARCH-1 拦下。
     *
     * @return void
     */
    public function testDetectsAppReference(): void
    {
        file_put_contents(
            $this->root . '/core/Foundation/Bad.php',
            "<?php\nnamespace SunsetReverie\\Foundation;\n\nuse App\\Service\\OrderService;\n"
        );

        $result = $this->find('ARCH-1');

        $this->assertFalse($result['ok']);
        $this->assertStringContains('App\\Service\\OrderService', $result['violations'][0]);
    }

    /**
     * 引用第三方命名空间应被 ARCH-2 拦下。
     *
     * @return void
     */
    public function testDetectsThirdPartyReference(): void
    {
        file_put_contents(
            $this->root . '/core/Foundation/Bad.php',
            "<?php\nnamespace SunsetReverie\\Foundation;\n\nuse Psr\\Log\\LoggerInterface;\n"
        );

        $this->assertFalse($this->find('ARCH-2')['ok']);
    }

    /**
     * 全局内置类不应被误判为第三方（Closure / PDO / RuntimeException）。
     *
     * @return void
     */
    public function testGlobalBuiltinClassesAreAllowed(): void
    {
        file_put_contents(
            $this->root . '/core/Foundation/Ok.php',
            "<?php\nnamespace SunsetReverie\\Foundation;\n\nuse Closure;\nuse RuntimeException;\nuse PDO;\n"
        );

        $this->assertTrue($this->find('ARCH-2')['ok'], '内置类不应算第三方依赖');
    }

    /**
     * 组件互相引用应被 ARCH-3 拦下（白名单外）。
     *
     * @return void
     */
    public function testDetectsCrossComponentReference(): void
    {
        file_put_contents(
            $this->root . '/components/alpha/src/Bad.php',
            "<?php\nnamespace SunsetReverie\\Alpha;\n\nuse SunsetReverie\\Beta\\Helper;\n"
        );

        $this->assertFalse($this->find('ARCH-3')['ok']);
    }

    /**
     * 非组装层的内核文件引用组件应被 ARCH-4 拦下。
     *
     * @return void
     */
    public function testDetectsCoreDependingOnComponent(): void
    {
        file_put_contents(
            $this->root . '/core/Foundation/Bad.php',
            "<?php\nnamespace SunsetReverie\\Foundation;\n\nuse SunsetReverie\\Alpha\\Helper;\n"
        );

        $this->assertFalse($this->find('ARCH-4')['ok']);
    }

    /**
     * 组装层（Application）引用组件是允许的。
     *
     * @return void
     */
    public function testAssemblyLayerMayReferenceComponents(): void
    {
        file_put_contents(
            $this->root . '/core/Foundation/Application.php',
            "<?php\nnamespace SunsetReverie\\Foundation;\n\nuse SunsetReverie\\Alpha\\Helper;\n"
        );

        $this->assertTrue($this->find('ARCH-4')['ok'], 'Application 作为组装层应被放行');
    }

    /**
     * 取指定编号的检查结果。
     *
     * @param string $id 检查编号
     * @return array{id: string, ok: bool, summary: string, violations: list<string>} 结果
     */
    private function find(string $id): array
    {
        foreach ((new ArchitectureChecker($this->root))->run() as $result) {
            if ($result['id'] === $id) {
                return $result;
            }
        }

        $this->assertTrue(false, '未找到检查项 ' . $id);

        return ['id' => $id, 'ok' => false, 'summary' => '', 'violations' => []];
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
