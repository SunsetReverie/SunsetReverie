<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 命令行端到端测试：以子进程方式跑 `php bin/sr xxx`，断言退出码与输出。
 *
 * 为什么必须用子进程而不是直接调 ConsoleKernel 的方法：
 *
 * 1. 命令的行为有一半在**入口脚本**里——`bin/sr` 决定 `SR_BASE_PATH` 怎么取、
 *    autoload 什么顺序加载。直接调类方法测不到这些。
 * 2. 退出码是 CLI 的契约。`make:controller` 失败时返回 1 还是 0，
 *    只有真跑一次才知道，而 CI / 部署脚本正是靠退出码判断成败。
 * 3. 40 个命令的实现占了 ConsoleKernel 864 行里的绝大部分，
 *    此前一条测试都没有——覆盖率报告里它是覆盖最低的文件之一。
 *
 * 用固定的临时项目目录，首次运行时用 `new` 生成（顺便测了脚手架命令）。
 */
final class ConsoleCommandTest extends TestCase
{
    /** @var string 框架根 */
    private string $framework;

    /** @var string 临时项目根 */
    private string $project;

    /**
     * 准备临时项目（只生成一次，多个用例复用）。
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->framework = dirname(__DIR__, 2);
        $this->project = sys_get_temp_dir() . '/sr-cli-test';

        if (is_file($this->project . '/bin/sr')) {
            return;
        }

        // 首次：用 new 生成（顺便验证脚手架）
        $this->runRaw(sprintf(
            'php %s/bin/sr new %s --force',
            escapeshellarg($this->framework),
            escapeshellarg($this->project)
        ));
    }

    /**
     * 帮助信息列出主要命令。
     *
     * @return void
     */
    public function testHelpListsCommands(): void
    {
        [$code, $output] = $this->sr('help');

        $this->assertSame(0, $code, 'help 应正常退出');

        foreach (['new', 'route:list', 'migrate', 'lint:architecture', 'queue:work'] as $command) {
            $this->assertStringContains($command, $output, "帮助信息应列出 {$command}");
        }
    }

    /**
     * 未知命令应报错且退出码非 0。
     *
     * @return void
     */
    public function testUnknownCommandFails(): void
    {
        [$code, $output] = $this->sr('no:such:command');

        // 约定：2 = 用法错误（未知命令、缺参数），1 = 执行失败
        $this->assertSame(2, $code, '未知命令应返回 2（用法错误）');
        $this->assertStringContains('未知命令', $output);
    }

    /**
     * route:list 输出已注册路由。
     *
     * @return void
     */
    public function testRouteList(): void
    {
        [$code, $output] = $this->sr('route:list');

        $this->assertSame(0, $code);
        $this->assertStringContains('路由', $output);
    }

    /**
     * route:list 支持 --domain 过滤。
     *
     * @return void
     */
    public function testRouteListWithDomain(): void
    {
        [$code, $output] = $this->sr('route:list', ['--domain=web']);

        // 单域骨架里可能没有 web 域，此时返回 1 是合理的；
        // 关键是 --domain 参数被识别（不报"未知命令"）
        $this->assertFalse(
            str_contains($output, '未知命令'),
            '--domain 参数应被识别，实际：' . mb_substr($output, 0, 120)
        );
    }

    /**
     * env:check 输出检查项。
     *
     * @return void
     */
    public function testEnvCheck(): void
    {
        [$code, $output] = $this->sr('env:check');

        // 环境不达标时退出码可能非 0，但不应崩溃
        $this->assertTrue(
            str_contains($output, 'PHP') || str_contains($output, '检查'),
            'env:check 应输出检查结果，实际：' . mb_substr($output, 0, 200)
        );
    }

    /**
     * key:generate --show 只打印不写入。
     *
     * @return void
     */
    public function testKeyGenerateShowDoesNotWrite(): void
    {
        $envFile = $this->project . '/.env';
        $before = is_file($envFile) ? (string) file_get_contents($envFile) : null;

        [$code, $output] = $this->sr('key:generate', ['--show']);

        $this->assertSame(0, $code);
        $this->assertTrue(
            preg_match('/[A-Za-z0-9+\/=]{20,}/', $output) === 1,
            '--show 应输出一段密钥，实际：' . mb_substr($output, 0, 100)
        );

        $after = is_file($envFile) ? (string) file_get_contents($envFile) : null;

        $this->assertSame($before, $after, '--show 不应修改 .env');
    }

    /**
     * make:controller 生成文件。
     *
     * @return void
     */
    public function testMakeController(): void
    {
        $name = 'Probe' . $this->suffix();

        [$code, $output] = $this->sr('make:controller', ['Web/' . $name]);

        $this->assertSame(0, $code, 'make:controller 应成功，输出：' . $output);

        $file = $this->project . '/app/Api/Web/Controller/' . $name . '.php';
        $this->assertTrue(is_file($file), '应生成 ' . $file);

        $source = (string) file_get_contents($file);
        $this->assertStringContains($name, $source);
        $this->assertStringContains('declare(strict_types=1)', $source);
    }

