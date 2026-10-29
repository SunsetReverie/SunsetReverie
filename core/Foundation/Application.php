<?php

declare(strict_types=1);

namespace SunsetReverie\Foundation;

use Closure;
use Throwable;
use SunsetReverie\Config\Config;
use SunsetReverie\Config\Env;
use SunsetReverie\Config\EnvLoader;
use SunsetReverie\Cache\CacheManager;
use SunsetReverie\Cache\CacheServiceProvider;
use SunsetReverie\Cache\CacheStoreInterface;
use SunsetReverie\Database\Connection;
use SunsetReverie\Database\ConnectionManager;
use SunsetReverie\Database\DatabaseServiceProvider;
use SunsetReverie\Container\Container;
use SunsetReverie\Exception\ErrorCode;
use SunsetReverie\Exception\ErrorHandler;
use SunsetReverie\Exception\HttpException;
use SunsetReverie\Http\Controller\HealthController;
use SunsetReverie\Http\Envelope;
use SunsetReverie\Http\Middleware\SecurityHeaders;
use SunsetReverie\Http\Middleware\TraceRequest;
use SunsetReverie\Http\Pipeline;
use SunsetReverie\Http\Request;
use SunsetReverie\Http\Response;
use SunsetReverie\Routing\Router;
use SunsetReverie\Validation\ValidationServiceProvider;
use SunsetReverie\Validation\Validator;

/**
 * 应用引导与请求分发。
 *
 * v0.2 在 v0.1 基础上增加：
 * - 配置加载（.env + config/*.php + 环境覆盖 + 缓存）；
 * - 全局与路由级中间件管道；
 * - 统一异常渲染（debug / 生产两套策略）；
 * - route:cache / config:cache 等运维命令。
 */
final class Application
{
    /** 框架版本 */
    public const VERSION = '0.4.0';

    private static ?self $instance = null;

    private Container $container;

    private Router $router;

    private Pipeline $pipeline;

    private Config $config;

    private ErrorHandler $errorHandler;

    /** @var Request|null 当前请求（包络中的 request_id 来源） */
    private ?Request $currentRequest = null;

    /** @var list<class-string|Closure> 全局中间件 */
    private array $globalMiddleware = [];

    private ProviderRepository $providers;

    /**
     * @param string $basePath 项目根目录
     */
    private function __construct(private readonly string $basePath)
    {
        $this->container = new Container();
        $this->router = new Router();
        $this->pipeline = new Pipeline($this->container);
        $this->config = new Config();
        $this->errorHandler = new ErrorHandler();
        $this->providers = new ProviderRepository($this->container);
    }

    /**
     * 引导应用（幂等：重复调用返回同一实例）。
     *
     * @param string $basePath 项目根目录
     * @return self 应用实例
     */
    public static function boot(string $basePath): self
    {
        if (self::$instance instanceof self) {
            return self::$instance;
        }

        $app = new self(rtrim($basePath, '/'));
        $app->bootstrap();
        self::$instance = $app;

        return $app;
    }

    /**
     * 获取已引导的实例。
     *
     * @return self 应用实例
     * @throws \RuntimeException 尚未引导时抛出
     */
    public static function getInstance(): self
    {
        if (!self::$instance instanceof self) {
            throw new \RuntimeException('应用尚未引导，请先调用 Application::boot()');
        }

        return self::$instance;
    }

    /**
     * 重置单例（仅供测试使用）。
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * 获取容器。
     *
     * @return Container 容器实例
     */
    public function container(): Container
    {
        return $this->container;
    }

    /**
     * 获取路由器。
     *
     * @return Router 路由器实例
     */
    public function router(): Router
    {
        return $this->router;
    }

    /**
     * 获取配置仓库。
     *
     * @return Config 配置实例
     */
    public function config(): Config
    {
        return $this->config;
    }

    /**
     * 获取数据库连接（懒建立）。
     *
     * @param string|null $name 连接名，null 表示默认连接
     * @return Connection 连接实例
     */
    public function db(?string $name = null): Connection
    {
        return $this->container->get(ConnectionManager::class)->connection($name);
    }

    /**
     * 获取缓存驱动。
     *
     * @param string|null $name 驱动名，null 表示默认驱动
     * @return CacheStoreInterface 缓存驱动
     */
    public function cache(?string $name = null): CacheStoreInterface
    {
        return $this->container->get(CacheManager::class)->store($name);
    }

    /**
     * 获取校验器。
     *
     * @return Validator 校验器实例
     */
    public function validator(): Validator
    {
        return $this->container->get(Validator::class);
    }

