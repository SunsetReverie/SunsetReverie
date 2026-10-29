<?php

declare(strict_types=1);

namespace SunsetReverie\Container;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use SunsetReverie\Container\Exception\CircularDependencyException;
use SunsetReverie\Container\Exception\ContainerException;

/**
 * 服务容器：绑定、单例、实例与构造函数自动注入。
 *
 * 设计边界（见规划文档 5.2）：
 * - 只做构造函数注入，不做属性注入、不扫描 public 方法；
 * - 标量参数必须显式提供或使用默认值，不做隐式猜测；
 * - 循环依赖立即报错并输出完整链路，不做静默兜底。
 */
final class Container
{
    /** @var array<string, array{concrete: Closure|string, shared: bool}> */
    private array $bindings = [];

    /** @var array<string, object> 已解析的单例 */
    private array $instances = [];

    /** @var list<string> 当前解析链（用于循环依赖检测） */
    private array $buildStack = [];

    /**
     * 绑定一个可解析条目。
     *
     * @param string $id 抽象标识（类名或字符串键）
     * @param Closure|string|null $concrete 具体实现（闭包、类名或别名）；null 表示绑定自身
     * @param bool $shared 是否共享实例
     * @return void
     */
    public function bind(string $id, Closure|string|null $concrete = null, bool $shared = false): void
    {
        $this->bindings[$id] = [
            'concrete' => $concrete ?? $id,
            'shared' => $shared,
        ];
    }

    /**
     * 绑定共享单例。
     *
     * @param string $id 抽象标识
     * @param Closure|string|null $concrete 具体实现
     * @return void
     */
    public function singleton(string $id, Closure|string|null $concrete = null): void
    {
        $this->bind($id, $concrete, true);
    }

    /**
     * 直接注册一个已存在的实例。
     *
     * @param string $id 抽象标识
     * @param object $instance 实例
     * @return void
     */
    public function instance(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
    }

    /**
     * 是否可解析该标识。
     *
     * @param string $id 抽象标识
     * @return bool 可解析返回 true
     */
    public function has(string $id): bool
    {
        return isset($this->instances[$id])
            || isset($this->bindings[$id])
            || class_exists($id);
    }

    /**
     * 解析条目。
     *
     * @param string $id 抽象标识
     * @param array<string, mixed> $parameters 显式参数（标量场景使用）
     * @return mixed 解析结果
     * @throws ContainerException 无法解析时抛出
     * @throws CircularDependencyException 存在循环依赖时抛出
     */
    public function get(string $id, array $parameters = []): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        $binding = $this->bindings[$id] ?? null;

        if ($binding === null) {
            if (!class_exists($id)) {
                throw new ContainerException(sprintf('无法解析 [%s]：未绑定且类不存在', $id));
            }

            return $this->build($id, $parameters);
        }

        $concrete = $binding['concrete'];

        if ($concrete instanceof Closure) {
            $object = $concrete($this, $parameters);
        } elseif ($concrete === $id) {
            // 绑定到自身（singleton(SomeClass::class)）：直接实例化，避免递归
            $object = $this->build($id, $parameters);
        } else {
            $object = $this->get($concrete, $parameters);
        }

        if ($binding['shared']) {
            $this->instances[$id] = $object;
        }

        return $object;
    }

    /**
     * 实例化类并自动注入构造函数依赖。
     *
     * @param string $class 类名
     * @param array<string, mixed> $parameters 显式参数
     * @return object 实例
     * @throws CircularDependencyException 循环依赖时抛出
     * @throws ContainerException 参数无法解析时抛出
     */
    private function build(string $class, array $parameters = []): object
    {
        if (in_array($class, $this->buildStack, true)) {
            throw new CircularDependencyException([...$this->buildStack, $class]);
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new ContainerException(sprintf('[%s] 不可实例化（接口或抽象类需先绑定实现）', $class));
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new $class();
        }

        $this->buildStack[] = $class;

        try {
            $arguments = [];

            foreach ($constructor->getParameters() as $parameter) {
                $arguments[] = $this->resolveParameter($parameter, $parameters);
            }

            return $reflection->newInstanceArgs($arguments);
        } finally {
            array_pop($this->buildStack);
        }
    }

    /**
     * 解析单个构造参数。
     *
     * @param ReflectionParameter $parameter 参数反射
     * @param array<string, mixed> $parameters 显式参数
     * @return mixed 参数值
     * @throws ContainerException 无法解析时抛出
     */
    private function resolveParameter(ReflectionParameter $parameter, array $parameters): mixed
    {
        $name = $parameter->getName();

        if (array_key_exists($name, $parameters)) {
            return $parameters[$name];
        }

        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $dependency = $type->getName();

            return $this->get($dependency);
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($parameter->allowsNull()) {
            return null;
        }

        throw new ContainerException(sprintf(
            '无法解析参数 [$%s]：标量参数需显式传入（当前类：%s）',
            $name,
            $parameter->getDeclaringClass()?->getName() ?? 'unknown'
        ));
    }

    /**
     * 清除已解析的实例（测试与热更新场景使用）。
     *
     * @param string|null $id 指定标识；null 表示清空全部
     * @return void
     */
    public function forget(?string $id = null): void
    {
        if ($id === null) {
            $this->instances = [];

            return;
        }

        unset($this->instances[$id]);
    }

    /**
     * 获取全部已解析实例（诊断用）。
     *
     * @return array<string, object> 标识 => 实例
     */
    public function resolved(): array
    {
        return $this->instances;
    }
}
