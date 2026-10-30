<?php

declare(strict_types=1);

/**
 * 极简测试运行器（零依赖）。
 *
 * 用法：
 *   php tests/run.php
 *   php tests/run.php --filter=Container
 *   php tests/run.php --coverage
 *   php tests/run.php --coverage --coverage-min=80
 *
 * 约定：
 * - 测试类位于 tests/Unit、tests/Feature 或 tests/Integration，
 *   命名空间分别为 Tests\Unit、Tests\Feature、Tests\Integration；
 * - 类名与文件名一致（如 tests/Unit/ContainerTest.php → Tests\Unit\ContainerTest）；
 * - 以 test 开头的 public 方法会被执行；
 * - 任一测试失败，进程退出码为 1（可直接用于 CI）。
 */

$basePath = dirname(__DIR__);

$filter = null;
$coverage = false;
$coverageMin = null;
$coverageDetail = null;

foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--filter=')) {
        $filter = substr($arg, strlen('--filter='));
    } elseif ($arg === '--coverage') {
        $coverage = true;
    } elseif (str_starts_with($arg, '--coverage-min=')) {
        $coverage = true;
        $coverageMin = (float) substr($arg, strlen('--coverage-min='));
    } elseif (str_starts_with($arg, '--coverage-detail=')) {
        $coverage = true;
        $coverageDetail = substr($arg, strlen('--coverage-detail='));
    }
}

if ($coverage && !function_exists('pcov\\start')) {
    fwrite(STDERR, "需要 pcov 扩展：pecl install pcov（并在 php.ini 加 extension=pcov.so）\n");
    exit(2);
}

/*
 * 必须在加载任何被测代码**之前**启动收集，
 * 否则 autoload 阶段 include 的文件会被算作"未执行"。
 */
if ($coverage) {
    pcov\start();
}

require $basePath . '/core/Foundation/autoload.php';
require __DIR__ . '/AssertionFailedException.php';
require __DIR__ . '/TestCase.php';

/**
 * 收集测试文件。
 *
 * @return list<array{file: string, class: string}> 测试文件与类名
 */
$collect = static function (string $directory, string $namespace): array {
    $result = [];

    foreach (glob($directory . '/*Test.php') ?: [] as $file) {
        $result[] = [
            'file' => $file,
            'class' => $namespace . '\\' . basename($file, '.php'),
        ];
    }

    sort($result);

    return $result;
};

$suites = [
    ...$collect(__DIR__ . '/Unit', 'Tests\\Unit'),
    ...$collect(__DIR__ . '/Feature', 'Tests\\Feature'),
    ...$collect(__DIR__ . '/Integration', 'Tests\\Integration'),
];

$passed = 0;
$failed = 0;
$assertions = 0;
$failures = [];

$startedAt = microtime(true);

foreach ($suites as $suite) {
    require_once $suite['file'];

    if (!class_exists($suite['class'])) {
        fwrite(STDERR, "跳过：{$suite['class']} 不存在\n");
        continue;
    }

    $reflection = new ReflectionClass($suite['class']);

    if ($reflection->isAbstract()) {
        continue;
    }

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (!str_starts_with($method->getName(), 'test')) {
            continue;
        }

        $name = $suite['class'] . '::' . $method->getName();

        if ($filter !== null && !str_contains($name, $filter)) {
            continue;
        }

        /** @var Tests\TestCase $instance */
        $instance = $reflection->newInstance();

        try {
            $setUp = $reflection->getMethod('setUp');
            $setUp->invoke($instance);
            $method->invoke($instance);
            $passed++;
            $assertions += $instance->assertionCount();
            fwrite(STDOUT, "  \033[32m✓\033[0m {$name}\n");
        } catch (Throwable $e) {
            $failed++;
            $assertions += $instance->assertionCount();
            $failures[] = [$name, $e];
            fwrite(STDOUT, "  \033[31m✗\033[0m {$name}\n");
        }
    }
}

$duration = round((microtime(true) - $startedAt) * 1000, 1);

fwrite(STDOUT, "\n");

