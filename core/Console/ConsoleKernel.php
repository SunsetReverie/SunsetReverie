<?php

declare(strict_types=1);

namespace SunsetReverie\Console;

use SunsetReverie\Config\Env;
use SunsetReverie\Foundation\Application;

/**
 * CLI 内核：命令分发与输出。
 *
 * 从 Application 中独立出来，使 Application 只负责"引导 + HTTP 分发"。
 * 所有命令通过 $this->app 访问路由器、配置与项目路径，不持有额外状态。
 */
final class ConsoleKernel
{
    /**
     * @param Application $app 已引导的应用
     */
    public function __construct(private readonly Application $app)
    {
    }

    /**
     * 执行命令。
     *
     * @param list<string> $argv 命令行参数
     * @return int 退出码（0 成功 / 1 失败 / 2 用法错误）
     */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $args = array_slice($argv, 2);

        return match ($command) {
            'new' => $this->new($args),
            'serve' => $this->serve($args),
            'route:list' => $this->routeList($args),
            'route:cache' => $this->routeCache(),
            'route:clear' => $this->routeClear(),
            'config:cache' => $this->configCache(),
            'config:clear' => $this->configClear(),
            'key:generate' => $this->keyGenerate($args),
            'env:check' => $this->envCheck(),
            'optimize' => $this->optimize(),
            'optimize:clear' => $this->optimizeClear(),
            'migrate' => $this->migrate(),
            'migrate:rollback' => $this->migrateRollback($args),
            'migrate:status' => $this->migrateStatus(),
            'make:migration' => $this->makeMigration($args),
            'about' => $this->about(),
            'help', '--help', '-h' => $this->help(),
            '--version', '-V' => $this->version(),
            default => $this->unknown($command),
        };
    }

    // ── new ───────────────────────────────────────────────────────────

    /**
     * new 命令：从 skeleton 生成项目骨架。
     *
     * @param list<string> $args 参数（[目录] [--force]）
     * @return int 退出码
     */
    private function new(array $args): int
    {
        $skeleton = $this->app->basePath('skeleton');

        if (!is_dir($skeleton)) {
            $this->error('未找到骨架模板：' . $skeleton);

            return 1;
        }

        $targetArgument = $this->firstArgument($args) ?? '.';
        $current = getcwd() ?: $this->app->basePath();
        $target = $targetArgument === '.'
            ? $current
            : (str_starts_with($targetArgument, '/') ? $targetArgument : $current . '/' . $targetArgument);
        $target = rtrim($target, '/');
        $name = basename($target) ?: 'app';
        $force = in_array('--force', $args, true);

        if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
            $this->error('无法创建目录：' . $target);

            return 1;
        }

        if (!$force && $this->hasBlockingFiles($target)) {
            $this->error('目标目录非空，如需继续请加 --force（已存在的业务文件不会被删除）');

            return 1;
        }

        $frameworkRelative = $this->relativePath($target, $this->app->basePath());
        $copied = $this->copySkeleton($skeleton, $target, $name, $frameworkRelative);
        $this->writeEnvFile($target);
        $this->writeBinShim($target, $frameworkRelative);

        $domains = $this->parseDomains((string) $this->optionValue($args, 'domains', ''));

        if ($domains !== null) {
            if ($domains === []) {
                $this->error('--domains 需要至少一个域名，例如 --domains=admin,web');

                return 2;
            }

            $generated = $this->generateDomainSkeleton($target, $name, $domains);
            $this->line(sprintf('已生成 %d 个 API 域：%s', count($domains), implode(', ', $domains)));
            $this->line(sprintf('  域入口 %d 个、示例控制器 %d 个', $generated, $generated));
        }

        $this->line('项目已生成：' . $target);
        $this->line(sprintf('  骨架文件 %d 个（已跳过 .git / vendor / 框架目录）', $copied));
        $this->line('');
        $this->line('后续步骤：');
        $this->line('  1) cd ' . $target);
        $this->line('  2) php bin/sr serve');
        $this->line('  3) 访问 http://127.0.0.1:8000/');

        return 0;
    }

    /**
     * 解析 --domains 参数。
     *
     * @param string $value 原始值（如 admin,web）
     * @return list<string>|null 域名列表；未传该参数返回 null
     * @throws \RuntimeException 域名非法时抛出
     */
    private function parseDomains(string $value): ?array
    {
        if ($value === '') {
            return null;
        }

        $domains = [];

        foreach (explode(',', $value) as $item) {
            $domain = trim($item);

            if ($domain === '') {
                continue;
            }

            if (preg_match('/^[a-z][a-z0-9-]{1,30}$/', $domain) !== 1) {
                throw new \RuntimeException(sprintf(
                    '非法域名 [%s]：只能包含小写字母、数字与短横线，且以字母开头',
                    $domain
                ));
            }

            $domains[] = $domain;
        }

        return array_values(array_unique($domains));
    }

    /**
     * 生成多域骨架：config/api.php + routes/{domain}.php + 示例控制器。
     *
     * @param string $target 项目目录
     * @param string $appName 项目名
     * @param list<string> $domains 域名列表
     * @return int 生成的域入口数量
     */
    private function generateDomainSkeleton(string $target, string $appName, array $domains): int
    {
        // 单域入口与多域并存会让人分不清，直接移除
        @unlink($target . '/routes/api.php');

        $definitions = [];

        foreach ($domains as $index => $domain) {
            $definitions[] = sprintf(
                "        '%s' => [\n            'prefix' => '%s',\n            'versions' => ['v1'],\n            'error_segment' => %d,\n        ],",
                $domain,
                $domain,
                51 + $index
            );
        }

        $apiConfig = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "/*\n * API 域配置（由 sr new --domains 生成）。\n *\n"
            . " * 每个域一个入口文件 routes/{domain}.php；\n"
            . " * 域前缀、域名前缀与中间件由框架自动套用，入口文件里只写版本分组与资源路由。\n */\n\n"
            . "return [\n"
            . "    'default' => '" . $domains[0] . "',\n"
            . "    'allow_header_detection' => false,\n"
            . "    'routing_mode' => 'single',\n"
            . "    'domains' => [\n"
            . implode("\n", $definitions) . "\n"
            . "    ],\n];\n";

        file_put_contents($target . '/config/api.php', $apiConfig);

        foreach ($domains as $domain) {
            $studly = ucfirst(str_replace('-', '', $domain));

            $routeFile = "<?php\n\ndeclare(strict_types=1);\n\n"
                . "use App\\Api\\{$studly}\\Controller\\HomeController;\n"
                . "use SunsetReverie\\Routing\\Router;\n\n"
                . "/*\n * {$domain} 域路由入口。\n *\n"
                . " * 域前缀（/{$domain}）与域名前缀（{$domain}.）已由框架套用，此处只写版本分组。\n */\n"
                . "return function (Router \$router): void {\n"
                . "    \$router->group(['prefix' => 'v1', 'as' => 'v1.'], static function (Router \$r): void {\n"
                . "        \$r->get('/hello', [HomeController::class, 'index'], 'hello');\n"
                . "    });\n};\n";

            if (!is_dir($target . '/routes')) {
                @mkdir($target . '/routes', 0755, true);
            }

            file_put_contents($target . '/routes/' . $domain . '.php', $routeFile);

            $controllerDir = $target . '/app/Api/' . $studly . '/Controller';

            if (!is_dir($controllerDir)) {
                @mkdir($controllerDir, 0755, true);
            }

            $controller = "<?php\n\ndeclare(strict_types=1);\n\n"
                . "namespace App\\Api\\{$studly}\\Controller;\n\n"
                . "use SunsetReverie\\Http\\Request;\n\n"
                . "/**\n * {$domain} 域示例控制器。\n */\n"
                . "final class HomeController\n{\n"
                . "    /**\n     * 示例接口。\n     *\n     * @param Request \$request 当前请求\n     * @return array<string, mixed> 响应数据\n     */\n"
                . "    public function index(Request \$request): array\n    {\n"
                . "        return ['domain' => '{$domain}', 'app' => '{$appName}'];\n    }\n}\n";

            file_put_contents($controllerDir . '/HomeController.php', $controller);
        }

        return count($domains);
    }

    /**
     * 递归复制骨架并替换占位符。
     *
     * @param string $source 骨架目录
     * @param string $target 目标目录
     * @param string $name 项目名
     * @param string $frameworkRelative 框架相对路径
     * @return int 复制文件数
     */
    private function copySkeleton(string $source, string $target, string $name, string $frameworkRelative): int
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $count = 0;

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $relative = substr($item->getPathname(), strlen($source) + 1);

            if (str_starts_with($relative, '.git/') || str_starts_with($relative, 'vendor/')) {
                continue;
            }

            $destination = $target . '/' . $relative;

            if ($item->isDir()) {
                if (!is_dir($destination)) {
                    @mkdir($destination, 0755, true);
                }

                continue;
            }

            if (!is_dir(dirname($destination))) {
                @mkdir(dirname($destination), 0755, true);
            }

            if (is_file($destination) && !in_array($relative, ['.gitignore', '.env.example'], true)) {
                continue;
            }

            $content = (string) file_get_contents($item->getPathname());
            $content = str_replace(
                ['{{APP_NAME}}', '{{FRAMEWORK_PATH}}'],
                [$name, $frameworkRelative],
                $content
            );

            file_put_contents($destination, $content);
            @chmod($destination, 0644);
            $count++;
        }

        return $count;
    }

    /**
     * 生成 .env（基于 .env.example，自动填入 APP_KEY）。
     *
     * @param string $target 项目目录
     * @return void
     */
    private function writeEnvFile(string $target): void
    {
        $example = $target . '/.env.example';

        if (!is_file($example) || is_file($target . '/.env')) {
            return;
        }

        $content = (string) file_get_contents($example);
        $key = 'base64:' . base64_encode(random_bytes(32));
        $content = (string) preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $content);

        file_put_contents($target . '/.env', $content);
        @chmod($target . '/.env', 0600);
    }

    /**
     * 生成项目 CLI 入口（shim，转发到框架 CLI 并传入项目根）。
     *
     * @param string $target 项目目录
     * @param string $frameworkRelative 框架路径
     * @return void
     */
    private function writeBinShim(string $target, string $frameworkRelative): void
    {
        $directory = $target . '/bin';

        if (!is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        $path = var_export($frameworkRelative, true);

        $shim = "#!/usr/bin/env php\n"
            . "<?php\n\n"
            . "declare(strict_types=1);\n\n"
            . "// 业务项目 CLI 入口：先定位项目根，再交给框架 CLI\n"
            . "\$projectRoot = dirname(__DIR__);\n"
            . "\$framework = {$path};\n\n"
            . "if (!str_starts_with(\$framework, '/') && preg_match('/^[A-Za-z]:/', \$framework) !== 1) {\n"
            . "    \$framework = \$projectRoot . '/' . ltrim(\$framework, '/');\n"
            . "}\n\n"
            . "putenv('SR_BASE_PATH=' . \$projectRoot);\n\n"
            . "require \$framework . '/bin/sr';\n";

        file_put_contents($target . '/bin/sr', $shim);
        @chmod($target . '/bin/sr', 0755);
    }

    // ── serve ─────────────────────────────────────────────────────────

    /**
     * serve 命令：启动 PHP 内置开发服务器。
     *
     * @param list<string> $args 参数
     * @return int 退出码
     */
    private function serve(array $args): int
    {
        $host = $this->optionValue($args, 'host', '127.0.0.1');
        $port = $this->optionValue($args, 'port', '8000');
        $router = $this->writeServeRouter();

        $this->line("SunsetReverie 开发服务器已启动：http://{$host}:{$port}");
        $this->line('健康检查：GET /health');
        $this->line('按 Ctrl+C 停止');

        $command = sprintf(
            '%s -S %s:%s %s',
            escapeshellarg(PHP_BINARY),
            $host,
            $port,
            escapeshellarg($router)
        );

        passthru($command, $exitCode);

        return is_int($exitCode) ? $exitCode : 0;
    }

    /**
     * 生成内置服务器路由脚本。
     *
     * @return string 脚本绝对路径
     */
    private function writeServeRouter(): string
    {
        $directory = $this->compiledPath();

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $path = $directory . '/serve-router.php';
        $basePathVar = var_export($this->app->basePath(), true);

        $code = <<<PHP
<?php

declare(strict_types=1);

// 由 `sr serve` 自动生成，请勿手动修改
\$basePath = {$basePathVar};
\$path = parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// 上传文件在 storage/uploads 下（不在 public 里），需手动映射；
// 生产环境由 Nginx alias 处理，这里只是开发环境的等价实现
if (str_starts_with(\$path, '/uploads/')) {
    \$file = \$basePath . '/storage' . \$path;

    if (is_file(\$file) && !str_contains(\$path, '..')) {
        header('Content-Type: ' . (mime_content_type(\$file) ?: 'application/octet-stream'));
        header('Content-Length: ' . filesize(\$file));
        readfile(\$file);

        return;
    }

    http_response_code(404);

    return;
}

// public 下的静态资源交给内置服务器直接读取
if (is_file(\$basePath . '/public' . \$path) && !str_contains(\$path, '..')) {
    return false;
}

// 业务项目：交给 public/index.php；框架自身：直接自举
if (is_file(\$basePath . '/public/index.php')) {
    require \$basePath . '/public/index.php';

    return;
}

require \$basePath . '/core/Foundation/autoload.php';

\\SunsetReverie\\Foundation\\Application::boot(\$basePath)
    ->handleRequest()
    ->send();

PHP;

        file_put_contents($path, $code);

        return $path;
    }

    // ── route / config ────────────────────────────────────────────────

    /**
     * route:list 命令。
     *
     * @param list<string> $args 参数
     * @return int 退出码
     */
    private function routeList(array $args): int
    {
        $routes = $this->app->router()->routes();
        $domain = $this->optionValue($args, 'domain', '');

        if ($domain !== '') {
            $routes = array_values(array_filter(
                $routes,
                static fn ($route): bool => $route->name !== null
                    && str_starts_with($route->name, $domain . '.')
            ));

            if ($routes === []) {
                $this->error(sprintf('域 [%s] 下没有已注册路由（检查 config/api.php 与 routes/%s.php）', $domain, $domain));

                return 1;
            }
        }

        if (in_array('--json', $args, true)) {
            $rows = array_map(static fn ($route): array => [
                'methods' => implode('|', $route->methods),
                'uri' => $route->uri,
                'name' => $route->name,
                'middleware' => $route->middlewareList(),
            ], $routes);

            $this->line((string) json_encode(['total' => count($rows), 'routes' => $rows], JSON_UNESCAPED_UNICODE));

            return 0;
        }

        $this->line(sprintf('共 %d 条路由%s', count($routes), $domain === '' ? '' : '（域：' . $domain . '）'));

        foreach ($routes as $route) {
            $this->line(sprintf(
                '%-10s %-24s %-24s %s',
                implode('|', $route->methods),
                $route->uri,
                $route->name ?? '-',
                $route->middlewareList() === [] ? '-' : implode(',', $route->middlewareList())
            ));
        }

        return 0;
    }

    /**
     * route:cache 命令：导出路由表。
     *
     * @return int 退出码
     */
    private function routeCache(): int
    {
        try {
            $code = $this->app->router()->export();
        } catch (\Throwable $e) {
            $this->error('路由缓存失败：' . $e->getMessage());

            return 1;
        }

        $file = $this->compiledPath('routes.php');

        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }

        file_put_contents($file, $code);
        $this->line('路由已缓存：' . $file . '（' . count($this->app->router()->routes()) . ' 条）');
        $this->line('提示：路由缓存仅在 APP_ENV=production 时加载');

        return 0;
    }

    /**
     * route:clear 命令：清除路由缓存。
     *
     * @return int 退出码
     */
    private function routeClear(): int
    {
        $file = $this->compiledPath('routes.php');

        if (is_file($file)) {
            unlink($file);
            $this->line('路由缓存已清除');

            return 0;
        }

        $this->line('没有路由缓存');

        return 0;
    }

    /**
     * config:cache 命令：导出配置。
     *
     * @return int 退出码
     */
    private function configCache(): int
    {
        try {
            $code = $this->app->config()->export();
        } catch (\Throwable $e) {
            $this->error('配置缓存失败：' . $e->getMessage());

            return 1;
        }

        $file = $this->compiledPath('config.php');

        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }

        file_put_contents($file, $code);
        $this->line('配置已缓存：' . $file);
        $this->line('提示：配置缓存仅在 APP_ENV=production 时加载');

        return 0;
    }

    /**
     * config:clear 命令：清除配置缓存。
     *
     * @return int 退出码
     */
    private function configClear(): int
    {
        $file = $this->compiledPath('config.php');

        if (is_file($file)) {
            unlink($file);
            $this->line('配置缓存已清除');

            return 0;
        }

        $this->line('没有配置缓存');

        return 0;
    }

    // ── 运维命令 ──────────────────────────────────────────────────────

    /**
     * key:generate：生成 APP_KEY 并写入 .env。
     *
     * @param list<string> $args 参数（--show 只打印不写入）
     * @return int 退出码
     */
    private function keyGenerate(array $args): int
    {
        $key = 'base64:' . base64_encode(random_bytes(32));

        if (in_array('--show', $args, true)) {
            $this->line($key);

            return 0;
        }

        $envFile = $this->app->basePath('.env');

        if (!is_file($envFile)) {
            $example = $this->app->basePath('.env.example');

            if (is_file($example)) {
                copy($example, $envFile);
                $this->line('已从 .env.example 创建 .env');
            } else {
                $this->error('未找到 .env 与 .env.example，请先执行 sr new 或手动创建 .env');

                return 1;
            }
        }

        $content = (string) file_get_contents($envFile);

        if (preg_match('/^APP_KEY=.*$/m', $content) === 1) {
            $content = (string) preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $content);
        } else {
            $content = rtrim($content, "\n") . "\nAPP_KEY=" . $key . "\n";
        }

        if (file_put_contents($envFile, $content) === false) {
            $this->error('.env 写入失败，请检查文件权限');

            return 1;
        }

        @chmod($envFile, 0600);
        $this->line('APP_KEY 已写入 .env');
        $this->line('提示：生产中更换 APP_KEY 会使已加密数据无法解密，请谨慎操作');

        return 0;
    }

    /**
     * env:check：环境自检。
     *
     * @return int 退出码（有 error 返回 1）
     */
    private function envCheck(): int
    {
        $errors = 0;
        $warnings = 0;

        // 1) PHP 版本
        $this->line('环境自检');
        $this->line('');
        $this->line('[PHP]');

        if (version_compare(PHP_VERSION, '8.3.0', '>=')) {
            $this->line('  ✓ 版本 ' . PHP_VERSION);
        } else {
            $this->line('  ✗ 版本 ' . PHP_VERSION . '，要求 >= 8.3.0');
            $errors++;
        }

        // 2) 扩展
        $this->line('');
        $this->line('[扩展]');

        $required = ['json' => 'JSON 编解码', 'mbstring' => '多字节字符串', 'fileinfo' => '上传类型检测', 'pdo' => '数据库'];
        $optional = ['openssl' => '加密/签名', 'gd' => '图片处理', 'zip' => '压缩包处理', 'apcu' => 'APCu 缓存', 'redis' => 'Redis 缓存'];

        foreach ($required as $extension => $purpose) {
            if (extension_loaded($extension)) {
                $this->line(sprintf('  ✓ %-10s %s', $extension, $purpose));
            } else {
                $this->line(sprintf('  ✗ %-10s %s（必需）', $extension, $purpose));
                $errors++;
            }
        }

        foreach ($optional as $extension => $purpose) {
            if (extension_loaded($extension)) {
                $this->line(sprintf('  ✓ %-10s %s', $extension, $purpose));
            } else {
                $this->line(sprintf('  - %-10s %s（可选，未安装）', $extension, $purpose));
                $warnings++;
            }
        }

        // 3) 目录
        $this->line('');
        $this->line('[目录]');

        foreach (['storage/logs', 'storage/cache', 'storage/compiled', 'storage/uploads'] as $directory) {
            $path = $this->app->basePath($directory);

            if (!is_dir($path)) {
                $this->line(sprintf('  - %-20s 不存在（首次写入时自动创建）', $directory));
                $warnings++;

                continue;
            }

            if (is_writable($path)) {
                $this->line(sprintf('  ✓ %-20s 可写', $directory));
            } else {
                $this->line(sprintf('  ✗ %-20s 不可写，请执行 chmod -R 0755 storage', $directory));
                $errors++;
            }
        }

        // 4) 环境变量
        $this->line('');
        $this->line('[环境变量]');

        $env = Env::string('APP_ENV', '');
        $allowedEnvs = ['local', 'test', 'staging', 'production'];

        if (in_array($env, $allowedEnvs, true)) {
            $this->line(sprintf('  ✓ APP_ENV=%s', $env));
        } else {
            $this->line(sprintf('  ✗ APP_ENV=%s，可选值：%s', $env, implode(' / ', $allowedEnvs)));
            $errors++;
        }

        $debug = Env::bool('APP_DEBUG', false);

        if ($debug && in_array($env, ['staging', 'production'], true)) {
            $this->line('  ✗ APP_DEBUG=true 在 ' . $env . ' 环境必须为 false（会泄露堆栈与配置）');
            $errors++;
        } else {
            $this->line('  ✓ APP_DEBUG=' . ($debug ? 'true' : 'false'));
        }

        $key = Env::string('APP_KEY', '');

        if ($key === '') {
            $this->line('  ✗ APP_KEY 为空，请执行 sr key:generate');
            $errors++;
        } elseif (strlen((string) preg_replace('/^base64:/', '', $key)) < 32) {
            $this->line('  ✗ APP_KEY 长度不足（解码后应 ≥32 字节），请重新生成');
            $errors++;
        } else {
            $this->line('  ✓ APP_KEY 已配置');
        }

        $jwtSecret = Env::string('JWT_SECRET', '');

        if ($jwtSecret !== '' && strlen($jwtSecret) < 32) {
            $this->line('  ✗ JWT_SECRET 长度不足 32 字节，Token 可被暴力破解');
            $errors++;
        } elseif ($jwtSecret !== '') {
            $this->line('  ✓ JWT_SECRET 已配置');
        }

        // 5) 汇总
        $this->line('');
        $this->line(sprintf('结果：%d 个错误，%d 个提示', $errors, $warnings));

        if ($errors > 0) {
            $this->error('环境检查未通过，请先修复上述错误');

            return 1;
        }

        $this->line('环境检查通过');

        return 0;
    }

    /**
     * optimize：一键缓存配置与路由。
     *
     * @return int 退出码
     */
    private function optimize(): int
    {
        $routeCode = $this->routeCache();

        if ($routeCode !== 0) {
            return $routeCode;
        }

        $configCode = $this->configCache();

        if ($configCode !== 0) {
            return $configCode;
        }

        $this->line('');
        $this->line('优化完成（缓存仅在 APP_ENV=production 时生效）');

        return 0;
    }

    /**
     * optimize:clear：一键清除缓存。
     *
     * @return int 退出码
     */
    private function optimizeClear(): int
    {
        $this->routeClear();
        $this->configClear();
        $this->line('');
        $this->line('已清除全部缓存');

        return 0;
    }

    // ── migrate ───────────────────────────────────────────────────────

    /**
     * migrate 命令：执行全部未运行的迁移。
     *
     * @return int 退出码
     */
    private function migrate(): int
    {
        try {
            $files = $this->migrator()->migrate();
        } catch (\Throwable $e) {
            $this->error('迁移失败：' . $e->getMessage());

            return 1;
        }

        if ($files === []) {
            $this->line('没有待执行的迁移');

            return 0;
        }

        foreach ($files as $file) {
            $this->line('  ✓ ' . $file);
        }

        $this->line(sprintf('完成：%d 个迁移', count($files)));

        return 0;
    }

    /**
     * migrate:rollback 命令：回滚最近批次。
     *
     * @param list<string> $args 参数（--step=N）
     * @return int 退出码
     */
    private function migrateRollback(array $args): int
    {
        $steps = max(1, (int) $this->optionValue($args, 'step', '1'));

        try {
            $files = $this->migrator()->rollback($steps);
        } catch (\Throwable $e) {
            $this->error('回滚失败：' . $e->getMessage());

            return 1;
        }

        if ($files === []) {
            $this->line('没有可回滚的迁移');

            return 0;
        }

        foreach ($files as $file) {
            $this->line('  ↩ ' . $file);
        }

        $this->line(sprintf('完成：回滚 %d 个迁移', count($files)));

        return 0;
    }

    /**
     * migrate:status 命令：查看迁移状态。
     *
     * @return int 退出码
     */
    private function migrateStatus(): int
    {
        try {
            $status = $this->migrator()->status();
        } catch (\Throwable $e) {
            $this->error('读取迁移状态失败：' . $e->getMessage());

            return 1;
        }

        if ($status['executed'] === [] && $status['pending'] === []) {
            $this->line('迁移目录为空：' . $this->migrationDirectory());

            return 0;
        }

        foreach ($status['executed'] as $file => $batch) {
            $this->line(sprintf('  [已执行 batch %d] %s', $batch, $file));
        }

        foreach ($status['pending'] as $file) {
            $this->line(sprintf('  [待执行]        %s', $file));
        }

        $this->line(sprintf(
            '共 %d 个，已执行 %d，待执行 %d',
            count($status['executed']) + count($status['pending']),
            count($status['executed']),
            count($status['pending'])
        ));

        return 0;
    }

    /**
     * make:migration 命令：生成迁移文件。
     *
     * @param list<string> $args 参数（迁移名）
     * @return int 退出码
     */
    private function makeMigration(array $args): int
    {
        $name = $this->firstArgument($args);

        if ($name === null) {
            $this->error('用法：sr make:migration create_users_table');

            return 2;
        }

        try {
            $file = $this->migrator()->make($name);
        } catch (\Throwable $e) {
            $this->error('生成失败：' . $e->getMessage());

            return 1;
        }

        $this->line('已生成：' . $file);

        return 0;
    }

    /**
     * 构造迁移执行器。
     *
     * @return \SunsetReverie\Database\Migration\Migrator 执行器
     */
    private function migrator(): \SunsetReverie\Database\Migration\Migrator
    {
        return new \SunsetReverie\Database\Migration\Migrator(
            $this->app->db(),
            $this->migrationDirectory(),
        );
    }

    /**
     * 解析迁移目录（配置优先，默认 database/migrations）。
     *
     * @return string 绝对路径
     */
    private function migrationDirectory(): string
    {
        $configured = (string) ($this->app->config()->get('database.migrations') ?? 'database/migrations');

        if (str_starts_with($configured, '/') || preg_match('/^[A-Za-z]:/', $configured) === 1) {
            return rtrim($configured, '/');
        }

        return rtrim($this->app->basePath($configured), '/');
    }

    // ── 信息类 ────────────────────────────────────────────────────────

    /**
     * about 命令：输出运行环境与框架信息。
     *
     * @return int 退出码
     */
    private function about(): int
    {
        $this->line('SunsetReverie Framework');
        $this->line('  版本      : ' . Application::VERSION);
        $this->line('  PHP       : ' . PHP_VERSION);
        $this->line('  环境      : ' . Env::string('APP_ENV', 'local') . (Env::bool('APP_DEBUG') ? '（debug）' : ''));
        $this->line('  根目录    : ' . $this->app->basePath());
        $this->line('  配置项    : ' . count($this->app->config()->all()) . ' 组');
        $this->line('  全局中间件: ' . count($this->app->globalMiddleware()));
        $this->line('  路由数量  : ' . count($this->app->router()->routes()));
        $this->line('  加载文件  : ' . count(get_included_files()));
        $this->line('  峰值内存  : ' . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB');

        return 0;
    }

    /**
     * help 命令。
     *
     * @return int 退出码
     */
    private function help(): int
    {
        $this->line('SunsetReverie ' . Application::VERSION . ' 命令行');
        $this->line('');
        $this->line('用法：sr <command> [options]');
        $this->line('');
        $this->line('可用命令：');
        $this->line('  new           生成项目骨架（[目录] [--force] [--domains=a,b]）');
        $this->line('  serve         启动本地开发服务器（--host= --port=）');
        $this->line('  route:list    列出已注册路由（--json / --domain=admin）');
        $this->line('  route:cache   导出路由缓存');
        $this->line('  route:clear   清除路由缓存');
        $this->line('  config:cache  导出配置缓存');
        $this->line('  config:clear  清除配置缓存');
        $this->line('  key:generate  生成 APP_KEY 并写入 .env（--show 只打印）');
        $this->line('  env:check     环境自检（PHP/扩展/目录/密钥/调试开关）');
        $this->line('  optimize      一键缓存（配置 + 路由）');
        $this->line('  optimize:clear 一键清除缓存');
        $this->line('  migrate       执行未运行的迁移');
        $this->line('  migrate:rollback [--step=1]  回滚最近批次');
        $this->line('  migrate:status 查看迁移状态');
        $this->line('  make:migration {name}        生成迁移文件');
        $this->line('  about         显示环境与框架信息');
        $this->line('  help          显示本帮助');

        return 0;
    }

    /**
     * version 命令。
     *
     * @return int 退出码
     */
    private function version(): int
    {
        $this->line(Application::VERSION);

        return 0;
    }

    /**
     * 未知命令。
     *
     * @param string $command 命令名
     * @return int 退出码（2 表示用法错误）
     */
    private function unknown(string $command): int
    {
        $this->error(sprintf('未知命令：%s（执行 sr help 查看可用命令）', $command));

        return 2;
    }

    // ── 通用辅助 ──────────────────────────────────────────────────────

    /**
     * 编译缓存目录。
     *
     * @param string $file 可选文件名
     * @return string 绝对路径
     */
    private function compiledPath(string $file = ''): string
    {
        $directory = $this->app->basePath('storage/compiled');

        return $file === '' ? $directory : $directory . '/' . $file;
    }

    /**
     * 判断目标目录是否存在"阻断性"文件。
     *
     * @param string $path 目录
     * @return bool 存在阻断文件返回 true
     */
    private function hasBlockingFiles(string $path): bool
    {
        $items = @scandir($path);

        if ($items === false) {
            return false;
        }

        $allowed = [
            '.', '..', '.git', 'vendor', '.DS_Store', '.gitmodules',
            'sunsetreverie', 'bin', 'app', 'config', 'routes', 'public',
            'storage', 'tests', '.env', '.env.example', '.gitignore',
        ];

        foreach ($items as $item) {
            if (!in_array($item, $allowed, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 计算路径相对基准目录的表示（不在其下则返回绝对路径）。
     *
     * @param string $base 基准目录
     * @param string $path 目标路径
     * @return string 相对或绝对路径
     */
    private function relativePath(string $base, string $path): string
    {
        $base = rtrim($base, '/');

        if ($path === $base) {
            return '.';
        }

        if (str_starts_with($path, $base . '/')) {
            return substr($path, strlen($base) + 1);
        }

        return $path;
    }

    /**
     * 取第一个非选项参数。
     *
     * @param list<string> $args 参数
     * @return string|null 参数值
     */
    private function firstArgument(array $args): ?string
    {
        foreach ($args as $arg) {
            if (!str_starts_with($arg, '-')) {
                return $arg;
            }
        }

        return null;
    }

    /**
     * 判断是否传入了某个选项（--name 或 --name=value）。
     *
     * @param list<string> $args 参数
     * @param string $name 选项名
     * @return bool 存在返回 true
     */
    private function hasOption(array $args, string $name): bool
    {
        foreach ($args as $arg) {
            if ($arg === '--' . $name || str_starts_with($arg, '--' . $name . '=')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 读取 --key=value 形式的选项。
     *
     * @param list<string> $args 参数
     * @param string $name 选项名（不含 --）
     * @param string $default 默认值
     * @return string 选项值
     */
    private function optionValue(array $args, string $name, string $default): string
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--' . $name . '=')) {
                return substr($arg, strlen($name) + 3);
            }
        }

        return $default;
    }

    /**
     * 输出一行到 stdout。
     *
     * @param string $message 内容
     * @return void
     */
    private function line(string $message): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }

    /**
     * 输出一行到 stderr。
     *
     * @param string $message 内容
     * @return void
     */
    private function error(string $message): void
    {
        fwrite(STDERR, $message . PHP_EOL);
    }
}
