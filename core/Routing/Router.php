<?php

declare(strict_types=1);

namespace SunsetReverie\Routing;

use SunsetReverie\Api\ApiDomain;

use Closure;
use SunsetReverie\Routing\Exception\DuplicateRouteException;
use SunsetReverie\Routing\Exception\MethodNotAllowedException;
use SunsetReverie\Routing\Exception\RouteNotFoundException;

/**
 * 路由注册与匹配。
 *
 * v0.2 能力：
 * - 静态路径 O(1)、{name} 占位符 + 参数约束；
 * - 路由组（prefix / as / middleware）；
 * - 路由级中间件；
 * - 404 / 405 区分，405 回写 Allow；
 * - 导出为可缓存数组（禁止闭包）。
 */
final class Router
{
    /** @var array<string, Route> 静态路由："{METHOD} {path}" => Route */
    private array $static = [];

    /** @var list<Route> 动态路由（按注册顺序匹配） */
    private array $dynamic = [];

    /** @var array<string, array<string, Route>> 路径 => 方法 => 路由（用于 405） */
    private array $byPath = [];

    /** @var array<string, Route> 具名路由 */
    private array $named = [];

    /** @var list<Route> 全部路由（去重后，按注册顺序） */
    private array $all = [];

    /** @var ApiDomain|null 当前正在注册路由的域（用于中间件别名解析） */
    private ?ApiDomain $currentDomain = null;

    /** @var list<array<string, mixed>> 分组属性栈 */
    private array $groupStack = [];

    /**
     * 注册 GET 路由。
     *
     * @param string $uri 路径
     * @param mixed $handler 处理器
     * @param string|null $name 路由名
     * @return Route 路由实例
     */
    public function get(string $uri, mixed $handler, ?string $name = null): Route
    {
        return $this->add(['GET'], $uri, $handler, $name);
    }

    /**
     * 注册 POST 路由。
     *
     * @param string $uri 路径
     * @param mixed $handler 处理器
     * @param string|null $name 路由名
     * @return Route 路由实例
     */
    public function post(string $uri, mixed $handler, ?string $name = null): Route
    {
        return $this->add(['POST'], $uri, $handler, $name);
    }

    /**
     * 注册 PUT 路由。
     *
     * @param string $uri 路径
     * @param mixed $handler 处理器
     * @param string|null $name 路由名
     * @return Route 路由实例
     */
    public function put(string $uri, mixed $handler, ?string $name = null): Route
    {
        return $this->add(['PUT'], $uri, $handler, $name);
    }

    /**
     * 注册 PATCH 路由。
     *
     * @param string $uri 路径
     * @param mixed $handler 处理器
     * @param string|null $name 路由名
     * @return Route 路由实例
     */
    public function patch(string $uri, mixed $handler, ?string $name = null): Route
    {
        return $this->add(['PATCH'], $uri, $handler, $name);
    }

    /**
     * 注册 DELETE 路由。
     *
     * @param string $uri 路径
     * @param mixed $handler 处理器
     * @param string|null $name 路由名
     * @return Route 路由实例
     */
    public function delete(string $uri, mixed $handler, ?string $name = null): Route
    {
        return $this->add(['DELETE'], $uri, $handler, $name);
    }

    /**
     * 注册任意方法路由。
     *
     * @param string $uri 路径
     * @param mixed $handler 处理器
     * @param string|null $name 路由名
     * @return Route 路由实例
     */
    public function any(string $uri, mixed $handler, ?string $name = null): Route
    {
        return $this->add(['ANY'], $uri, $handler, $name);
    }

    /**
     * 路由组。
     *
     * @param array{prefix?: string, as?: string, middleware?: string|list<string>} $attributes 分组属性
     * @param Closure(Router): void $callback 组内注册回调
     * @return void
     */
    public function group(array $attributes, Closure $callback): void
    {
        $previousDomain = $this->currentDomain;

        // 域上下文可以嵌套（域 group → 版本 group → 资源 group），只在最外层设置
        if (isset($attributes['domain'])) {
            $this->currentDomain = $attributes['domain'];
        }

        $this->groupStack[] = $attributes;

        try {
            $callback($this);
        } finally {
            array_pop($this->groupStack);
            $this->currentDomain = $previousDomain;
        }
    }

    /**
     * 通用注册。
     *
     * @param list<string> $methods 允许的方法
     * @param string $uri 路径（需以 / 开头）
     * @param mixed $handler 处理器
     * @param string|null $name 路由名
     * @return Route 路由实例
     * @throws DuplicateRouteException 重复注册时抛出
     */
    public function add(array $methods, string $uri, mixed $handler, ?string $name = null): Route
    {
        $fullUri = $this->joinUri($uri);
        $methods = array_map('strtoupper', $methods);

        $fullName = $this->joinName($name);
        $route = new Route($methods, $fullUri, $handler, $fullName, $this->groupMiddleware(), $this->currentDomain);

        foreach ($methods as $method) {
            if (isset($this->static[$method . ' ' . $fullUri])) {
                throw new DuplicateRouteException($method, $fullUri);
            }

            $this->byPath[$fullUri][$method] = $route;
        }

        if ($route->isStatic()) {
            foreach ($methods as $method) {
                $this->static[$method . ' ' . $fullUri] = $route;
            }
        } else {
            $this->dynamic[] = $route;
        }

        if ($fullName !== null) {
            $this->named[$fullName] = $route;
        }

        $this->all[] = $route;

        return $route;
    }

