<?php

declare(strict_types=1);

namespace Tests\Unit;

use SunsetReverie\Console\Lint\IsolationChecker;
use Tests\TestCase;

/**
 * 域隔离检查器测试。
 */
final class IsolationCheckerTest extends TestCase
{
    /** @var string 临时 app 目录 */
    private string $appPath;

    /**
     * 准备目录骨架。
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->appPath = sys_get_temp_dir() . '/sr-iso-check-' . uniqid('', true);

        foreach (['Api/Admin/Controller', 'Api/Web/Controller', 'Domain/Post', 'Common/Support'] as $directory) {
            mkdir($this->appPath . '/' . $directory, 0755, true);
        }
    }

    /**
     * 清理。
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->remove($this->appPath);
    }

    /**
     * 无违规时应全部通过。
     *
     * @return void
     */
    public function testCleanProjectPasses(): void
    {
        $this->write('Api/Admin/Controller/PostController.php', <<<'PHP'
<?php

namespace App\Api\Admin\Controller;

use App\Domain\Post\PostService;

final class PostController
{
}
PHP);

        $results = (new IsolationChecker($this->appPath))->run();

        foreach ($results as $result) {
            $this->assertTrue($result['ok'], $result['id'] . ' 不应报错：' . implode(' | ', $result['violations']));
        }
    }

    /**
     * 识别域列表。
     *
     * @return void
     */
    public function testDetectsDomains(): void
    {
        $checker = new IsolationChecker($this->appPath);

        $this->assertSame(['Admin', 'Web'], $checker->domains());
    }

    /**
     * 一个域引用另一个域应被 ISO-1 拦下。
     *
     * @return void
     */
    public function testDetectsCrossDomainReference(): void
    {
        $this->write('Api/Web/Controller/BadController.php', <<<'PHP'
<?php

namespace App\Api\Web\Controller;

use App\Api\Admin\Controller\PostController;

final class BadController
{
}
PHP);

        $result = $this->find('ISO-1');

        $this->assertFalse($result['ok']);
        $this->assertStringContains('App\Api\Admin\Controller\PostController', $result['violations'][0]);
    }

    /**
     * 领域层引用接口层应被 ISO-2 拦下。
     *
     * @return void
     */
    public function testDetectsDomainDependingOnApi(): void
    {
        $this->write('Domain/Post/BadService.php', <<<'PHP'
<?php

namespace App\Domain\Post;

use App\Api\Admin\Controller\PostController;

final class BadService
{
}
PHP);

        $this->assertFalse($this->find('ISO-2')['ok']);
    }

    /**
     * 公共层引用领域层应被 ISO-3 拦下。
     *
     * @return void
     */
    public function testDetectsCommonDependingOnDomain(): void
    {
        $this->write('Common/Support/BadHelper.php', <<<'PHP'
<?php

namespace App\Common\Support;

use App\Domain\Post\PostService;

final class BadHelper
{
}
PHP);

        $this->assertFalse($this->find('ISO-3')['ok']);
    }

    /**
     * 注释里的 use 不应造成误报。
     *
     * @return void
     */
    public function testIgnoresCommentsAndClosures(): void
    {
        $this->write('Api/Web/Controller/Clean.php', <<<'PHP'
<?php

namespace App\Api\Web\Controller;

use Closure;

// use App\Api\Admin\Controller\PostController;  ← 注释，不应误报
final class Clean
{
    public function handle(Closure $next): mixed
    {
        // 闭包 use 语法也不应被当成 import
        $fn = function () use ($next): mixed {
            return $next();
        };

        return $fn();
    }
}
PHP);

        $this->assertTrue($this->find('ISO-1')['ok'], '注释与闭包 use 不应误判');
    }

    /**
     * 取指定编号的结果。
     *
     * @param string $id 检查编号
     * @return array{id: string, ok: bool, summary: string, violations: list<string>} 结果
     */
    private function find(string $id): array
    {
        foreach ((new IsolationChecker($this->appPath))->run() as $result) {
            if ($result['id'] === $id) {
                return $result;
            }
        }

        $this->assertTrue(false, '未找到检查项 ' . $id);

        return ['id' => $id, 'ok' => false, 'summary' => '', 'violations' => []];
    }

    /**
     * 写入文件。
     *
     * @param string $relative 相对 app/ 的路径
     * @param string $contents 内容
     * @return void
     */
    private function write(string $relative, string $contents): void
    {
        $path = $this->appPath . '/' . $relative;

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $contents);
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