    /**
     * 获取 Provider 注册表（诊断用）。
     *
     * @return ProviderRepository Provider 注册表
     */
    public function providers(): ProviderRepository
    {
        return $this->providers;
    }

    /**
     * 拼接项目内绝对路径。
     *
     * @param string $path 相对路径
     * @return string 绝对路径
     */
    public function basePath(string $path = ''): string
    {
        return $this->basePath . ($path === '' ? '' : '/' . ltrim($path, '/'));
    }

    /**
     * 追加全局中间件。
     *
     * @param class-string|Closure|list<class-string|Closure> $middleware 中间件
     * @return self 应用实例
     */
    public function middleware(string|Closure|array $middleware): self
    {
        foreach ((array) $middleware as $item) {
            $this->globalMiddleware[] = $item;
        }

        return $this;
    }

    /**
     * 获取全局中间件列表。
     *
     * @return list<class-string|Closure> 中间件
     */
    public function globalMiddleware(): array
    {
        return $this->globalMiddleware;
    }

    /**
     * 处理 HTTP 请求。
     *
     * @param Request|null $request 请求对象；null 表示从运行环境捕获
     * @return Response 响应对象
     */
    public function handleRequest(?Request $request = null): Response
    {
        $request ??= Request::capture();
        $this->currentRequest = $request;

        $matched = null;
        $dispatchError = null;

        try {
            $matched = $this->router->dispatch($request->method, $request->path);
        } catch (Throwable $e) {
            $dispatchError = $e;
        }

        $middleware = [
            ...$this->globalMiddleware,
            ...($matched === null ? [] : $matched['route']->middlewareList()),
        ];

        return $this->pipeline
            ->send($request)
            ->through($middleware)
            ->then(function (Request $request) use ($matched, $dispatchError): Response {
                if ($dispatchError !== null) {
                    return $this->errorHandler->render($dispatchError, $request);
                }

                try {
                    $result = $this->invoke($matched['route']->handler, $matched['params'], $request);

                    return $this->normalize($result);
                } catch (Throwable $e) {
                    return $this->errorHandler->render($e, $request);
                }
            });
    }

    /**
     * 执行 CLI 命令。
     *
     * @param list<string> $argv 命令行参数
     * @return int 退出码（0 成功 / 1 失败 / 2 用法错误）
     */
    public function runConsole(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $args = array_slice($argv, 2);

        return match ($command) {
            'new' => $this->commandNew($args),
            'serve' => $this->commandServe($args),
            'route:list' => $this->commandRouteList($args),
            'route:cache' => $this->commandRouteCache(),
            'route:clear' => $this->commandRouteClear(),
            'config:cache' => $this->commandConfigCache(),
            'config:clear' => $this->commandConfigClear(),
            'about' => $this->commandAbout(),
            'help', '--help', '-h' => $this->commandHelp(),
            '--version', '-V' => $this->commandVersion(),
            default => $this->commandUnknown($command),
        };
    }

    /**
     * 构造成功响应（统一包络）。
     *
     * @param mixed $data 业务数据
     * @param string $message 提示消息
     * @return Response 响应实例
     */
    public function success(mixed $data = null, string $message = 'ok'): Response
    {
        return Response::json(Envelope::success($data, $message, $this->currentRequestId()));
    }

    /**
     * 构造失败响应（统一包络）。
     *
     * @param int $code 业务错误码
     * @param string $message 提示消息
     * @param int $status HTTP 状态码
     * @param array<string, list<string>>|null $errors 字段级错误
     * @return Response 响应实例
     */
    public function fail(int $code, string $message, int $status = 400, ?array $errors = null): Response
    {
        $payload = Envelope::error($code, $message, $this->currentRequestId(), $errors);

        return Response::json($payload, $status);
    }

    /**
     * 引导流程：环境 → 配置 → 绑定 → 中间件 → 路由。
     *
     * @return void
     */
    private function bootstrap(): void
    {
        $this->loadEnvironment();
        $this->registerAppNamespace();
        $this->loadConfiguration();
        $this->loadRoutes();

        $this->container->instance(self::class, $this);
        $this->container->instance(Container::class, $this->container);
        $this->container->instance(Router::class, $this->router);
        $this->container->instance(Config::class, $this->config);

        $this->errorHandler = new ErrorHandler($this->isDebug());
        $this->globalMiddleware = $this->resolveGlobalMiddleware();
        $this->registerProviders();
    }