    /**
     * 匹配请求。
     *
     * @param string $method HTTP 方法（大写）
     * @param string $path 请求路径
     * @return array{route: Route, params: array<string, string>} 命中结果
     * @throws RouteNotFoundException 无匹配路由时抛出
     * @throws MethodNotAllowedException 路径存在但方法不允许时抛出
     */
    public function dispatch(string $method, string $path): array
    {
        $path = $this->normalizePath($path);

        $static = $this->static[$method . ' ' . $path] ?? $this->static['ANY ' . $path] ?? null;

        if ($static !== null) {
            return ['route' => $static, 'params' => []];
        }

        foreach ($this->dynamic as $route) {
            if (!$route->supports($method)) {
                continue;
            }

            $params = $route->match($path);

            if ($params !== null) {
                return ['route' => $route, 'params' => $params];
            }
        }

        $allowed = $this->allowedMethods($path);

        if ($allowed !== []) {
            throw new MethodNotAllowedException($allowed);
        }

        throw new RouteNotFoundException();
    }

    /**
     * 获取某路径允许的方法（用于 405 的 Allow 头）。
     *
     * @param string $path 请求路径
     * @return list<string> 允许的方法
     */
    public function allowedMethods(string $path): array
    {
        $path = $this->normalizePath($path);

        if (isset($this->byPath[$path])) {
            return array_values(array_diff(array_keys($this->byPath[$path]), ['ANY']));
        }

        $allowed = [];

        foreach ($this->dynamic as $route) {
            if ($route->match($path) !== null) {
                $allowed = [...$allowed, ...$route->methods];
            }
        }

        $allowed = array_values(array_unique(array_diff($allowed, ['ANY'])));
        sort($allowed);

        return $allowed;
    }

    /**
     * 按名称获取路由。
     *
     * @param string $name 路由名
     * @return Route|null 路由实例
     */
    public function named(string $name): ?Route
    {
        return $this->named[$name] ?? null;
    }

    /**
     * 获取全部已注册路由（去重，按注册顺序）。
     *
     * @return list<Route> 路由列表
     */
    public function routes(): array
    {
        return $this->all;
    }

    /**
     * 导出为可缓存的 PHP 代码。
     *
     * @return string PHP 代码
     * @throws \RuntimeException 存在闭包处理器时抛出
     */
    public function export(): string
    {
        $definitions = array_map(
            static fn (Route $route): array => $route->toArray(),
            $this->all
        );

        return "<?php\n\ndeclare(strict_types=1);\n\n// 由 sr route:cache 生成，请勿手动修改\nreturn "
            . var_export($definitions, true) . ";\n";
    }

    /**
     * 从缓存文件还原路由表。
     *
     * @param string $file 缓存文件
     * @return self 新路由器
     * @throws \RuntimeException 文件损坏时抛出
     */
    public static function fromCacheFile(string $file): self
    {
        $definitions = require $file;

        if (!is_array($definitions)) {
            throw new \RuntimeException(sprintf('路由缓存损坏：[%s] 未返回数组', $file));
        }

        $router = new self();

        foreach ($definitions as $definition) {
            $route = Route::fromArray($definition);
            $router->restore($route);
        }

        return $router;
    }

    /**
     * 直接还原一条已构造的路由（缓存加载专用）。
     *
     * @param Route $route 路由
     * @return void
     */
    private function restore(Route $route): void
    {
        if ($route->isStatic()) {
            foreach ($route->methods as $method) {
                $this->static[$method . ' ' . $route->uri] = $route;
            }
        } else {
            $this->dynamic[] = $route;
        }

        foreach ($route->methods as $method) {
            $this->byPath[$route->uri][$method] = $route;
        }

        if ($route->name !== null) {
            $this->named[$route->name] = $route;
        }

        $this->all[] = $route;
    }

    /**
     * 合并分组前缀与当前 URI。
     *
     * @param string $uri 原始 URI
     * @return string 完整 URI
     */
    private function joinUri(string $uri): string
    {
        $prefix = '';

        foreach ($this->groupStack as $attributes) {
            $segment = trim((string) ($attributes['prefix'] ?? ''), '/');

            if ($segment !== '') {
                $prefix .= '/' . $segment;
            }
        }

        return $this->normalizePath($prefix . '/' . ltrim($uri, '/'));
    }

    /**
     * 合并分组名称前缀。
     *
     * @param string|null $name 路由名
     * @return string|null 完整名称
     */
    private function joinName(?string $name): ?string
    {
        $prefix = '';

        foreach ($this->groupStack as $attributes) {
            $prefix .= (string) ($attributes['as'] ?? '');
        }

        if ($name === null) {
            return $prefix === '' ? null : rtrim($prefix, '.');
        }

        return $prefix . $name;
    }

    /**
     * 合并分组中间件。
     *
     * @return list<string> 中间件列表
     */
    private function groupMiddleware(): array
    {
        $middleware = [];

        foreach ($this->groupStack as $attributes) {
            $middleware = [...$middleware, ...(array) ($attributes['middleware'] ?? [])];
        }

        return $middleware;
    }

    /**
     * 路径归一化：确保以 / 开头、无重复斜杠、无尾斜杠。
     *
     * @param string $path 原始路径
     * @return string 归一化路径
     */
    private function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');
        $path = (string) preg_replace('#/+#', '/', $path);

        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
