<?php

declare(strict_types=1);

namespace SunsetReverie\Foundation;

use Closure;
use Throwable;
use SunsetReverie\Config\Config;
use SunsetReverie\Console\ConsoleKernel;
use SunsetReverie\Config\Env;
use SunsetReverie\Config\EnvLoader;
use SunsetReverie\Auth\AuthManager;
use SunsetReverie\Auth\AuthServiceProvider;
use SunsetReverie\Api\DomainContext;
use SunsetReverie\Api\DomainRegistry;
use SunsetReverie\Api\DomainResolver;
use SunsetReverie\Api\Middleware\ResolveDomain;
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
use SunsetReverie\Http\Middleware\HandleCors;
use SunsetReverie\Http\Middleware\SecurityHeaders;
use SunsetReverie\Http\Middleware\TraceRequest;
use SunsetReverie\Http\Pipeline;
use SunsetReverie\Http\Request;
use SunsetReverie\Log\LogManager;
use SunsetReverie\Log\Middleware\LogRequest;
use SunsetReverie\Log\LogServiceProvider;
use SunsetReverie\Http\Response;
use SunsetReverie\RateLimit\RateLimitServiceProvider;
use SunsetReverie\Support\Facade;
use SunsetReverie\Routing\Router;
use SunsetReverie\Upload\Controller\FileController;
use SunsetReverie\Upload\UploadManager;
use SunsetReverie\Upload\UploadServiceProvider;
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
    public const VERSION = '0.19.0';

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

    private DomainRegistry $domains;

    private DomainContext $domainContext;

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
        $this->domainContext = new DomainContext();
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
        Facade::clearContainer();

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
     * 获取当前域上下文（错误码段位 / 缓存前缀 / 上传目录）。
     *
     * @return DomainContext 域上下文
     */
    public function domain(): DomainContext
    {
        return $this->domainContext;
    }

    /**
     * 获取上传管理器。
     *
     * @return UploadManager 上传管理器
     */
    public function upload(): UploadManager
    {
        return $this->container->get(UploadManager::class);
    }

    /**
     * 获取认证管理器。
     *
     * @return AuthManager 认证管理器
     */
    public function auth(): AuthManager
    {
        return $this->container->get(AuthManager::class);
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
     * 编译缓存目录（配置 / 路由 / 类映射产物）。
     *
     * @param string $file 可选文件名
     * @return string 绝对路径
     */
    public function compiledPath(string $file = ''): string
    {
        $directory = $this->basePath('storage/compiled');

        return $file === '' ? $directory : $directory . '/' . $file;
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

        if ($matched !== null) {
            $request = $request
                ->withAttribute('route.name', $matched['route']->name)
                ->withAttribute('route.uri', $matched['route']->uri);
        }

        $middleware = [
            ...$this->globalMiddleware,
            ...($matched === null ? [] : $matched['route']->middlewareList()),
        ];

        try {
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
        } catch (Throwable $e) {
            // 中间件自身抛出的异常（如认证失败）：此时外层中间件的后置逻辑不会执行，
            // 因此这里补上基础的追踪与安全头，保证错误响应与正常响应同样安全。
            return $this->errorHandler->render($e, $request)
                ->withHeader('X-Request-Id', $request->requestId)
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withHeader('X-Frame-Options', 'DENY');
        }
    }

    /**
     * 执行 CLI 命令。
     *
     * @param list<string> $argv 命令行参数
     * @return int 退出码（0 成功 / 1 失败 / 2 用法错误）
     */
    /**
     * 执行 CLI 命令（委托给 ConsoleKernel）。
     *
     * @param list<string> $argv 命令行参数
     * @return int 退出码（0 成功 / 1 失败 / 2 用法错误）
     */
    public function runConsole(array $argv): int
    {
        return (new ConsoleKernel($this))->run($argv);
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
        $this->loadDomainRegistry();

        // 先绑定基础设施：路由文件里可能直接写 Route::get() / DB::table()
        $this->container->instance(self::class, $this);
        $this->container->instance(Container::class, $this->container);
        $this->container->instance(Router::class, $this->router);
        $this->container->instance(Config::class, $this->config);
        $this->container->instance(DomainRegistry::class, $this->domains);
        $this->container->instance(DomainContext::class, $this->domainContext);
        $this->container->instance(
            DomainResolver::class,
            new DomainResolver(
                $this->domains,
                (bool) $this->config->get('api.allow_header_detection', false),
            ),
        );

        // 门面在此挂载：早于 Provider 与路由加载，让配置文件之外的地方都能用静态调用
        Facade::setContainer($this->container);

        $this->registerProviders();
        $this->loadRoutes();

        $this->errorHandler = new ErrorHandler($this->isDebug());
        $this->globalMiddleware = $this->resolveGlobalMiddleware();
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
        // 默认组件始终注册；config/app.php 的 providers 只做追加
        $providers = [
            DatabaseServiceProvider::class,
            CacheServiceProvider::class,
            ValidationServiceProvider::class,
            AuthServiceProvider::class,
            RateLimitServiceProvider::class,
            LogServiceProvider::class,
            UploadServiceProvider::class,
        ];

        $configured = $this->config->get('app.providers');

        if (is_array($configured)) {
            $providers = array_values(array_unique([...$providers, ...$configured]));
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
        $cacheFile = $this->compiledPath('config.php');

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

        // 顺序很重要：安全头在最外（保证错误响应也带头），追踪其次，CORS 最后
        // 这样预检 204 也能拿到 X-Request-Id 与安全头
        // 顺序：安全头最外（错误响应也带头）→ 追踪 → 域解析（日志要用）→ 日志 → CORS（预检最后短路）
        return [
            SecurityHeaders::class,
            TraceRequest::class,
            ResolveDomain::class,
            LogRequest::class,
            HandleCors::class,
        ];
    }

    /**
     * 构建域注册表（未配置 api.domains 时为空表，走单域模式）。
     *
     * @return void
     */
    private function loadDomainRegistry(): void
    {
        $this->domains = new DomainRegistry((array) $this->config->get('api', []));
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
        // 多域：每个域一个入口文件，注册时套上「域前缀 + 域中间件 + 域名前缀」
        if (!$this->domains->isEmpty()) {
            $this->registerDomainRoutes();

            return;
        }

        // 单域：routes/api.php
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
     * 按域加载路由文件（routes/{domain}.php）。
     *
     * @return void
     */
    private function registerDomainRoutes(): void
    {
        foreach ($this->domains->all() as $domain) {
            $file = $this->basePath('routes/' . $domain->name . '.php');

            if (!is_file($file)) {
                continue;
            }

            $registrar = require $file;

            if (!is_callable($registrar)) {
                continue;
            }

            $this->router->group([
                'prefix' => $domain->prefix,
                'as' => $domain->name . '.',
                'middleware' => $domain->middleware,
            ], static function (Router $router) use ($registrar): void {
                $registrar($router);
            });
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

        // 私有文件访问入口（签名 URL 的验证端，见 upload.signedUrl）
        $this->router->get('/files', [FileController::class, 'show'], 'framework.file');
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
}
