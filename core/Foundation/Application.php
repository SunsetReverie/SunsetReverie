<?php

declare(strict_types=1);

namespace SunsetReverie\Foundation;

use JsonException;
use Throwable;
use SunsetReverie\Container\Container;
use SunsetReverie\Exception\ErrorCode;
use SunsetReverie\Exception\HttpException;
use SunsetReverie\Http\Request;
use SunsetReverie\Http\Response;
use SunsetReverie\Routing\Exception\MethodNotAllowedException;
use SunsetReverie\Routing\Router;

/**
 * 应用引导与请求分发（v0.1 最小内核）。
 *
 * 职责：
 * 1. 注册自动加载器（由 autoload.php 完成）；
 * 2. 构建容器并绑定基础服务；
 * 3. 分发 HTTP 请求与 CLI 命令；
 * 4. 统一响应包络与异常兜底。
 */
final class Application
{
    /** 框架版本 */
    public const VERSION = '0.1.0';

    private static ?self $instance = null;

    private Container $container;

    private Router $router;

    /** @var Request|null 当前请求（用于包络中的 request_id） */
    private ?Request $currentRequest = null;

    /**
     * @param string $basePath 项目根目录
     */
    private function __construct(private readonly string $basePath)
    {
        $this->container = new Container();
        $this->router = new Router();
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
        $app->registerBaseBindings();
        $app->registerDefaultRoutes();
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
     * 处理 HTTP 请求。
     *
     * @param Request|null $request 请求对象；null 表示从运行环境捕获
     * @return Response 响应对象
     */
    public function handleRequest(?Request $request = null): Response
    {
        $request ??= Request::capture();
        $this->currentRequest = $request;

        try {
            $matched = $this->router->dispatch($request->method, $request->path);
            $result = $this->invoke($matched['route']->handler, $matched['params'], $request);
            $response = $this->normalize($result);
        } catch (MethodNotAllowedException $e) {
            $response = $this->fail(
                $e->errorCode ?? ErrorCode::MethodNotAllowed->value,
                $e->getMessage(),
                $e->status,
            )->withHeader('Allow', implode(', ', $e->allowed));
        } catch (HttpException $e) {
            $response = $this->fail(
                $e->errorCode ?? $e->status,
                $e->getMessage(),
                $e->status,
                $e->errors,
            );
        } catch (Throwable $e) {
            $response = $this->renderThrowable($e);
        }

        return $response
            ->withHeader('X-Request-Id', $request->requestId)
            ->withHeader('X-Content-Type-Options', 'nosniff');
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
            'serve' => $this->commandServe($args),
            'route:list' => $this->commandRouteList($args),
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
        $payload = $this->envelope(ErrorCode::Ok->value, $message, $data);

        return Response::json($payload, 200);
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
        $payload = $this->envelope($code, $message, null);

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return Response::json($payload, $status);
    }

    /**
     * 绑定基础服务到容器。
     *
     * @return void
     */
    private function registerBaseBindings(): void
    {
        $this->container->instance(self::class, $this);
        $this->container->instance(Container::class, $this->container);
        $this->container->instance(Router::class, $this->router);
    }

    /**
     * 注册内置路由（健康检查）。
     *
     * @return void
     */
    private function registerDefaultRoutes(): void
    {
        $this->router->get('/health', function (Request $request): array {
            return [
                'status' => 'healthy',
                'version' => self::VERSION,
                'time' => date(DATE_ATOM),
            ];
        }, 'framework.health');
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
        if ($handler instanceof \Closure) {
            return $handler($request, ...array_values($params));
        }

        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            $instance = is_object($class) ? $class : $this->container->get((string) $class);

            return $instance->{$method}($request, ...array_values($params));
        }

        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
            $instance = $this->container->get($class);

            return $instance->{$method}($request, ...array_values($params));
        }

        throw new HttpException(500, ErrorCode::InternalError->value, '路由处理器不可调用');
    }

    /**
     * 规范化处理器返回值。
     *
     * @param mixed $result 返回值
     * @return Response 响应实例
     * @throws JsonException 编码失败时抛出
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
     * 渲染未捕获异常（生产脱敏 / 调试暴露细节）。
     *
     * @param Throwable $e 异常
     * @return Response 响应实例
     */
    private function renderThrowable(Throwable $e): Response
    {
        $debug = (bool) ($_ENV['APP_DEBUG'] ?? false);

        $response = $this->fail(
            ErrorCode::InternalError->value,
            $debug ? $e->getMessage() : '服务器内部错误',
            500,
        );

        if ($debug) {
            $response = $response->withHeader('X-Debug-Exception', rawurlencode($e::class));
        }

        error_log(sprintf(
            '[SunsetReverie] %s: %s in %s:%d',
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));

        return $response;
    }

    /**
     * 构造统一响应包络。
     *
     * @param int $code 业务码
     * @param string $message 消息
     * @param mixed $data 数据
     * @return array<string, mixed> 包络数组
     */
    private function envelope(int $code, string $message, mixed $data): array
    {
        return [
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'request_id' => $this->currentRequestId(),
            'timestamp' => time(),
        ];
    }

    /**
     * 获取当前请求 ID（CLI 场景生成临时值）。
     *
     * @return string 请求 ID
     */
    private function currentRequestId(): string
    {
        return $this->currentRequest?->requestId ?? \SunsetReverie\Support\Str::uuid7();
    }

    // ── CLI 命令 ──────────────────────────────────────────────────────

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
        $directory = $this->basePath('storage/compiled');

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
if (str_starts_with(\$path, '/uploads/') || is_file(\$basePath . '/public' . \$path)) {
    return false;
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
            ], $routes);

            $this->line((string) json_encode(['total' => count($rows), 'routes' => $rows], JSON_UNESCAPED_UNICODE));

            return 0;
        }

        $this->line(sprintf('共 %d 条路由', count($routes)));

        foreach ($routes as $route) {
            $this->line(sprintf(
                '%-12s %-24s %s',
                implode('|', $route->methods),
                $route->uri,
                $route->name ?? '-'
            ));
        }

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
        $this->line('  根目录    : ' . $this->basePath);
        $this->line('  已解析服务: ' . count($this->container->resolved()));
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
        $this->line('  serve       启动本地开发服务器（--host= --port=）');
        $this->line('  route:list  列出已注册路由（--json）');
        $this->line('  about       显示环境与框架信息');
        $this->line('  help        显示本帮助');

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
