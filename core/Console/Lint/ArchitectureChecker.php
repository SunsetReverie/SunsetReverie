<?php

declare(strict_types=1);

namespace SunsetReverie\Console\Lint;

/**
 * 架构检查：用静态分析守住"内核不腐化"这条底线。
 *
 * 只做**能明确判定**的四条（无法量化的规则不写进来，避免变成摆设）：
 *   ARCH-1  内核不得引用业务命名空间 App\
 *   ARCH-2  内核与组件不得引用第三方命名空间（白名单：SunsetReverie\）
 *   ARCH-3  组件之间不得互相引用（只能依赖内核）
 *   ARCH-4  除组装层 Application 外，内核不得引用任何组件
 *
 * 实现说明：用 `token_get_all()` 解析而非正则，避免注释与字符串里的 "use" 造成误报。
 */
final class ArchitectureChecker
{
    /** 允许的顶层命名空间前缀 */
    private const ALLOWED_ROOTS = ['SunsetReverie\\'];

    /**
     * 允许引用组件的内核文件 / 目录（组装层）。
     *
     * - Application：负责注册 Provider，必然要知道组件类
     * - Support/Facades：门面的职责就是声明"静态调用解析到哪个容器标识"，
     *   本质上与组装层同性质；若改成字符串键，反而失去了类型检查与 IDE 跳转
     */
    private const ASSEMBLY_FILES = ['core/Foundation/Application.php'];

    private const ASSEMBLY_PREFIXES = ['core/Support/Facades/'];

    /**
     * 允许的组件间依赖（显式声明，附理由；未声明的依赖一律视为违规）。
     *
     * ratelimit → cache：限流计数器必须落在共享存储上，复用缓存驱动而非自造一套。
     */
    private const ALLOWED_COMPONENT_DEPS = [
        // 限流计数器必须落在共享存储上，复用缓存驱动而非自造一套
        'ratelimit' => ['cache'],
        // database 驱动的队列就是把任务存进数据库表，离不开连接
        'queue' => ['database'],
        // log 驱动的邮件器（本地开发不发真邮件）需要写日志
        'mail' => ['log'],
    ];

    /** @var list<string> 组件名（由 components/ 目录扫描得到） */
    private array $components = [];

    /**
     * @param string $frameworkRoot 框架根目录（绝对路径）
     */
    public function __construct(private readonly string $frameworkRoot)
    {
        $this->components = $this->detectComponents();
    }

    /**
     * 执行全部检查。
     *
     * @return list<array{id: string, ok: bool, summary: string, violations: list<string>}> 检查结果
     */
    public function run(): array
    {
        return [
            $this->checkAppReferences(),
            $this->checkThirdPartyReferences(),
            $this->checkCrossComponentReferences(),
            $this->checkCoreDependsOnComponents(),
            $this->checkVendorFreeBootstrap(),
            $this->checkVendorCoexist(),
            $this->checkFacadeAnnotations(),
            $this->checkComponentRegistration(),
        ];
    }

    /**
     * ARCH-1：内核不得引用 App\。
     *
     * @return array{id: string, ok: bool, summary: string, violations: list<string>} 结果
     */
    private function checkAppReferences(): array
    {
        $violations = [];

        foreach ($this->sourceFiles('core') as $relative => $file) {
            foreach ($this->usesOf($file) as $use) {
                if ($this->isAppNamespace($use)) {
                    $violations[] = sprintf('%s 引用了 %s', $relative, $use);
                }
            }
        }

        return [
            'id' => 'ARCH-1',
            'ok' => $violations === [],
            'summary' => '内核无业务命名空间引用',
            'violations' => $violations,
        ];
    }

