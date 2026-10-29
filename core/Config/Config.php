<?php

declare(strict_types=1);

namespace SunsetReverie\Config;

use SunsetReverie\Config\Exception\ConfigException;

/**
 * 配置仓库：点语法读取、目录加载、环境覆盖、深度合并。
 *
 * 约定（见规划文档 5.3）：
 * - config/{name}.php 的返回值即 {name}.* 子树；
 * - config/{env}/{name}.php 作为该环境的覆盖，深度合并；
 * - 配置文件禁止副作用（不查库、不读业务文件、不发请求）。
 */
final class Config
{
    /**
     * @param array<string, mixed> $items 配置项
     */
    public function __construct(private array $items = [])
    {
    }

    /**
     * 从目录加载配置。
     *
     * @param string $directory 配置目录（如 {basePath}/config）
     * @param string|null $env 环境名（如 production），用于加载 config/{env} 覆盖
     * @return self 配置实例
     * @throws ConfigException 配置文件未返回数组时抛出
     */
    public static function fromDirectory(string $directory, ?string $env = null): self
    {
        $items = self::loadDirectory($directory);

        if ($env !== null && $env !== '') {
            $items = self::deepMerge($items, self::loadDirectory($directory . '/' . $env));
        }

        return new self($items);
    }

    /**
     * 从缓存文件加载（PHP 数组）。
     *
     * @param string $file 缓存文件路径
     * @return self 配置实例
     * @throws ConfigException 文件损坏时抛出
     */
    public static function fromCache(string $file): self
    {
        $items = require $file;

        if (!is_array($items)) {
            throw new ConfigException(sprintf('配置缓存损坏：[%s] 未返回数组', $file));
        }

        return new self($items);
    }

    /**
     * 读取配置（点语法）。
     *
     * @param string $key 键名（如 app.name）
     * @param mixed $default 默认值
     * @return mixed 配置值
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * 写入配置（运行期覆盖，不写回文件）。
     *
     * @param string $key 键名
     * @param mixed $value 值
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $cursor = &$this->items;

        foreach ($segments as $index => $segment) {
            if ($index === count($segments) - 1) {
                $cursor[$segment] = $value;

                return;
            }

            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }
    }

    /**
     * 是否存在该键。
     *
     * @param string $key 键名
     * @return bool 存在返回 true
     */
    public function has(string $key): bool
    {
        $sentinel = new \stdClass();

        return $this->get($key, $sentinel) !== $sentinel;
    }

    /**
     * 获取全部配置。
     *
     * @return array<string, mixed> 配置数组
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * 合并一批配置（后者覆盖前者）。
     *
     * @param array<string, mixed> $items 配置
     * @return void
     */
    public function merge(array $items): void
    {
        $this->items = self::deepMerge($this->items, $items);
    }

    /**
     * 导出为可缓存的 PHP 代码。
     *
     * @return string 形如 <?php return [...];
     * @throws ConfigException 含闭包等不可导出值时抛出
     */
    public function export(): string
    {
        $this->assertExportable($this->items);

        return "<?php\n\ndeclare(strict_types=1);\n\n// 由 sr config:cache 生成，请勿手动修改\nreturn "
            . var_export($this->items, true) . ";\n";
    }

    /**
     * 加载目录下所有 *.php（文件名即一级键）。
     *
     * @param string $directory 目录
     * @return array<string, mixed> 配置数组
     * @throws ConfigException 返回值非数组时抛出
     */
    private static function loadDirectory(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $items = [];

        foreach (glob($directory . '/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            $value = require $file;

            if (!is_array($value)) {
                throw new ConfigException(sprintf('配置文件必须返回数组：[%s]', $file));
            }

            $items[$name] = $value;
        }

        return $items;
    }

    /**
     * 深度合并（数组递归合并，标量后者覆盖）。
     *
     * @param array<string, mixed> $base 基础配置
     * @param array<string, mixed> $override 覆盖配置
     * @return array<string, mixed> 合并结果
     */
    private static function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = self::deepMerge($base[$key], $value);

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * 校验是否可导出（禁止闭包与资源）。
     *
     * @param mixed $value 待校验值
     * @param string $path 当前路径（错误信息用）
     * @return void
     * @throws ConfigException 不可导出时抛出
     */
    private function assertExportable(mixed $value, string $path = ''): void
    {
        if ($value instanceof \Closure) {
            throw new ConfigException(sprintf('配置项 [%s] 含闭包，无法缓存', $path));
        }

        if (is_resource($value)) {
            throw new ConfigException(sprintf('配置项 [%s] 含资源句柄，无法缓存', $path));
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $this->assertExportable($item, $path === '' ? (string) $key : $path . '.' . $key);
            }
        }
    }
}
