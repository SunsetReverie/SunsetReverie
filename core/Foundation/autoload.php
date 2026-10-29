<?php

declare(strict_types=1);

/**
 * 夕梦框架 SunsetReverie —— PSR-4 自动加载器（零 Composer 依赖）
 *
 * 用法：
 *   require __DIR__ . '/core/Foundation/autoload.php';
 *
 * 设计要点：
 * 1. 只处理显式注册的命名空间前缀，未命中即返回 false，交给后续加载器（Composer）；
 * 2. 前缀按长度降序匹配，保证 SunsetReverie\Database\ 优先于 SunsetReverie\；
 * 3. 组件目录不存在时跳过（未启用的组件不产生映射开销）。
 */

namespace SunsetReverie\Foundation;

final class Autoloader
{
    /** @var array<string, list<string>> 命名空间前缀 => 目录列表 */
    private array $prefixes = [];

    /**
     * 注册命名空间前缀。
     *
     * @param string $prefix 命名空间前缀（如 SunsetReverie\）
     * @param string $directory 对应目录（绝对路径）
     * @return void
     */
    public function addNamespace(string $prefix, string $directory): void
    {
        $prefix = trim($prefix, '\\') . '\\';
        $directory = rtrim($directory, '/\\');

        $this->prefixes[$prefix][] = $directory;

        // 最长前缀优先，避免 SunsetReverie\ 抢先匹配 SunsetReverie\Database\Foo
        uksort(
            $this->prefixes,
            static fn (string $a, string $b): int => strlen($b) <=> strlen($a)
        );
    }

    /**
     * 注册到 SPL 自动加载队列。
     *
     * @param bool $prepend 是否插入队首
     * @return void
     */
    public function register(bool $prepend = false): void
    {
        spl_autoload_register([$this, 'load'], true, $prepend);
    }

    /**
     * 按 PSR-4 规则加载类文件。
     *
     * @param string $class 完整类名
     * @return bool 命中并加载返回 true，未命中返回 false
     */
    public function load(string $class): bool
    {
        foreach ($this->prefixes as $prefix => $directories) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = substr($class, strlen($prefix));
            $file = str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

            foreach ($directories as $directory) {
                $path = $directory . DIRECTORY_SEPARATOR . $file;

                if (is_file($path)) {
                    require $path;

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 获取已注册的全部前缀（调试与诊断用）。
     *
     * @return array<string, list<string>> 前缀 => 目录列表
     */
    public function prefixes(): array
    {
        return $this->prefixes;
    }
}

// ── 注册内核与官方组件命名空间 ────────────────────────────────────────
$sunsetBasePath = dirname(__DIR__, 2);
$sunsetLoader = new Autoloader();

// 内核：SunsetReverie\Foo\Bar → core/Foo/Bar.php
$sunsetLoader->addNamespace('SunsetReverie\\', $sunsetBasePath . '/core');

// 官方组件：SunsetReverie\Cache\Foo → components/cache/src/Foo.php
$sunsetComponents = [
    'Database' => 'database',
    'Cache' => 'cache',
    'Log' => 'log',
    'Validation' => 'validation',
    'Auth' => 'auth',
    'Upload' => 'upload',
    'Console' => 'console',
    'RateLimit' => 'ratelimit',
];

foreach ($sunsetComponents as $namespace => $directory) {
    $componentPath = $sunsetBasePath . '/components/' . $directory . '/src';

    if (is_dir($componentPath)) {
        $sunsetLoader->addNamespace('SunsetReverie\\' . $namespace . '\\', $componentPath);
    }
}

// 业务代码（应用骨架中存在时才映射）
if (is_dir($sunsetBasePath . '/app')) {
    $sunsetLoader->addNamespace('App\\', $sunsetBasePath . '/app');
}

$sunsetLoader->register();

return $sunsetLoader;