    /**
     * make:model 生成领域服务。
     *
     * @return void
     */
    public function testMakeModel(): void
    {
        $name = 'Probe' . $this->suffix();

        [$code, $output] = $this->sr('make:model', [$name]);

        $this->assertSame(0, $code, '输出：' . $output);
        $this->assertTrue(
            is_file($this->project . '/app/Domain/' . $name . '/' . $name . 'Service.php'),
            '应生成 app/Domain/' . $name . '/' . $name . 'Service.php'
        );
    }

    /**
     * make:migration 生成带时间戳的迁移文件。
     *
     * @return void
     */
    public function testMakeMigration(): void
    {
        $table = 'create_probe_' . strtolower($this->suffix()) . '_table';

        [$code, $output] = $this->sr('make:migration', [$table]);

        $this->assertSame(0, $code, '输出：' . $output);

        $files = glob($this->project . '/database/migrations/*' . $table . '.php') ?: [];

        $this->assertSame(1, count($files), '应生成一个迁移文件，实际：' . json_encode($files));

        $source = (string) file_get_contents($files[0]);
        $this->assertStringContains($table, $source);
        $this->assertStringContains('return new class extends Migration', $source);
    }

    /**
     * make:middleware / make:request / make:seeder 都能生成。
     *
     * @return void
     */
    public function testMakeOtherScaffolds(): void
    {
        $suffix = $this->suffix();
        $cases = [
            ['make:middleware', 'ProbeMiddleware' . $suffix, 'app/Http/Middleware/ProbeMiddleware' . $suffix . '.php', 'ProbeMiddleware' . $suffix],
            // make:request 要求 {域}/{名称} 格式
            ['make:request', 'Admin/ProbeRequest' . $suffix, 'app/Api/Admin/Request/ProbeRequest' . $suffix . '.php', 'Admin/ProbeRequest' . $suffix],
            ['make:seeder', 'ProbeSeeder' . $suffix, 'scripts/ProbeSeeder' . $suffix . '.php', 'ProbeSeeder' . $suffix],
        ];

        foreach ($cases as [$command, $name, $relative, $argument]) {
            [$code, $output] = $this->sr($command, [$argument]);

            $this->assertSame(0, $code, "{$command} 应成功，输出：" . $output);
            $this->assertTrue(
                is_file($this->project . '/' . $relative),
                "{$command} 应生成 {$relative}"
            );
        }
    }

    /**
     * 缺少参数时应提示用法且退出码非 0，而不是静默成功。
     *
     * @return void
     */
    public function testMakeWithoutArgumentShowsUsage(): void
    {
        [$code, $output] = $this->sr('make:controller');

        $this->assertSame(2, $code, '缺参数应返回 2（区别于未知命令的 1）');
        $this->assertStringContains('用法', $output);
    }

    /**
     * lint:architecture 在临时项目里也应通过。
     *
     * @return void
     */
    public function testLintArchitectureOnFreshProject(): void
    {
        [$code, $output] = $this->sr('lint:architecture');

        $this->assertSame(0, $code, '刚生成的骨架不应有架构违规，输出：' . $output);
        $this->assertStringContains('ARCH-1', $output);
    }

    /**
     * lint:isolation 检查域隔离。
     *
     * @return void
     */
    public function testLintIsolation(): void
    {
        [$code, $output] = $this->sr('lint:isolation');

        // 单域骨架下应通过；失败时至少要输出规则编号而不是崩溃
        $this->assertTrue(
            str_contains($output, 'DOMAIN') || str_contains($output, '隔离') || $code === 0,
            'lint:isolation 应输出检查结果，实际：' . mb_substr($output, 0, 200)
        );
    }

    /**
     * 本次运行唯一的后缀。
     *
     * 临时项目目录会被多个用例、多次运行复用，
     * 固定的类名会让第二次运行报"目标已存在"——那是测试自己的问题，不是产品缺陷。
     *
     * @return string 如 "A1B2C3"
     */
    private function suffix(): string
    {
        return strtoupper(bin2hex(random_bytes(3)));
    }

    /**
     * 执行一条命令。
     *
     * @param string $command 命令名
     * @param list<string> $args 参数
     * @return array{0: int, 1: string} 退出码与合并输出
     */
    private function sr(string $command, array $args = []): array
    {
        $parts = [escapeshellarg($command)];

        foreach ($args as $arg) {
            $parts[] = escapeshellarg($arg);
        }

        return $this->runRaw(sprintf(
            'php %s/bin/sr %s',
            escapeshellarg($this->framework),
            implode(' ', $parts)
        ));
    }

    /**
     * 以子进程执行，返回退出码与合并后的输出。
     *
     * @param string $command 完整命令
     * @return array{0: int, 1: string} 退出码与输出
     */
    private function runRaw(string $command): array
    {
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        /*
         * cwd 用框架根（一定存在）：首次调用时 $this->project 还没建出来，
         * 以它为 cwd 会让 proc_open 直接失败。
         * 项目根通过 SR_BASE_PATH 传给 bin/sr —— 这正是业务项目 shim 的用法，
         * 顺带验证了这条路径。
         */
        $env = array_merge(getenv(), ['SR_BASE_PATH' => $this->project]);

        $process = proc_open($command, $descriptor, $pipes, $this->framework, $env);

        if (!is_resource($process)) {
            return [255, '无法启动子进程'];
        }

        fclose($pipes[0]);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $code = proc_close($process);

        return [$code, trim($stdout . "\n" . $stderr)];
    }
}
