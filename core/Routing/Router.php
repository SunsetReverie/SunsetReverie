<?php

declare(strict_types=1);

namespace SunsetReverie\Routing;

use Closure;
use SunsetReverie\Routing\Exception\DuplicateRouteException;
use SunsetReverie\Routing\Exception\MethodNotAllowedException;
use SunsetReverie\Routing\Exception\RouteNotFoundException;

/**
 * 路由注册与匹配。
 *
 * v0.1 能力：
 * - 静态路径 O(1) 匹配；
 * - {name} 占位符（不含正则约束，v0.2 补 whereNumber 等）；
 * - 404 / 405 区分，405 回写 Allow 头；
 * - 重复注册在注册期抛异常。
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
        $uri = '/' . trim($uri, '/');
        $uri = $uri === '/' ? '/' : rtrim($uri, '/');
        $methods = array_map('strtoupper', $methods);
        $route = new Route($methods, $uri, $handler, $name);

        foreach ($methods as $method) {
            if (isset($this->static[$method . ' ' . $uri])) {
                throw new DuplicateRouteException($method, $uri);
            }

            $this->byPath[$uri][$method] = $route;
        }

        if ($route->isStatic()) {
            foreach ($methods as $method) {
                $this->static[$method . ' ' . $uri] = $route;
            }
        } else {
            $this->dynamic[] = $route;
        }

        if ($name !== null) {
            $this->named[$name] = $route;
        }

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
        $path = '/' . trim($path, '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');

        // 1) 静态路由 O(1)
        $static = $this->static[$method . ' ' . $path] ?? $this->static['ANY ' . $path] ?? null;

        if ($static !== null) {
            return ['route' => $static, 'params' => []];
        }

        // 2) 动态路由按注册顺序
        foreach ($this->dynamic as $route) {
            if (!$route->supports($method)) {
                continue;
            }

            $params = $route->match($path);

            if ($params !== null) {
                return ['route' => $route, 'params' => $params];
            }
        }

        // 3) 路径存在但方法不允许 → 405
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
     * 获取全部已注册路由（route:list 使用）。
     *
     * @return list<Route> 路由列表
     */
    public function routes(): array
    {
        return [...array_values($this->static), ...$this->dynamic];
    }

    /**
     * 批量注册（闭包内可直接使用 Router 方法）。
     *
     * @param Closure $callback 注册回调
     * @return void
     */
    public function group(Closure $callback): void
    {
        $callback($this);
    }
}