    /**
     * 注册并启动服务提供者。
     *
     * 默认只启用数据库组件；其余组件在 config/app.php 的 providers 中显式追加。
     *
     * @return void
     */
    private function registerProviders(): void
    {
        $providers = $this->config->get('app.providers');

        if (!is_array($providers)) {
            $providers = [
                DatabaseServiceProvider::class,
                CacheServiceProvider::class,
                ValidationServiceProvider::class,
            ];
        }

        $this->providers->register($providers);
        $this->providers->boot();
    }

    /**
     * 加载 .env（存在才加载；真实环境变量优先）。
     *
     * @return void
     */
    private function loadEnvironment(): void
    {
        $file = $this->basePath('.env');

        if (is_file($file)) {
            EnvLoader::load($file);
        }
    }

    /**
     * 加载配置：优先读取缓存，其次读取 config 目录。
     *
     * @return void
     */
    private function loadConfiguration(): void
    {
        $cacheFile = $this->basePath('storage/compiled/config.php');

        if ($this->shouldPreferCache() && is_file($cacheFile)) {
            $this->config = Config::fromCache($cacheFile);

            return;
        }

        $this->config = Config::fromDirectory(
            $this->basePath('config'),
            Env::string('APP_ENV', 'local'),
        );
    }

    /**
     * 解析全局中间件（配置优先，否则使用框架默认）。
     *
     * @return list<class-string|Closure> 中间件列表
     */
    private function resolveGlobalMiddleware(): array
    {
        $configured = $this->config->get('app.middleware');

        if (is_array($configured)) {
            return array_values($configured);
        }

        return [TraceRequest::class, SecurityHeaders::class];
    }

    /**
     * 加载路由：生产环境优先使用缓存，其余情况现场注册。
     *
     * @return void
     */
    private function loadRoutes(): void
    {
        $cacheFile = $this->compiledPath('routes.php');

        if ($this->shouldPreferCache() && is_file($cacheFile)) {
            $this->router = Router::fromCacheFile($cacheFile);

            return;
        }

        $this->registerDefaultRoutes();
        $this->registerFileRoutes();
    }

    /**
     * 注册业务路由文件（routes/api.php 返回一个接收 Router 的闭包）。
     *
     * @return void
     */
    private function registerFileRoutes(): void
    {
        $file = $this->basePath('routes/api.php');

        if (!is_file($file)) {
            return;
        }

        $registrar = require $file;

        if (is_callable($registrar)) {
            $registrar($this->router);
        }
    }

    /**
     * 注册 App\ 命名空间到项目根 app/ 目录。
     *
     * 框架位于项目子目录时（如 sunsetreverie/），框架自身无法推断项目位置，
     * 因此由 Application 在引导时注册，而不是写死在 autoload.php 里。
     *
     * @return void
     */
    private function registerAppNamespace(): void
    {
        $appPath = $this->basePath('app');

        if (!is_dir($appPath)) {
            return;
        }

        spl_autoload_register(static function (string $class) use ($appPath): void {
            if (!str_starts_with($class, 'App\\')) {
                return;
            }

            $file = $appPath . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';

            if (is_file($file)) {
                require $file;
            }
        });
    }

    /**
     * 是否优先使用编译缓存（仅生产环境）。
     *
     * 开发环境忽略缓存，避免「改了路由/配置却不生效」的困惑。
     *
     * @return bool 使用缓存返回 true
     */
    private function shouldPreferCache(): bool
    {
        return Env::string('APP_ENV', 'local') === 'production';
    }

    /**
     * 是否处于调试模式。
     *
     * @return bool 调试返回 true
     */
    private function isDebug(): bool
    {
        return Env::bool('APP_DEBUG', false);
    }

    /**
     * 注册内置路由。
     *
     * @return void
     */
    private function registerDefaultRoutes(): void
    {
        $this->router->get('/health', [HealthController::class, 'index'], 'framework.health');
    }

    /**
     * 调用处理器。
     *
     * @param mixed $handler 处理器（Closure / [class, method] / 'class@method'）
     * @param array<string, string> $params 路由参数
     * @param Request $request 当前请求
     * @return mixed 处理器返回值
     * @throws HttpException 处理器不可调用时抛出
     */
    private function invoke(mixed $handler, array $params, Request $request): mixed
    {
        [$target, $reflection] = $this->resolveHandler($handler);
        $arguments = $this->resolveArguments($reflection->getParameters(), $params, $request);

        return $target(...$arguments);
    }

