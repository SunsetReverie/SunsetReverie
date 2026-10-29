<?php

declare(strict_types=1);

namespace SunsetReverie\Routing;

/**
 * 单条路由定义。
 *
 * handler 支持三种形态：
 * 1. Closure：直接调用；
 * 2. [ClassName::class, 'method']：由容器解析实例后调用；
 * 3. 'ClassName@method'：同上，字符串写法。
 */
final class Route
{
    /** @var string|null 编译后的匹配正则（静态路由为 null） */
    private ?string $regex = null;

    /** @var list<string> 参数名列表 */
    private array $parameters = [];

    /**
     * @param list<string> $methods 允许的方法（大写）
     * @param string $uri 路由路径（如 /users/{id}）
     * @param mixed $handler 处理器
     * @param string|null $name 路由名
     */
    public function __construct(
        public readonly array $methods,
        public readonly string $uri,
        public readonly mixed $handler,
        public readonly ?string $name = null,
    ) {
        $this->compile();
    }

    /**
     * 是否支持该 HTTP 方法。
     *
     * @param string $method 方法（大写）
     * @return bool 支持返回 true
     */
    public function supports(string $method): bool
    {
        return in_array('ANY', $this->methods, true) || in_array($method, $this->methods, true);
    }

    /**
     * 是否为静态路由（无占位符）。
     *
     * @return bool 静态返回 true
     */
    public function isStatic(): bool
    {
        return $this->regex === null;
    }

    /**
     * 尝试匹配路径。
     *
     * @param string $path 请求路径
     * @return array<string, string>|null 命中返回参数数组，未命中返回 null
     */
    public function match(string $path): ?array
    {
        if ($this->regex === null) {
            return $this->uri === $path ? [] : null;
        }

        if (!preg_match($this->regex, $path, $matches)) {
            return null;
        }

        $params = [];

        foreach ($this->parameters as $index => $name) {
            $params[$name] = $matches[$index + 1] ?? '';
        }

        return $params;
    }

    /**
     * 编译路径为正则（含 {name} 占位符时）。
     *
     * @return void
     */
    private function compile(): void
    {
        if (!str_contains($this->uri, '{')) {
            return;
        }

        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            function (array $m): string {
                $this->parameters[] = $m[1];

                return '([^/]+)';
            },
            $this->uri
        );

        $this->regex = '#^' . $pattern . '$#';
    }
}
