<?php

declare(strict_types=1);

/**
 * 极简测试运行器（零依赖）。
 *
 * 用法：
 *   php tests/run.php
 *   php tests/run.php --filter=Container
 *
 * 约定：
 * - 测试类位于 tests/Unit 或 tests/Feature，命名空间分别为 Tests\Unit、Tests\Feature；
 * - 类名与文件名一致（如 tests/Unit/ContainerTest.php → Tests\Unit\ContainerTest）；
 * - 以 test 开头的 public 方法会被执行；
 * - 任一测试失败，进程退出码为 1（可直接用于 CI）。
 */

$basePath = dirname(__DIR__);

require $basePath . '/core/Foundation/autoload.php';
require __DIR__ . '/AssertionFailedException.php';
require __DIR__ . '/TestCase.php';

$filter = null;

foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--filter=')) {
        $filter = substr($arg, strlen('--filter='));
    }
}

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
