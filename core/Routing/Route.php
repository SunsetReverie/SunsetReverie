<?php

declare(strict_types=1);

namespace SunsetReverie\Routing;

/**
 * 单条路由定义。
 *
 * handler 支持三种形态：
 * 1. Closure：直接调用（不可进入路由缓存）；
 * 2. [ClassName::class, 'method']：容器解析实例后调用（可缓存）；
 * 3. 'ClassName@method'：同上（可缓存）。
 */
final class Route
{
    /** @var array<string, string> 参数名 => 正则（不含锚点） */
    private array $wheres = [];

    /** @var list<string> 中间件类名或别名 */
    private array $middleware = [];

    /** @var string|null 编译后的匹配正则（静态路由为 null） */
    private ?string $regex = null;

    /** @var list<string> 参数名列表 */
    private array $parameters = [];

    /**
     * @param list<string> $methods 允许的方法（大写）
     * @param string $uri 路由路径（如 /users/{id}）
     * @param mixed $handler 处理器
     * @param string|null $name 路由名
     * @param list<string> $middleware 路由级中间件
     */
    public function __construct(
        public readonly array $methods,
        public readonly string $uri,
        public readonly mixed $handler,
        public ?string $name = null,
        array $middleware = [],
    ) {
        $this->middleware = array_values($middleware);
        $this->compile();
    }

    /**
     * 追加路由级中间件（返回自身，支持链式）。
     *
     * @param string|list<string> $middleware 中间件类名或别名
     * @return self 路由实例
     */
    public function middleware(string|array $middleware): self
    {
        foreach ((array) $middleware as $item) {
            $this->middleware[] = $item;
        }

        return $this;
    }

    /**
     * 设置参数约束。
     *
     * @param string $name 参数名
     * @param string $regex 正则（不含锚点）
     * @return self 路由实例
     * @throws \InvalidArgumentException 参数未在路径中声明时抛出
     */
    public function where(string $name, string $regex): self
    {
        if (!str_contains($this->uri, '{' . $name . '}')) {
            throw new \InvalidArgumentException(sprintf(
                '路由 [%s] 未声明参数 {%s}，无法设置约束',
                $this->uri,
                $name
            ));
        }

        $this->wheres[$name] = $regex;
        $this->compile();

        return $this;
    }

    /**
     * 设置名称为数字约束。
     *
     * @param string $name 参数名
     * @return self 路由实例
     */
    public function whereNumber(string $name): self
    {
        return $this->where($name, '[0-9]+');
    }

    /**
     * 设置名称为 UUID 约束。
     *
     * @param string $name 参数名
     * @return self 路由实例
     */
    public function whereUuid(string $name): self
    {
        return $this->where($name, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
    }

    /**
     * 设置名称为枚举集合约束。
     *
     * @param string $name 参数名
     * @param list<string> $values 允许值
     * @return self 路由实例
     */
    public function whereIn(string $name, array $values): self
    {
        return $this->where($name, implode('|', array_map(
            static fn (string $value): string => preg_quote($value, '#'),
            $values
        )));
    }

    /**
     * 重命名路由。
     *
     * @param string $name 新名称
     * @return self 路由实例
     */
    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
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
     * 获取路由级中间件。
     *
     * @return list<string> 中间件列表
     */
    public function middlewareList(): array
    {
        return $this->middleware;
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

        if (preg_match($this->regex, $path, $matches) !== 1) {
            return null;
        }

        $params = [];

        foreach ($this->parameters as $index => $name) {
            $params[$name] = $matches[$index + 1] ?? '';
        }

        return $params;
    }

    /**
     * 导出为可缓存的数组（闭包会抛异常）。
     *
     * @return array<string, mixed> 路由定义
     * @throws \RuntimeException 处理器为闭包时抛出
     */
    public function toArray(): array
    {
        if ($this->handler instanceof \Closure) {
            throw new \RuntimeException(sprintf(
                '路由 [%s %s] 使用闭包处理器，无法进入路由缓存；请改用控制器方法',
                implode('|', $this->methods),
                $this->uri
            ));
        }

        return [
            'methods' => $this->methods,
            'uri' => $this->uri,
            'handler' => $this->handler,
            'name' => $this->name,
            'middleware' => $this->middleware,
            'wheres' => $this->wheres,
        ];
    }

    /**
     * 从缓存数组重建路由。
     *
     * @param array<string, mixed> $definition 路由定义
     * @return self 路由实例
     */
    public static function fromArray(array $definition): self
    {
        $route = new self(
            methods: $definition['methods'],
            uri: $definition['uri'],
            handler: $definition['handler'],
            name: $definition['name'] ?? null,
            middleware: $definition['middleware'] ?? [],
        );

        foreach ($definition['wheres'] ?? [] as $name => $regex) {
            $route->where((string) $name, (string) $regex);
        }

        return $route;
    }

    /**
     * 编译路径为正则（含占位符时）。
     *
     * @return void
     */
    private function compile(): void
    {
        $this->parameters = [];
        $this->regex = null;

        if (!str_contains($this->uri, '{')) {
            return;
        }

        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            function (array $m): string {
                $this->parameters[] = $m[1];
                $constraint = $this->wheres[$m[1]] ?? '[^/]+';

                return '(' . $constraint . ')';
            },
            $this->uri
        );

        $this->regex = '#^' . $pattern . '$#';
    }
}