    /**
     * ARCH-2：内核与组件不得引用第三方命名空间。
     *
     * @return array{id: string, ok: bool, summary: string, violations: list<string>} 结果
     */
    private function checkThirdPartyReferences(): array
    {
        $violations = [];
        $files = $this->sourceFiles('core') + $this->sourceFiles('components');

        foreach ($files as $relative => $file) {
            foreach ($this->usesOf($file) as $use) {
                // 不含命名空间的 use 指向全局类（Closure / PDO / RuntimeException 等），
                // 这些是 PHP 内置或全局用户类，不属于第三方依赖
                if (!str_contains($use, '\\')) {
                    continue;
                }

                if ($this->isAppNamespace($use) || $this->isAllowed($use)) {
                    continue;
                }

                $violations[] = sprintf('%s 引用了第三方 %s', $relative, $use);
            }
        }

        return [
            'id' => 'ARCH-2',
            'ok' => $violations === [],
            'summary' => '核心零第三方依赖（白名单：SunsetReverie\）',
            'violations' => $violations,
        ];
    }

    /**
     * ARCH-3：组件之间不得互相引用。
     *
     * @return array{id: string, ok: bool, summary: string, violations: list<string>} 结果
     */
    private function checkCrossComponentReferences(): array
    {
        $violations = [];

        foreach ($this->components as $component) {
            foreach ($this->sourceFiles('components/' . $component) as $relative => $file) {
                foreach ($this->usesOf($file) as $use) {
                    $target = $this->componentOf($use);

                    if ($target === null || $target === $component) {
                        continue;
                    }

                    if (in_array($target, self::ALLOWED_COMPONENT_DEPS[$component] ?? [], true)) {
                        continue;
                    }

                    $violations[] = sprintf('%s 引用了其他组件 %s', $relative, $use);
                }
            }
        }

        return [
            'id' => 'ARCH-3',
            'ok' => $violations === [],
            'summary' => '组件之间无未声明的互相依赖（白名单见常量）',
            'violations' => $violations,
        ];
    }

    /**
     * ARCH-4：内核只有组装层可以引用组件。
     *
     * @return array{id: string, ok: bool, summary: string, violations: list<string>} 结果
     */
    private function checkCoreDependsOnComponents(): array
    {
        $violations = [];

        foreach ($this->sourceFiles('core') as $relative => $file) {
            if ($this->isAssemblyLayer($relative)) {
                continue;
            }

            foreach ($this->usesOf($file) as $use) {
                $target = $this->componentOf($use);

                if ($target !== null) {
                    $violations[] = sprintf('%s 引用了组件 %s（应通过容器字符串键或 Provider 解耦）', $relative, $use);
                }
            }
        }

        return [
            'id' => 'ARCH-4',
            'ok' => $violations === [],
            'summary' => '内核仅组装层（Application / Facades）依赖组件',
            'violations' => $violations,
        ];
    }

    /**
     * 扫描目录下的 PHP 文件。
     *
     * @param string $relativeDirectory 相对框架根的目录
     * @return array<string, string> 相对路径 => 绝对路径
     */
    private function sourceFiles(string $relativeDirectory): array
    {
        $root = $this->frameworkRoot . '/' . $relativeDirectory;

        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $absolute = $file->getPathname();
            $files[substr($absolute, strlen($this->frameworkRoot) + 1)] = $absolute;
        }

        ksort($files);

