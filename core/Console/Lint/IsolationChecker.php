<?php

declare(strict_types=1);

namespace SunsetReverie\Console\Lint;

/**
 * 域隔离检查：守住"四端互不引用、领域层不依赖接口层"这两条线。
 *
 * 规则（对应规划文档 16.3.2 ISOLATION-1～3）：
 *   ISO-1  app/Api/{A} 不得引用 app/Api/{B}
 *   ISO-2  app/Domain 不得引用 app/Api
 *   ISO-3  app/Common 不得引用 app/Api 或 app/Domain
 *
 * 为什么值得做成命令：域隔离靠 Code Review 守不住——
 * 一个 `use App\Api\Admin\Service\X` 就能让 web 域偷偷依赖后台逻辑，
 * 而这类耦合平时跑得好好的，直到下线某个域时才炸。
 *
 * 与 lint:architecture 一样，用 token_get_all 解析而非正则。
 */
final class IsolationChecker
{
    /** 接口层目录（相对于 app/） */
    private const API_DIRECTORY = 'Api';

    /** 领域层目录 */
    private const DOMAIN_DIRECTORY = 'Domain';

    /** 公共层目录 */
    private const COMMON_DIRECTORY = 'Common';

    /** @var list<string> 已识别的域名 */
    private array $domains = [];

    /**
     * @param string $appPath 业务项目 app/ 目录绝对路径
     */
    public function __construct(private readonly string $appPath)
    {
        $this->domains = $this->detectDomains();
    }

    /**
     * 执行检查。
     *
     * @return list<array{id: string, ok: bool, summary: string, violations: list<string>}> 结果
     */
    public function run(): array
    {
        return [
            $this->checkCrossDomain(),
            $this->checkDomainDependsOnApi(),
            $this->checkCommonDependsOnUpper(),
        ];
    }

    /**
     * 已识别的域名列表（诊断用）。
     *
     * @return list<string> 域名
     */
    public function domains(): array
    {
        return $this->domains;
    }

    /**
     * ISO-1：一个域不得引用另一个域。
     *
     * @return array{id: string, ok: bool, summary: string, violations: list<string>} 结果
     */
    private function checkCrossDomain(): array
    {
        $violations = [];

        foreach ($this->domains as $domain) {
            $directory = $this->appPath . '/' . self::API_DIRECTORY . '/' . $domain;

            foreach ($this->phpFiles($directory) as $relative => $file) {
                foreach ($this->usesOf($file) as $use) {
                    $target = $this->domainOf($use);

                    if ($target !== null && $target !== $domain) {
                        $violations[] = sprintf('%s 引用了其他域 %s', $relative, $use);
                    }
                }
            }
        }

        return [
            'id' => 'ISO-1',
            'ok' => $violations === [],
            'summary' => sprintf('域之间互不引用（共 %d 个域）', count($this->domains)),
            'violations' => $violations,
        ];
    }

    /**
     * ISO-2：领域层不得依赖接口层。
     *
     * @return array{id: string, ok: bool, summary: string, violations: list<string>} 结果
     */
    private function checkDomainDependsOnApi(): array
    {
        $violations = [];

        foreach ($this->phpFiles($this->appPath . '/' . self::DOMAIN_DIRECTORY) as $relative => $file) {
            foreach ($this->usesOf($file) as $use) {
                if (str_starts_with($use, 'App\\' . self::API_DIRECTORY . '\\')) {
                    $violations[] = sprintf('%s 引用了接口层 %s（领域层应保持与端无关）', $relative, $use);
                }
            }
        }

        return [
            'id' => 'ISO-2',
            'ok' => $violations === [],
            'summary' => '领域层不依赖接口层',
            'violations' => $violations,
        ];
    }

    /**
     * ISO-3：公共层不得依赖领域层或接口层。
     *
     * @return array{id: string, ok: bool, summary: string, violations: list<string>} 结果
     */
    private function checkCommonDependsOnUpper(): array
    {
        $violations = [];

        foreach ($this->phpFiles($this->appPath . '/' . self::COMMON_DIRECTORY) as $relative => $file) {
            foreach ($this->usesOf($file) as $use) {
                $upper = 'App\\' . self::API_DIRECTORY . '\\';
                $domain = 'App\\' . self::DOMAIN_DIRECTORY . '\\';

                if (str_starts_with($use, $upper) || str_starts_with($use, $domain)) {
                    $violations[] = sprintf('%s 引用了上层 %s', $relative, $use);
                }
            }
        }

        return [
            'id' => 'ISO-3',
            'ok' => $violations === [],
            'summary' => '公共层不依赖领域层与接口层',
            'violations' => $violations,
        ];
    }

    /**
     * 扫描 app/Api 下的一级目录作为域名。
     *
     * @return list<string> 域名
     */
    private function detectDomains(): array
    {
        $directory = $this->appPath . '/' . self::API_DIRECTORY;

        if (!is_dir($directory)) {
            return [];
        }

        $domains = [];

        foreach (glob($directory . '/*', GLOB_ONLYDIR) ?: [] as $path) {
            $domains[] = basename($path);
        }

        sort($domains);

        return $domains;
    }

    /**
     * 判断某个类名属于哪个域。
     *
     * @param string $use 完整类名
     * @return string|null 域名
     */
    private function domainOf(string $use): ?string
    {
        $prefix = 'App\\' . self::API_DIRECTORY . '\\';

        if (!str_starts_with($use, $prefix)) {
            return null;
        }

        $rest = substr($use, strlen($prefix));
        $segment = explode('\\', $rest)[0];

        return in_array($segment, $this->domains, true) ? $segment : null;
    }

    /**
     * 递归扫描 PHP 文件。
     *
     * @param string $directory 目录
     * @return array<string, string> 相对路径 => 绝对路径
     */
    private function phpFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $absolute = $file->getPathname();
            $files[ltrim(substr($absolute, strlen(dirname($this->appPath))), '/')] = $absolute;
        }

        ksort($files);

        return $files;
    }

    /**
     * 用 token_get_all 提取 use 语句。
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

            $next = $index + 1;

            while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                $next++;
            }

            // 跳过闭包 use (...) 与 trait use
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
}