if ($failures !== []) {
    fwrite(STDOUT, "失败详情：\n");

    foreach ($failures as [$name, $e]) {
        fwrite(STDOUT, "\n  {$name}\n");
        fwrite(STDOUT, '  ' . str_replace("\n", "\n  ", $e->getMessage()) . "\n");

        if (!$e instanceof Tests\AssertionFailedException) {
            fwrite(STDOUT, '  ' . $e::class . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n");
        }
    }

    fwrite(STDOUT, "\n");
}

/*
 * 覆盖率报告。
 *
 * pcov 的 collect() 返回 ['/path/file.php' => [行号 => 1|-1]]：
 *   1  = 该行被执行过
 *   -1 = 该行可执行但没执行
 * 所以「可执行行数」= 数组长度，「已覆盖」= 值为 1 的个数，
 * 不需要另外用 tokenizer 估算分母。
 */
if ($coverage) {
    pcov\stop();

    $include = [$basePath . '/core/', $basePath . '/components/'];
    $totalLines = 0;
    $coveredLines = 0;
    $perFile = [];

    foreach (pcov\collect() as $file => $lines) {
        $inScope = false;

        foreach ($include as $prefix) {
            if (str_starts_with((string) $file, $prefix)) {
                $inScope = true;
                break;
            }
        }

        if (!$inScope) {
            continue;
        }

        $executed = 0;
        $executable = 0;
        $missing = [];

        foreach ($lines as $line => $state) {
            $executable++;

            if ($state === 1) {
                $executed++;
            } else {
                $missing[] = $line;
            }
        }

        if ($executable === 0) {
            continue;
        }

        $totalLines += $executable;
        $coveredLines += $executed;
        $perFile[str_replace($basePath . '/', '', (string) $file)] = [
            'executed' => $executed,
            'executable' => $executable,
            'percent' => $executed / $executable * 100,
            'missing' => $missing,
        ];
    }

    // 按覆盖率升序（asort 比较的是数组元素，先比 executed，顺序会错乱）
    uasort($perFile, static fn (array $a, array $b): int => $a['percent'] <=> $b['percent']);

    $percent = $totalLines === 0 ? 0.0 : $coveredLines / $totalLines * 100;

    fwrite(STDOUT, "\n覆盖率（行）\n");
    fwrite(STDOUT, sprintf("  总覆盖  %5.1f%%   %d / %d 行\n", $percent, $coveredLines, $totalLines));
    fwrite(STDOUT, sprintf("  文件数  %d\n", count($perFile)));

    // 覆盖率最低的 10 个文件——这是补测试时最该先看的地方
    $worst = array_slice($perFile, 0, 10, true);

    if ($worst !== []) {
        fwrite(STDOUT, "\n  覆盖最低的 10 个：\n");

        foreach ($worst as $file => $stat) {
            fwrite(STDOUT, sprintf(
                "    %5.1f%%  %-46s %d/%d\n",
                $stat['percent'],
                $file,
                $stat['executed'],
                $stat['executable']
            ));
        }
    }

    // 完全没被执行的文件单独列出（往往是"写了但没测"）
    $untouched = [];

    foreach ($perFile as $file => $stat) {
        if ($stat['executed'] === 0) {
            $untouched[] = sprintf('%s (%d 行)', $file, $stat['executable']);
        }
    }

    if ($untouched !== []) {
        fwrite(STDOUT, "\n  完全未执行的文件：\n");

        foreach ($untouched as $line) {
            fwrite(STDOUT, '    ' . $line . "\n");
        }
    }

    // 指定文件的未覆盖行区间（定位"这块为什么没测"用）
    if ($coverageDetail !== null) {
        fwrite(STDOUT, sprintf("\n  [%s] 的未覆盖行：\n", $coverageDetail));

        foreach ($perFile as $file => $stat) {
            if (!str_contains((string) $file, $coverageDetail)) {
                continue;
            }

            $missing = $stat['missing'];
            sort($missing);

            $ranges = [];
            $start = null;
            $prev = null;

            foreach ($missing as $line) {
                if ($start === null) {
                    $start = $prev = $line;
                    continue;
                }

                if ($line === $prev + 1) {
                    $prev = $line;
                    continue;
                }

                $ranges[] = $start === $prev ? "L{$start}" : "L{$start}-L{$prev}";
                $start = $prev = $line;
            }

            if ($start !== null) {
                $ranges[] = $start === $prev ? "L{$start}" : "L{$start}-L{$prev}";
            }

            fwrite(STDOUT, sprintf("    %s：%d 行未覆盖，%d 个区间\n", $file, count($missing), count($ranges)));
            fwrite(STDOUT, '    ' . implode(' ', array_slice($ranges, 0, 30)) . "\n");
        }
    }

    if ($coverageMin !== null && $percent < $coverageMin) {
        fwrite(STDOUT, sprintf(
            "\n  \033[31m未达阈值\033[0m：%.1f%% < %.1f%%\n",
            $percent,
            $coverageMin
        ));

        $failed++;
    }
}

$summary = sprintf(
    '%s  通过 %d，失败 %d，断言 %d，耗时 %sms',
    $failed === 0 ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m",
    $passed,
    $failed,
    $assertions,
    $duration
);

fwrite(STDOUT, $summary . "\n");

exit($failed === 0 ? 0 : 1);
