<?php

declare(strict_types=1);

namespace SunsetReverie\Http;

use InvalidArgumentException;
use SunsetReverie\Container\Container;
use SunsetReverie\Http\Middleware\HasParameters;

/**
 * 中间件解析：把路由上声明的字符串（别名 / 类名 / 带参数形式）解析成实例。
 *
 * 支持三种写法：
 *   'auth'                          → 按别名表解析
 *   'throttle:300,1'                → 别名 + 参数（交给 HasParameters）
 *   \App\Api\Admin\Middleware\X::class  → 直接实例化
 *
 * 别名优先级：域级别名 > 全局别名 > 视为类名。
 * 这样同一个 `auth` 在 admin 域与 web 域可以指向完全不同的实现（见文档 6.7.3）。
 */
final class MiddlewareResolver
{
    /**
     * @param Container $container 容器（用于实例化中间件）
     * @param array<string, string> $aliases 全局别名表（别名 => 类名）
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $aliases = [],
    ) {
    }

    /**
     * 批量解析中间件声明。
     *
     * @param list<string|object|callable> $middleware 声明列表
     * @param array<string, string> $domainAliases 域级别名（覆盖全局）
     * @return list<object|callable> 实例列表
     * @throws InvalidArgumentException 无法解析时抛出
     */
    public function resolve(array $middleware, array $domainAliases = []): array
    {
        $resolved = [];

        foreach ($middleware as $item) {
            $resolved[] = is_string($item)
                ? $this->resolveOne($item, $domainAliases)
                : $item;
        }

        return $resolved;
    }

    /**
     * 解析单个中间件声明。
     *
     * @param string $spec 声明（别名 / 类名 / 带参数）
     * @param array<string, string> $domainAliases 域级别名
     * @return object 中间件实例
     * @throws InvalidArgumentException 无法解析时抛出
     */
    private function resolveOne(string $spec, array $domainAliases): object
    {
        [$name, $parameters] = $this->split($spec);

        $class = $domainAliases[$name] ?? $this->aliases[$name] ?? $name;

        if (!class_exists($class)) {
            $available = array_keys($domainAliases + $this->aliases);

            throw new InvalidArgumentException(sprintf(
                '中间件 [%s] 无法解析：既不是已注册别名，也不是存在的类。可用别名：%s',
                $name,
                $available === [] ? '（无）' : implode('、', $available)
            ));
        }

        $instance = $this->container->get($class);

        if ($parameters === []) {
            return $instance;
        }

        if (!$instance instanceof HasParameters) {
            throw new InvalidArgumentException(sprintf(
                '中间件 [%s] 不接受参数（声明为 %s），请去掉冒号后的内容',
                $class,
                $spec
            ));
        }

        return $instance->withParameters($parameters);
    }

    /**
     * 拆分 `name:arg1,arg2` 形式。
     *
     * @param string $spec 声明
     * @return array{0: string, 1: list<string>} 名称与参数
     */
    private function split(string $spec): array
    {
        if (!str_contains($spec, ':')) {
            return [$spec, []];
        }

        [$name, $argumentString] = explode(':', $spec, 2);

        $parameters = array_values(array_filter(
            array_map('trim', explode(',', $argumentString)),
            static fn (string $value): bool => $value !== ''
        ));

        return [$name, $parameters];
    }
}