        return $files;
    }

    /**
     * 用 token_get_all 提取文件里的 use 语句。
     *
     * @param string $file 文件路径
     * @return list<string> 完整类名
     */
    private function usesOf(string $file): array
    {
        $source = @file_get_contents($file);

        if ($source === false) {
            return [];
        }

        $tokens = token_get_all($source);
        $uses = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            if (!is_array($tokens[$index]) || $tokens[$index][0] !== T_USE) {
                continue;
            }

            // 跳过闭包 use ( ... ) 与 trait use
            $next = $index + 1;

            while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                $next++;
            }

            if ($next < $count && $tokens[$next] === '(') {
                continue;
            }

            $buffer = '';

            for ($cursor = $next; $cursor < $count; $cursor++) {
                $token = $tokens[$cursor];

                if ($token === ';' || $token === '{') {
                    break;
                }

                if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $buffer .= is_array($token) ? $token[1] : $token;
            }

            $buffer = trim($buffer);

            if ($buffer === '' || str_starts_with($buffer, 'function ') || str_starts_with($buffer, 'const ')) {
                continue;
            }

            $uses[] = ltrim($buffer, '\\');
        }

        return $uses;
    }

    /**
     * 是否为业务命名空间。
     *
     * @param string $use 类名
     * @return bool 是 App\ 返回 true
     */
    private function isAppNamespace(string $use): bool
    {
        return str_starts_with($use, 'App\\');
    }

    /**
     * 是否在允许的顶层命名空间内。
     *
     * @param string $use 类名
     * @return bool 允许返回 true
     */
    private function isAllowed(string $use): bool
    {
        foreach (self::ALLOWED_ROOTS as $root) {
            if (str_starts_with($use, $root)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 是否为组装层文件。
     *
     * @param string $relative 相对路径
     * @return bool 是组装层返回 true
     */
    private function isAssemblyLayer(string $relative): bool
    {
        if (in_array($relative, self::ASSEMBLY_FILES, true)) {
            return true;
        }

        foreach (self::ASSEMBLY_PREFIXES as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 判断某个类名属于哪个组件。
     *
     * @param string $use 类名
     * @return string|null 组件名
     */
    /**
     * ARCH-5：无 vendor/ 时核心可启动。
     *
     * 这项需要真正启动一次应用（无 Composer 环境下的冒烟），
     * 与架构检查"只读文件、5 秒内完成"的约束冲突，
     * 因此交给 tests/Feature/VendorCoexistTest 覆盖，
     * 这里明确标 N/A 而不是假装检查过。
     *
     * @return array{id: string, ok: bool, summary: string, violations: list<string>} 结果
     */
    private function checkVendorFreeBootstrap(): array
    {
        return [
            'id' => 'ARCH-5',
            'ok' => true,
            'summary' => 'N/A：由 tests/Feature/VendorCoexistTest 覆盖（需启动容器）',
            'violations' => [],
        ];
    }

    /**
     * ARCH-6：存在 vendor/autoload.php 时加载不冲突。
     *
     * 同 ARCH-5，属运行期行为，由测试覆盖。
     *
     * @return array{id: string, ok: bool, summary: string, violations: list<string>} 结果
     */
    private function checkVendorCoexist(): array
    {
        return [
            'id' => 'ARCH-6',
            'ok' => true,
            'summary' => 'N/A：由 tests/Feature/VendorCoexistTest 覆盖（两种加载顺序）',
            'violations' => [],
        ];
    }

    /**
     * ARCH-7：Facade 的 @method 注解与底层类一致。
     *
     * 为什么值得检查：Facade 靠 `__callStatic` 转发，注解写漏了不会报错，
     * 但 IDE 提示与静态分析会失效（`DB::statement()` 就被漏掉过一次）。
     * 注解写了底层不存在的方法更糟——调用时才炸。
     *
     * @return array{id: string, ok: bool, summary: string, violations: list<string>} 结果
     */
    private function checkFacadeAnnotations(): array
    {
        $violations = [];
        $directory = $this->frameworkRoot . '/core/Support/Facades';

        foreach (glob($directory . '/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            $facade = basename($file, '.php');

            // accessor() 里 return Xxx::class;
            if (preg_match('/return\s+([\\\w]+)::class\s*;/', $source, $m) !== 1) {
                continue;
            }

            $accessor = ltrim($m[1], '\\');

            if (!class_exists($accessor)) {
                $violations[] = sprintf('%s: accessor 类 %s 不存在', $facade, $accessor);
                continue;
            }

            // @method static <返回类型> <方法名>(<参数>)
            preg_match_all('/@method\s+static\s+[^\s]+\s+(\w+)\(/', $source, $methods);

            foreach (array_unique($methods[1] ?? []) as $method) {
                if (!method_exists($accessor, $method)) {
                    $violations[] = sprintf(
                        '%s: 注解声明了 %s()，但 %s 没有该方法',
                        $facade,
                        $method,
                        $accessor
                    );
                }
            }
        }

        return [
            'id' => 'ARCH-7',
            'ok' => $violations === [],
            'summary' => 'Facade @method 注解与底层类一致',
            'violations' => $violations,
        ];
    }

    /**
     * ARCH-8：组件目录与 autoload 映射表一致。
     *
     * 踩过三次的坑：新增组件时忘了在 autoload.php 的 $sunsetComponents 里登记，
     * 运行期表现为"类找不到"，而报错信息完全指不到真正原因。
     *
     * @return array{id: string, ok: bool, summary: string, violations: list<string>} 结果
     */
    private function checkComponentRegistration(): array
    {
        $violations = [];
        $componentsDir = $this->frameworkRoot . '/components';

        $directories = [];

        foreach (glob($componentsDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $directories[] = basename($dir);
        }

        $autoloadPath = $this->frameworkRoot . '/core/Foundation/autoload.php';

        /*
         * 读不到就明确报违规，而不是让 file_get_contents 抛 warning：
         * warning 会被 error_reporting 设置、SAPI、以及各种错误处理器差别对待，
         * 有时是噪声、有时被静默吞掉。这类「配置错了」的问题应该显式说出来。
         */
        if (!is_file($autoloadPath)) {
            return [
                'id' => 'ARCH-8',
                'ok' => false,
                'summary' => '组件目录与 autoload 映射表一致',
                'violations' => [sprintf(
                    '找不到 %s，无法校验组件注册（frameworkRoot 可能配错了：%s）',
                    $autoloadPath,
                    $this->frameworkRoot
                )],
            ];
        }

        $autoload = (string) file_get_contents($autoloadPath);

        // 从 $sunsetComponents 数组里取 'Namespace' => 'dir'
        preg_match_all("/'([A-Za-z]+)'\s*=>\s*'([a-z]+)'/", $autoload, $matches, PREG_SET_ORDER);

        $registered = [];

        foreach ($matches as $match) {
            $registered[$match[2]] = $match[1];
        }

        // 有目录但没登记
        foreach ($directories as $dir) {
            if (!isset($registered[$dir])) {
                $violations[] = sprintf(
                    'components/%s 存在，但 autoload.php 的 $sunsetComponents 未登记',
                    $dir
                );
            }
        }

        // 登记了但目录不存在
        foreach ($registered as $dir => $namespace) {
            if (!is_dir($componentsDir . '/' . $dir)) {
                $violations[] = sprintf(
                    'autoload.php 登记了 %s => components/%s，但该目录不存在',
                    $namespace,
                    $dir
                );
            }
        }

        return [
            'id' => 'ARCH-8',
            'ok' => $violations === [],
            'summary' => '组件目录与 autoload 映射表一致',
            'violations' => $violations,
        ];
    }

    private function componentOf(string $use): ?string
    {
        $parts = explode('\\', $use);

        if (count($parts) < 2 || $parts[0] !== 'SunsetReverie') {
            return null;
        }

        // 命名空间段与目录名大小写无关（RateLimit 对应 ratelimit）
        $segment = strtolower($parts[1]);

        return in_array($segment, $this->components, true) ? $segment : null;
    }

    /**
     * 扫描 components/ 得到组件名列表。
     *
     * @return list<string> 组件名
     */
    private function detectComponents(): array
    {
        $directory = $this->frameworkRoot . '/components';

        if (!is_dir($directory)) {
            return [];
        }

        $components = [];

        foreach (glob($directory . '/*', GLOB_ONLYDIR) ?: [] as $path) {
            $components[] = basename($path);
        }

        sort($components);

        return $components;
    }

}