    /**
     * 把处理器解析成可调用对象与反射信息。
     *
     * @param mixed $handler 处理器（Closure / [class, method] / 'class@method'）
     * @return array{0: callable, 1: \ReflectionFunctionAbstract} 目标与反射
     * @throws HttpException 处理器不可调用时抛出
     */
    private function resolveHandler(mixed $handler): array
    {
        if ($handler instanceof Closure) {
            return [$handler, new \ReflectionFunction($handler)];
        }

        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            $instance = is_object($class) ? $class : $this->container->get((string) $class);

            return $this->reflectMethod($instance, (string) $method);
        }

        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);

            return $this->reflectMethod($this->container->get($class), $method);
        }

        throw new HttpException(500, ErrorCode::InternalError->value, '路由处理器不可调用');
    }

    /**
     * 反射控制器方法并校验可调用性。
     *
     * @param object $instance 控制器实例
     * @param string $method 方法名
     * @return array{0: callable, 1: \ReflectionMethod} 目标与反射
     * @throws HttpException 方法不存在或不可调用时抛出
     */
    private function reflectMethod(object $instance, string $method): array
    {
        if (!method_exists($instance, $method)) {
            throw new HttpException(500, ErrorCode::InternalError->value, sprintf(
                '控制器方法 [%s::%s] 不存在',
                $instance::class,
                $method
            ));
        }

        $reflection = new \ReflectionMethod($instance, $method);

        if (!$reflection->isPublic()) {
            throw new HttpException(500, ErrorCode::InternalError->value, sprintf(
                '控制器方法 [%s::%s] 必须是 public',
                $instance::class,
                $method
            ));
        }

        return [[$instance, $method], $reflection];
    }

    /**
     * 按类型与名称解析处理器参数。
     *
     * 解析优先级：
     * 1. 类类型：Request 注入当前请求，其余从容器解析（支持任意服务）；
     * 2. 与路由参数同名的标量：取路由参数并做类型转换；
     * 3. 参数默认值；
     * 4. 可空参数注入 null；
     * 5. 否则报错（不猜测）。
     *
     * @param list<\ReflectionParameter> $parameters 参数列表
     * @param array<string, string> $params 路由参数
     * @param Request $request 当前请求
     * @return list<mixed> 实参数组
     * @throws HttpException 无法解析时抛出
     */
    private function resolveArguments(array $parameters, array $params, Request $request): array
    {
        $arguments = [];

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $class = $type->getName();

                if ($class === Request::class || $request instanceof $class) {
                    $arguments[] = $request;

                    continue;
                }

                $arguments[] = $this->container->get($class);

                continue;
            }

            $name = $parameter->getName();

            if (array_key_exists($name, $params)) {
                $arguments[] = $this->coerceScalar($params[$name], $type);

                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();

                continue;
            }

            if ($parameter->allowsNull()) {
                $arguments[] = null;

                continue;
            }

            throw new HttpException(500, ErrorCode::InternalError->value, sprintf(
                '无法解析控制器参数 [$%s]：标量参数需与路由参数同名或提供默认值',
                $name
            ));
        }

        return $arguments;
    }

    /**
     * 路由参数类型转换（路由匹配结果永远是字符串）。
     *
     * @param string $value 原始值
     * @param \ReflectionType|null $type 目标类型
     * @return mixed 转换后的值
     */
    private function coerceScalar(string $value, ?\ReflectionType $type): mixed
    {
        if (!$type instanceof \ReflectionNamedType) {
            return $value;
        }

        return match ($type->getName()) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL),
            default => $value,
        };
    }

    /**
     * 规范化处理器返回值。
     *
     * @param mixed $result 返回值
     * @return Response 响应实例
     */
    private function normalize(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if ($result === null) {
            return Response::noContent();
        }

        if (is_string($result)) {
            return Response::text($result);
        }

        return $this->success($result);
    }

    /**
     * 获取当前请求 ID（无请求时生成临时值）。
     *
     * @return string 请求 ID
     */
    private function currentRequestId(): string
    {
        return $this->currentRequest?->requestId ?? \SunsetReverie\Support\Str::uuid7();
    }

    // ── CLI 命令 ──────────────────────────────────────────────────────

    /**
     * new 命令：从 skeleton 生成项目骨架。
     *
     * @param list<string> $args 参数（[目录] [--force]）
     * @return int 退出码
     */
    private function commandNew(array $args): int
    {
        if ($this->hasOption($args, 'domains')) {
            $this->error('多域骨架尚未实现（计划 v0.5），当前请使用单域骨架');

            return 1;
        }

        $skeleton = $this->basePath('skeleton');

        if (!is_dir($skeleton)) {
            $this->error('未找到骨架模板：' . $skeleton);

            return 1;
        }

        $targetArgument = $this->firstArgument($args) ?? '.';
        $current = getcwd() ?: $this->basePath();
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

        $frameworkRelative = $this->relativePath($target, $this->basePath());
        $copied = $this->copySkeleton($skeleton, $target, $name, $frameworkRelative);
        $this->writeEnvFile($target);
        $this->writeBinShim($target, $frameworkRelative);

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
     * serve 命令：启动 PHP 内置开发服务器。
     *
     * @param list<string> $args 参数
     * @return int 退出码
     */
    private function commandServe(array $args): int
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
        $basePathVar = var_export($this->basePath, true);

        $code = <<<PHP
<?php

declare(strict_types=1);

// 由 `sr serve` 自动生成，请勿手动修改
\$basePath = {$basePathVar};
\$path = parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// 静态资源与上传文件交给内置服务器直接读取
if (str_starts_with(\$path, '/uploads/') || (is_file(\$basePath . '/public' . \$path) && !str_contains(\$path, '..'))) {
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

    /**
     * route:list 命令。
     *
     * @param list<string> $args 参数
     * @return int 退出码
     */
    private function commandRouteList(array $args): int
    {
        $routes = $this->router->routes();

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

        $this->line(sprintf('共 %d 条路由', count($routes)));

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
    private function commandRouteCache(): int
    {
        try {
            $code = $this->router->export();
        } catch (Throwable $e) {
            $this->error('路由缓存失败：' . $e->getMessage());

            return 1;
        }

        $file = $this->compiledPath('routes.php');

        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }

        file_put_contents($file, $code);
        $this->line('路由已缓存：' . $file . '（' . count($this->router->routes()) . ' 条）');
        $this->line('提示：路由缓存仅在 APP_ENV=production 时加载');

        return 0;
    }

    /**
     * route:clear 命令：清除路由缓存。
     *
     * @return int 退出码
     */
    private function commandRouteClear(): int
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
    private function commandConfigCache(): int
    {
        try {
            $code = $this->config->export();
        } catch (Throwable $e) {
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
    private function commandConfigClear(): int
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

    /**
     * about 命令：输出运行环境与框架信息。
     *
     * @return int 退出码
     */
    private function commandAbout(): int
    {
        $this->line('SunsetReverie Framework');
        $this->line('  版本      : ' . self::VERSION);
        $this->line('  PHP       : ' . PHP_VERSION);
        $this->line('  环境      : ' . Env::string('APP_ENV', 'local') . (Env::bool('APP_DEBUG') ? '（debug）' : ''));
        $this->line('  根目录    : ' . $this->basePath);
        $this->line('  配置项    : ' . count($this->config->all()) . ' 组');
        $this->line('  全局中间件: ' . count($this->globalMiddleware));
        $this->line('  路由数量  : ' . count($this->router->routes()));
        $this->line('  加载文件  : ' . count(get_included_files()));
        $this->line('  峰值内存  : ' . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB');

        return 0;
    }

    /**
     * help 命令。
     *
     * @return int 退出码
     */
    private function commandHelp(): int
    {
        $this->line('SunsetReverie ' . self::VERSION . ' 命令行');
        $this->line('');
        $this->line('用法：sr <command> [options]');
        $this->line('');
        $this->line('可用命令：');
        $this->line('  new           生成项目骨架（[目录] [--force]）');
        $this->line('  serve         启动本地开发服务器（--host= --port=）');
        $this->line('  route:list    列出已注册路由（--json）');
        $this->line('  route:cache   导出路由缓存');
        $this->line('  route:clear   清除路由缓存');
        $this->line('  config:cache  导出配置缓存');
        $this->line('  config:clear  清除配置缓存');
        $this->line('  about         显示环境与框架信息');
        $this->line('  help          显示本帮助');

        return 0;
    }

    /**
     * version 命令。
     *
     * @return int 退出码
     */
    private function commandVersion(): int
    {
        $this->line(self::VERSION);

        return 0;
    }

    /**
     * 未知命令。
     *
     * @param string $command 命令名
     * @return int 退出码（2 表示用法错误）
     */
    private function commandUnknown(string $command): int
    {
        $this->error(sprintf('未知命令：%s（执行 sr help 查看可用命令）', $command));

        return 2;
    }

    /**
     * 编译缓存目录。
     *
     * @param string $file 可选文件名
     * @return string 绝对路径
     */
    private function compiledPath(string $file = ''): string
    {
        $directory = $this->basePath('storage/compiled');

        return $file === '' ? $directory : $directory . '/' . $file;
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
