<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * storage:link 的端到端行为。
 *
 * 这个命令看起来简单（建个软链），但真正决定它好不好用的是**异常路径**：
 *
 *   - 目标位置已经有真实文件时，自动删掉就是数据丢失
 *   - 用绝对路径建链，换机器全部失效，而表现只是「文件 404」
 *   - 不幂等的话，部署脚本每次跑都要处理「已存在」的报错
 *
 * 所以六个用例里只有一个是正常路径，其余全是边界。
 */
final class StorageLinkTest extends TestCase
{
    /** @var string 框架根 */
    private string $framework;

    /** @var string 临时项目根 */
    private string $project;

    protected function setUp(): void
    {
        // Windows 上 symlink() 需要管理员权限，无法在 CI 里稳定复现
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        $this->framework = dirname(__DIR__, 2);
        $this->project = sys_get_temp_dir() . '/sr-storage-link';

        if (!is_file($this->project . '/bin/sr')) {
            $this->exec(sprintf(
                'php %s/bin/sr new %s --force',
                escapeshellarg($this->framework),
                escapeshellarg($this->project)
            ));
        }

        // 每个用例从「干净状态」开始
        $this->cleanPublic();
    }

    /**
     * 清掉 public 下的软链与测试目录。
     *
     * @return void
     */
    private function cleanPublic(): void
    {
        foreach (['uploads', 'files', 'private', 'deep'] as $name) {
            $path = $this->project . '/public/' . $name;

            if (is_link($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->removeDirectory($path);
            }
        }
    }

    /**
     * 递归删除目录（仅测试用）。
     *
     * @param string $directory 目录
     * @return void
     */
    private function removeDirectory(string $directory): void
    {
        $items = @scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;

            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }

    /**
     * 跑一条命令，返回 [退出码, 输出]。
     *
     * @param string $arguments 命令参数（不含 php bin/sr）
     * @return array{0: int, 1: string} 结果
     */
    private function sr(string $arguments): array
    {
        return $this->exec(sprintf(
            'php %s/bin/sr %s',
            escapeshellarg($this->framework),
            $arguments
        ));
    }

    /**
     * 在临时项目里执行命令。
     *
     * @param string $command 完整命令
     * @return array{0: int, 1: string} [退出码, 输出]
     */
    private function exec(string $command): array
    {
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = array_merge(getenv(), ['SR_BASE_PATH' => $this->project]);

        /*
         * cwd 用框架根（一定存在）：首次调用是 `sr new`，那时
         * $this->project 还没建出来，拿它当 cwd 会让 proc_open 直接失败
         * （返回 255，看不出原因）。
         *
         * 项目根靠 SR_BASE_PATH 传，这正是业务项目 bin/sr 的用法。
         */
        $process = @proc_open($command, $descriptor, $pipes, $this->framework, $env);

        if (!is_resource($process)) {
            return [255, '无法启动子进程'];
        }

        fclose($pipes[0]);

        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), trim($output)];
    }

    /**
     * 缺参数时给出用法，退出码 2。
     *
     * @return void
     */
    public function testMissingArgumentShowsUsage(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        [$code, $output] = $this->sr('storage:link');

        $this->assertSame(2, $code, '用法错误应返回退出码 2');
        $this->assertStringContains('用法', $output);
        $this->assertStringContains('storage:link', $output);
    }

    /**
     * 源目录不存在时报错，不建链。
     *
     * @return void
     */
    public function testMissingSourceFails(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        [$code, $output] = $this->sr('storage:link storage/does-not-exist');

        $this->assertSame(1, $code);
        $this->assertStringContains('源目录不存在', $output);
        $this->assertFalse(
            is_link($this->project . '/public/does-not-exist'),
            '源目录不存在时不应创建软链'
        );
    }

    /**
     * 正常创建：软链用**相对路径**，且能实际读到文件。
     *
     * 相对路径是这个命令的关键设计——绝对路径换机器就失效，
     * 而失效表现是「文件 404」，很难联想到软链。
     *
     * @return void
     */
    public function testCreatesRelativeSymlink(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        @mkdir($this->project . '/storage/uploads', 0755, true);
        file_put_contents($this->project . '/storage/uploads/a.txt', 'hello');

        [$code, $output] = $this->sr('storage:link storage/uploads');

        $this->assertSame(0, $code, '正常创建应返回 0：' . $output);

        $link = $this->project . '/public/uploads';

        $this->assertTrue(is_link($link), '应创建软链');

        $target = (string) readlink($link);

        $this->assertSame('../storage/uploads', $target, '软链目标必须是相对路径');
        $this->assertFalse(str_starts_with($target, '/'), '不能写入本机绝对路径');

        // 通过软链能读到真实文件
        $this->assertSame('hello', (string) file_get_contents($link . '/a.txt'));
    }

    /**
     * 重复执行是幂等的（退出码 0，提示跳过）。
     *
     * 部署脚本里可以无脑重复跑，不用先判断。
     *
     * @return void
     */
    public function testIsIdempotent(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        @mkdir($this->project . '/storage/uploads', 0755, true);

        $this->sr('storage:link storage/uploads');

        [$code, $output] = $this->sr('storage:link storage/uploads');

        $this->assertSame(0, $code, '已存在时应返回 0，否则脚本会误判失败');
        $this->assertStringContains('已存在', $output);
        $this->assertTrue(is_link($this->project . '/public/uploads'));
    }

    /**
     * 软链指向别处时，重建（而不是报错让人手动处理）。
     *
     * @return void
     */
    public function testRebuildsWhenPointingElsewhere(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        @mkdir($this->project . '/storage/uploads', 0755, true);
        @mkdir($this->project . '/storage/other', 0755, true);

        // 先建一个指向 other 的软链
        @symlink('../storage/other', $this->project . '/public/uploads');

        [$code, $output] = $this->sr('storage:link storage/uploads');

        $this->assertSame(0, $code, $output);
        $this->assertStringContains('已移除后重建', $output);
        $this->assertSame('../storage/uploads', (string) readlink($this->project . '/public/uploads'));
    }

    /**
     * 目标位置是普通目录时报错，**绝不自动删除**。
     *
     * 这是最危险的一条：public/uploads 里如果已经有真实文件
     * （手工拷的、旧版本遗留的），删掉就是数据丢失。
     *
     * @return void
     */
    public function testRefusesToDeleteRealDirectory(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        @mkdir($this->project . '/storage/uploads', 0755, true);
        @mkdir($this->project . '/public/uploads', 0755, true);
        file_put_contents($this->project . '/public/uploads/real.txt', 'do not delete');

        [$code, $output] = $this->sr('storage:link storage/uploads');

        $this->assertSame(1, $code, '普通目录占位时应失败');
        $this->assertStringContains('不是软链', $output);

        // 关键：真实文件必须还在
        $this->assertTrue(
            is_file($this->project . '/public/uploads/real.txt'),
            '绝不能删除真实目录里的文件'
        );
        $this->assertFalse(is_link($this->project . '/public/uploads'), '仍然是普通目录，没被替换');
    }

    /**
     * 自定义链接路径。
     *
     * @return void
     */
    public function testCustomLinkPath(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        @mkdir($this->project . '/storage/app/deep', 0755, true);

        [$code, $output] = $this->sr('storage:link storage/app/deep public/files');

        $this->assertSame(0, $code, $output);
        $this->assertSame(
            '../storage/app/deep',
            (string) readlink($this->project . '/public/files')
        );
    }
}
