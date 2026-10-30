<?php

declare(strict_types=1);

namespace SunsetReverie\Console;

use SunsetReverie\Config\Env;
use SunsetReverie\Api\DomainRegistry;
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
            'lint:architecture' => $this->lintArchitecture($args),
            'lint:isolation' => $this->lintIsolation($args),
            'optimize' => $this->optimize(),
            'optimize:clear' => $this->optimizeClear(),
            'migrate' => $this->migrate(),
            'migrate:rollback' => $this->migrateRollback($args),
            'migrate:status' => $this->migrateStatus(),
            'migrate:fresh' => $this->migrateFresh($args),
            'db:show' => $this->dbShow($args),
            'route:mode' => $this->routeMode($args),
            'schedule:run' => $this->scheduleRun(),
            'schedule:list' => $this->scheduleList(),
            'queue:work' => $this->queueWork($args),
            'queue:size' => $this->queueSize($args),
            'queue:clear' => $this->queueClear($args),
            'queue:failed' => $this->queueFailed(),
            'queue:retry' => $this->queueRetry($args),
            'make:migration' => $this->makeMigration($args),
            'make:controller', 'make:model', 'make:middleware', 'make:request',
            'make:seeder', 'make:domain' => $this->make($command, $args),
            'jwt:secret' => $this->jwtSecret($args),
            'cache:clear' => $this->cacheClear(),
            'domain:list' => $this->domainList($args),
            'db:seed' => $this->dbSeed($args),
            'upload:prune' => $this->uploadPrune($args),
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
        // 单域骨架残留物：路由入口与示例控制器都会变成死文件，一并移除
        @unlink($target . '/routes/api.php');
        @unlink($target . '/app/Controller/HomeController.php');
        @rmdir($target . '/app/Controller');

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

    // ── lint ──────────────────────────────────────────────────────────

    /**
     * make:* 生成命令（委托给 MakeCommands）。
     *
     * @param string $command 命令名
     * @param list<string> $args 参数
     * @return int 退出码
     */
    private function make(string $command, array $args): int
    {
        return (new MakeCommands($this->app))->run(substr($command, 5), $args);
    }

    /**
     * lint:architecture：架构检查。
     *
     * @param list<string> $args 参数（--json 机器可读）
     * @return int 退出码（有违规返回 1）
     */
    private function lintArchitecture(array $args): int
    {
        /*
         * 必须传**框架根**而不是项目根：架构检查扫描的是框架自身的
         * core/ 与 components/。传 basePath() 时：
         *   - 在框架仓库里跑，两者相同，看不出问题
         *   - 在业务项目里跑（bin/sr 是 shim），basePath 是项目根，
         *     于是找不到 core/Foundation/autoload.php，ARCH-8 直接抛 warning
         *
         * __DIR__ 在 shim 场景下仍然指向框架目录，用它推导最可靠。
         */
        $checker = new \SunsetReverie\Console\Lint\ArchitectureChecker(dirname(__DIR__, 2));
        $results = $checker->run();
        $failed = 0;

        if (in_array('--json', $args, true)) {
            $payload = array_map(static fn (array $result): array => [
                'id' => $result['id'],
                'ok' => $result['ok'],
                'summary' => $result['summary'],
                'violations' => $result['violations'],
            ], $results);

            $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE));

            foreach ($results as $result) {
                if (!$result['ok']) {
                    $failed++;
                }
            }

            return $failed === 0 ? 0 : 1;
        }

        $this->line('架构检查');

        foreach ($results as $result) {
            if ($result['ok']) {
                $this->line(sprintf('  %-7s OK   %s', $result['id'], $result['summary']));

                continue;
            }

            $failed++;
            $this->line(sprintf('  %-7s FAIL %s', $result['id'], $result['summary']));

            foreach ($result['violations'] as $violation) {
                $this->line('             ' . $violation);
            }
        }

        $this->line('');
        $this->line($failed === 0 ? '全部通过' : sprintf('%d 项未通过', $failed));

        return $failed === 0 ? 0 : 1;
    }

    /**
     * jwt:secret：生成 JWT 密钥写入 .env。
     *
     * @param list<string> $args 参数（--show）
     * @return int 退出码
     */
    private function jwtSecret(array $args): int
    {
        $secret = bin2hex(random_bytes(32));

        if (in_array('--show', $args, true)) {
            $this->line($secret);

            return 0;
        }

        $envFile = $this->app->basePath('.env');

        if (!is_file($envFile)) {
            $this->error('未找到 .env，请先执行 sr key:generate 或手动创建');

            return 1;
        }

        $content = (string) file_get_contents($envFile);

        if (preg_match('/^JWT_SECRET=.*$/m', $content) === 1) {
            $content = (string) preg_replace('/^JWT_SECRET=.*$/m', 'JWT_SECRET=' . $secret, $content);
        } else {
            $content = rtrim($content, "\n") . "\nJWT_SECRET=" . $secret . "\n";
        }

        if (file_put_contents($envFile, $content) === false) {
            $this->error('.env 写入失败，请检查文件权限');

            return 1;
        }

        $this->line('JWT_SECRET 已写入 .env（变更后所有已签发 Token 立即失效）');

        return 0;
    }

    /**
     * cache:clear：清空默认缓存驱动。
     *
     * @return int 退出码
     */
    private function cacheClear(): int
    {
        $driver = (string) $this->app->config()->get(
            'cache.stores.' . ($this->app->config()->get('cache.default', 'file')) . '.driver',
            'file'
        );

        if ($driver === 'apcu') {
            $this->line('提示：APCu 的 CLI 与 FPM 使用不同内存段，');
            $this->line('      本命令只能清空 CLI 侧的缓存，FPM 已写入的条目需等 TTL 过期或重启 PHP-FPM。');
        }

        try {
            $this->app->cache()->flush();
        } catch (\Throwable $e) {
            $this->error('清空缓存失败：' . $e->getMessage());

            return 1;
        }

        $this->line('缓存已清空');

        return 0;
    }

    /**
     * domain:list：列出已配置的 API 域。
     *
     * @param list<string> $args 参数（--json）
     * @return int 退出码
     */
    private function domainList(array $args): int
    {
        $registry = $this->app->container()->get(\SunsetReverie\Api\DomainRegistry::class);

        if ($registry->isEmpty()) {
            $this->line('未配置任何 API 域（单域模式，路由写在 routes/api.php）');

            return 0;
        }

        if (in_array('--json', $args, true)) {
            $rows = [];

            foreach ($registry->all() as $domain) {
                $rows[] = [
                    'name' => $domain->name,
                    'prefix' => $domain->prefix,
                    'hosts' => $domain->hosts,
                    'versions' => $domain->versions,
                    'middleware' => $domain->middleware,
                    'guard' => $domain->guard,
                    'error_segment' => $domain->errorSegment,
                ];
            }

            $this->line((string) json_encode($rows, JSON_UNESCAPED_UNICODE));

            return 0;
        }

        $this->line(sprintf('共 %d 个域（默认：%s）', count($registry->all()), $registry->defaultName()));

        foreach ($registry->all() as $domain) {
            $this->line(sprintf(
                '  %-10s prefix=/%-10s versions=%-10s 中间件 %d 个%s',
                $domain->name,
                $domain->prefix,
                $domain->versions === [] ? '-' : implode(',', $domain->versions),
                count($domain->middleware),
                $domain->errorSegment === null ? '' : '  错误码段位 ' . $domain->errorSegment
            ));
        }

        return 0;
    }

    /**
     * db:seed：执行示例数据脚本。
     *
     * @param list<string> $args 参数（可选脚本名）
     * @return int 退出码
     */
    private function dbSeed(array $args): int
    {
        $name = $this->firstArgument($args) ?? 'seed';
        $file = $this->app->basePath('scripts/' . $name . '.php');

        if (!is_file($file)) {
            $this->error('种子脚本不存在：scripts/' . $name . '.php');

            return 1;
        }

        $this->line('执行 scripts/' . $name . '.php');
        $script = $file;

        // 交给子进程执行，避免污染当前进程的容器与环境变量
        $command = sprintf('%s %s', escapeshellarg(PHP_BINARY), escapeshellarg($script));
        passthru($command, $exitCode);

        return is_int($exitCode) ? $exitCode : 0;
    }

    /**
     * upload:prune：清理过期的分片上传会话。
     *
     * @param list<string> $args 参数
     * @return int 退出码
     */
    private function uploadPrune(array $args): int
    {
        try {
            $manager = $this->app->container()->get(\SunsetReverie\Upload\Chunk\ChunkedUploadManager::class);
            $count = $manager->prune();
        } catch (\Throwable $e) {
            $this->error('清理失败：' . $e->getMessage());

            return 1;
        }

        $this->line($count === 0 ? '没有过期的上传会话' : sprintf('已清理 %d 个过期会话', $count));

        return 0;
    }

    /**
     * lint:isolation：域隔离检查（针对业务项目的 app/ 目录）。
     *
     * @param list<string> $args 参数（--json）
     * @return int 退出码（有违规返回 1）
     */
    private function lintIsolation(array $args): int
    {
        $appPath = $this->app->basePath('app');

        if (!is_dir($appPath)) {
            $this->error('未找到 app/ 目录：lint:isolation 需要在业务项目中执行');

            return 1;
        }

        $checker = new \SunsetReverie\Console\Lint\IsolationChecker($appPath);
        $results = $checker->run();
        $failed = 0;

        if (in_array('--json', $args, true)) {
            $this->line((string) json_encode($results, JSON_UNESCAPED_UNICODE));

            foreach ($results as $result) {
                $failed += $result['ok'] ? 0 : 1;
            }

            return $failed === 0 ? 0 : 1;
        }

        $this->line('域隔离检查（app/）');

        if ($checker->domains() === []) {
            $this->line('  - 未在 app/Api 下发现任何域，跳过');
        } else {
            $this->line('  域：' . implode('、', $checker->domains()));
        }

        foreach ($results as $result) {
            if ($result['ok']) {
                $this->line(sprintf('  %-6s OK   %s', $result['id'], $result['summary']));

                continue;
            }

            $failed++;
            $this->line(sprintf('  %-6s FAIL %s', $result['id'], $result['summary']));

            foreach ($result['violations'] as $violation) {
                $this->line('             ' . $violation);
            }
        }

        $this->line('');
        $this->line($failed === 0 ? '全部通过' : sprintf('%d 项未通过', $failed));

        return $failed === 0 ? 0 : 1;
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
     * migrate:fresh：回滚全部批次后重新迁移（危险操作）。
     *
     * @param list<string> $args 参数（--force）
     * @return int 退出码
     */
    private function migrateFresh(array $args): int
    {
        if (!in_array('--force', $args, true)) {
            $this->error('migrate:fresh 会回滚全部迁移（数据会丢失），确认请加 --force');

            return 2;
        }

        try {
            $migrator = $this->migrator();
            $status = $migrator->status();
            $batches = $status['executed'] === [] ? 0 : count(array_unique(array_values($status['executed'])));

            if ($batches > 0) {
                $rolled = $migrator->rollback($batches);
                $this->line(sprintf('已回滚 %d 个迁移', count($rolled)));
            }

            $files = $migrator->migrate();
        } catch (\Throwable $e) {
            $this->error('重建失败：' . $e->getMessage());

            return 1;
        }

        foreach ($files as $file) {
            $this->line('  ✓ ' . $file);
        }

        $this->line(sprintf('重建完成：%d 个迁移', count($files)));

        return 0;
    }

    /**
     * db:show：连接信息与表概览。
     *
     * @param list<string> $args 参数
     * @return int 退出码
     */
    private function dbShow(array $args): int
    {
        try {
            $db = $this->app->db();
            $driver = $db->driver();

            $this->line('数据库连接');
            $this->line('  驱动      : ' . $driver);
            $this->line('  当前库    : ' . (string) ($db->scalar($driver === 'sqlite' ? 'PRAGMA database_list' : 'SELECT DATABASE()') ?: '-'));

            $tables = $driver === 'sqlite'
                ? $db->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
                : $db->select('SHOW TABLES');

            $names = [];

            foreach ($tables as $row) {
                $names[] = (string) array_values($row)[0];
            }
        } catch (\Throwable $e) {
            $this->error('读取数据库信息失败：' . $e->getMessage());

            return 1;
        }

        $this->line('');
        $this->line(sprintf('共 %d 张表', count($names)));

        foreach ($names as $name) {
            try {
                $count = $this->app->db()->table($name)->count();
                $this->line(sprintf('  %-24s %d 行', $name, $count));
            } catch (\Throwable) {
                $this->line(sprintf('  %-24s ?', $name));
            }
        }

        return 0;
    }

    /**
     * 取队列驱动，失败时给出可操作提示。
     *
     * database 驱动依赖数据库；框架仓库自身没有 config/database.php，
     * 直接抛异常会让人摸不着头脑（"未找到数据库连接配置 [mysql]"）。
     *
     * @return \SunsetReverie\Queue\Driver\QueueDriverInterface|null 驱动，不可用时 null
     */
    private function queueDriver(): ?\SunsetReverie\Queue\Driver\QueueDriverInterface
    {
        try {
            return $this->app->queue()->driver();
        } catch (\Throwable $e) {
            $this->error('队列不可用：' . $e->getMessage());
            $this->line('');
            $this->line('database 驱动需要先完成两件事：');
            $this->line('  1. 在 config/database.php 中配置连接');
            $this->line('  2. 执行 sr migrate 建 jobs / failed_jobs 表');
            $this->line('（或把 queue.driver 改为 sync 仅作本地调试）');

            return null;
        }
    }

    /**
     * queue:work：消费队列。
     *
     * 两种模式：
     * - `--once`：处理到队列为空就退出，适合 cron 驱动（每分钟跑一次）
     * - 不带 `--once`：常驻循环，适合 supervisor 守护
     *
     * @param list<string> $args 参数
     * @return int 退出码
     */
    private function queueWork(array $args): int
    {
        $queue = $this->optionValue($args, 'queue', '') ?: $this->app->queue()->defaultQueue();
        $once = in_array('--once', $args, true);
        $max = (int) ($this->optionValue($args, 'max', '0') ?: 0);

        $driver = $this->queueDriver();

        if ($driver === null) {
            return 1;
        }

        $worker = new \SunsetReverie\Queue\Worker($driver, $queue);

        if ($once) {
            $log = $worker->workUntilEmpty($max);

            if ($log === []) {
                $this->line(sprintf('队列 [%s] 为空', $queue));

                return 0;
            }

            foreach ($log as $line) {
                $this->line('  ' . $line);
            }

            $this->line('');
            $this->line(sprintf('处理完成：%d 个任务', count($log)));

            return 0;
        }

        $this->line(sprintf('开始消费队列 [%s]（Ctrl+C 退出）', $queue));

        // 节奏由 Worker 管（sleepSeconds），这里只管输出
        foreach ($worker->work($max) as $line) {
            $this->line('  ' . $line);
        }

        return 0;
    }

    /**
     * queue:size：队列长度。
     *
     * @param list<string> $args 参数
     * @return int 退出码
     */
    private function queueSize(array $args): int
    {
        $queue = $this->optionValue($args, 'queue', '') ?: $this->app->queue()->defaultQueue();

        $driver = $this->queueDriver();

        if ($driver === null) {
            return 1;
        }

        $this->line(sprintf('队列 [%s]：%d 个待处理任务', $queue, $driver->size($queue)));

        return 0;
    }

    /**
     * queue:clear：清空队列（不含死信）。
     *
     * @param list<string> $args 参数
     * @return int 退出码
     */
    private function queueClear(array $args): int
    {
        $queue = $this->optionValue($args, 'queue', '') ?: $this->app->queue()->defaultQueue();
        $driver = $this->queueDriver();

        if ($driver === null) {
            return 1;
        }

        $deleted = $driver->clear($queue);

        $this->line(sprintf('队列 [%s] 已清空：%d 个任务', $queue, $deleted));

        return 0;
    }

    /**
     * queue:failed：查看死信。
     *
     * @return int 退出码
     */
    private function queueFailed(): int
    {
        $driver = $this->queueDriver();

        if (!$driver instanceof \SunsetReverie\Queue\Driver\DatabaseDriver) {
            $this->error('只有 database 驱动有死信表');

            return 1;
        }

        $rows = $driver->failed(20);

        if ($rows === []) {
            $this->line('没有死信任务');

            return 0;
        }

        $this->line('死信任务');
        $this->line('');

        foreach ($rows as $row) {
            $payload = json_decode((string) $row['payload'], true);
            $class = is_array($payload) ? (string) ($payload['class'] ?? '?') : '?';

            $this->line(sprintf('  #%s  %s', (string) $row['id'], $class));
            $this->line(sprintf('      队列：%s  时间：%s', (string) $row['queue'], (string) $row['failed_at']));
            $this->line(sprintf('      原因：%s', (string) $row['exception']));
            $this->line('');
        }

        return 0;
    }

    /**
     * queue:retry：死信重新入队。
     *
     * @param list<string> $args 参数
     * @return int 退出码
     */
    private function queueRetry(array $args): int
    {
        $driver = $this->queueDriver();

        if (!$driver instanceof \SunsetReverie\Queue\Driver\DatabaseDriver) {
            $this->error('只有 database 驱动支持重试');

            return 1;
        }

        if (in_array('--all', $args, true)) {
            $count = 0;

            foreach ($driver->failed(500) as $row) {
                if ($driver->retryFailed((string) $row['id'])) {
                    $count++;
                }
            }

            $this->line(sprintf('已重新入队：%d 个任务', $count));

            return 0;
        }

        $id = $this->firstArgument($args);

        if ($id === null) {
            $this->error('用法：sr queue:retry {id} 或 sr queue:retry --all');

            return 2;
        }

        if (!$driver->retryFailed($id)) {
            $this->error(sprintf('死信任务 #%s 不存在或载荷损坏', $id));

            return 1;
        }

        $this->line(sprintf('死信任务 #%s 已重新入队', $id));

        return 0;
    }

    /**
     * schedule:run：执行本分钟到期的任务。
     *
     * 由系统 cron 每分钟调用一次：
     *
     *   * * * * * cd /path/to/project && php bin/sr schedule:run >> /dev/null 2>&1
     *
     * 两道锁都必要：
     * - 分钟级 guard：cron 抖动或手工重复执行时，同一分钟不跑第二次
     * - 任务级 lock：某个任务跑得比间隔还慢时，不叠加重入
     *
     * @return int 退出码（有任务失败返回 1）
     */
    private function scheduleRun(): int
    {
        $now = new \DateTimeImmutable();
        $tasks = $this->loadSchedule();
        $due = [];

        foreach ($tasks as $task) {
            if (Schedule::isDue($task, $now)) {
                $due[] = $task;
            }
        }

        if ($due === []) {
            $this->line(sprintf('[%s] 本分钟无到期任务', $now->format('Y-m-d H:i:s')));

            return 0;
        }

        /** @var \SunsetReverie\Cache\CacheStoreInterface $cache */
        $cache = $this->app->cache();

        // 分钟级防重
        $guard = 'schedule:guard:' . $now->format('YmdHi');

        if ($cache->has($guard)) {
            $this->line('本分钟已执行过，跳过');

            return 0;
        }

        $cache->put($guard, 1, 120);

        $failed = 0;

        foreach ($due as $task) {
            $lockKey = 'schedule:lock:' . $task['name'];

            // 任务级防重入：上一轮还没跑完就不再叠加
            if ($cache->has($lockKey)) {
                $this->line(sprintf('  - %-34s 跳过（上次仍在执行）', $task['name']));

                continue;
            }

            $cache->put($lockKey, 1, 300);
            $started = microtime(true);

            try {
                ($task['callback'])();
                $this->line(sprintf(
                    '  ✓ %-34s %7.1f ms',
                    $task['name'],
                    (microtime(true) - $started) * 1000
                ));
            } catch (\Throwable $e) {
                $failed++;
                $this->error(sprintf('  ✗ %-34s %s', $task['name'], $e->getMessage()));
            } finally {
                $cache->forget($lockKey);
            }
        }

        $this->line('');
        $this->line($failed === 0 ? '全部执行完成' : sprintf('%d 个任务失败', $failed));

        return $failed === 0 ? 0 : 1;
    }

    /**
     * schedule:list：列出全部定时任务。
     *
     * @return int 退出码
     */
    private function scheduleList(): int
    {
        $tasks = $this->loadSchedule();

        if ($tasks === []) {
            $this->line('未注册任何定时任务（在 app/Console/Schedule.php 中注册）');

            return 0;
        }

        $now = new \DateTimeImmutable();

        $this->line('定时任务');
        $this->line(sprintf('  当前时间：%s', $now->format('Y-m-d H:i:s')));
        $this->line('');

        foreach ($tasks as $task) {
            $this->line(sprintf(
                '  %-34s %-14s %s',
                $task['name'],
                $task['cron'],
                Schedule::isDue($task, $now) ? '本分钟到期' : ''
            ));
        }

        return 0;
    }

    /**
     * 加载业务侧的任务注册文件。
     *
     * @return list<array{name: string, cron: string, callback: \Closure}> 任务列表
     */
    private function loadSchedule(): array
    {
        $file = $this->app->basePath('app/Console/Schedule.php');

        if (!is_file($file)) {
            return [];
        }

        $registrar = require $file;
        $schedule = new Schedule();

        if (is_callable($registrar)) {
            $registrar($schedule);
        }

        return $schedule->tasks();
    }

    /**
     * route:mode：切换路由组织模式。
     *
     * @param list<string> $args 参数（single|directory，--dry-run）
     * @return int 退出码
     */
    private function routeMode(array $args): int
    {
        $mode = $this->firstArgument($args);
        $dryRun = in_array('--dry-run', $args, true);
        $file = $this->app->basePath('config/api.php');

        if (!is_file($file)) {
            $this->error('未找到 config/api.php，请先执行 sr new 或手动创建');

            return 1;
        }

        if ($mode === null) {
            $current = (string) ($this->app->config()->get('api.routing_mode') ?? 'single');
            $this->line('当前路由模式：' . $current);
            $this->line('用法：sr route:mode single|directory [--dry-run]');

            return 2;
        }

        if (!in_array($mode, ['single', 'directory'], true)) {
            $this->error('模式只能是 single 或 directory');

            return 2;
        }

        $content = (string) file_get_contents($file);

        if (preg_match("/'routing_mode'\s*=>\s*'([a-z]+)'/", $content, $matches) !== 1) {
            $this->error('config/api.php 中未找到 routing_mode 配置项');

            return 1;
        }

        if ($matches[1] === $mode) {
            $this->line('当前已是 ' . $mode . ' 模式，无需修改');

            return 0;
        }

        if ($dryRun) {
            $this->line(sprintf('将把 routing_mode 从 %s 改为 %s（--dry-run，未写入）', $matches[1], $mode));

            return 0;
        }

        $updated = (string) preg_replace(
            "/'routing_mode'\s*=>\s*'[a-z]+'/",
            "'routing_mode' => '" . $mode . "'",
            $content,
            1
        );

        if (file_put_contents($file, $updated) === false) {
            $this->error('写入失败：' . $file);

            return 1;
        }

        $this->line(sprintf('路由模式已切换：%s → %s', $matches[1], $mode));

        if ($mode === 'directory') {
            $this->line('提示：每个域需要保留入口文件 routes/{domain}.php，版本分组用 group(目录) 加载');
        }

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
            // 走静态方法：生成文件不需要数据库连接
            $file = \SunsetReverie\Database\Migration\Migrator::createFile(
                $this->migrationDirectory(),
                $name
            );
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
        $this->line('  lint:architecture  架构检查（8 项：依赖方向 / Facade 一致性 / 组件注册）');
        $this->line('  lint:isolation     域隔离检查（3 条分层规则，CI 可用）');
        $this->line('  optimize      一键缓存（配置 + 路由）');
        $this->line('  optimize:clear 一键清除缓存');
        $this->line('  migrate       执行未运行的迁移');
        $this->line('  migrate:rollback [--step=1]  回滚最近批次');
        $this->line('  migrate:status 查看迁移状态');
        $this->line('  migrate:fresh 重建全部表（需 --force）');
        $this->line('  db:show       连接信息与表概览');
        $this->line('  route:mode    切换路由模式（single|directory，--dry-run）');
        $this->line('  schedule:run  执行到期任务（cron 每分钟调一次）');
        $this->line('  schedule:list 列出定时任务与下次执行');
        $this->line('  queue:work    消费队列（--once 处理到空，--max=N）');
        $this->line('  queue:size    队列长度');
        $this->line('  queue:clear   清空队列（不含死信）');
        $this->line('  queue:failed  查看死信');
        $this->line('  queue:retry   死信重新入队（--all 全部）');
        $this->line('  make:migration {name}        生成迁移文件');
        $this->line('  make:controller {Domain/Name} 生成域控制器');
        $this->line('  make:model {Name}            生成领域服务（app/Domain/）');
        $this->line('  make:middleware {Name}       生成中间件');
        $this->line('  make:request {Domain/Name}   生成请求校验类');
        $this->line('  make:seeder {Name}           生成填充脚本（scripts/）');
        $this->line('  make:domain {name}           生成域骨架（路由 + 控制器）');
        $this->line('  jwt:secret    生成 JWT 密钥并写入 .env（--show 只打印）');
        $this->line('  cache:clear   清空默认缓存驱动');
        $this->line('  domain:list   列出已配置的 API 域');
        $this->line('  db:seed       执行 scripts/seed.php');
        $this->line('  upload:prune  清理过期的分片上传会话');
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
