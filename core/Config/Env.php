<?php

declare(strict_types=1);

namespace SunsetReverie\Config;

use SunsetReverie\Config\Exception\ConfigException;

/**
 * 环境变量读取入口：统一类型转换，避免业务代码里到处 (bool) / (int)。
 *
 * 约定（见规划文档 5.4）：
 * - 优先读取真实环境变量（getenv），其次 $_ENV / $_SERVER；
 * - 转换规则：true/false/null/(true)/(false) 及数字自动转换，其余保持字符串；
 * - 不得在运行期依赖 env() 的"可变性"：配置应尽早固化。
 */
final class Env
{
    /**
     * 读取环境变量。
     *
     * @param string $key 变量名
     * @param mixed $default 默认值
     * @return mixed 转换后的值
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::raw($key);

        if ($value === null) {
            return $default;
        }

        return self::normalize($value);
    }

    /**
     * 读取布尔值。
     *
     * @param string $key 变量名
     * @param bool $default 默认值
     * @return bool 布尔值
     */
    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default);

        return is_bool($value) ? $value : (bool) $value;
    }

    /**
     * 读取整数。
     *
     * @param string $key 变量名
     * @param int $default 默认值
     * @return int 整数值
     */
    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key, $default);

        return is_int($value) ? $value : (int) $value;
    }

    /**
     * 读取字符串。
     *
     * @param string $key 变量名
     * @param string $default 默认值
     * @return string 字符串值
     */
    public static function string(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * 读取必填变量，缺失时抛异常。
     *
     * @param string $key 变量名
     * @return string 变量值
     * @throws ConfigException 变量缺失或为空时抛出
     */
    public static function required(string $key): string
    {
        $value = self::string($key);

        if ($value === '') {
            throw new ConfigException(sprintf(
                '缺少必需的环境变量 [%s]，请在 .env 或部署环境中配置',
                $key
            ));
        }

        return $value;
    }

    /**
     * 写入环境变量（供 EnvLoader 使用）。
     *
     * @param string $key 变量名
     * @param string $value 值
     * @param bool $overwrite 是否覆盖已存在的真实环境变量
     * @return void
     */
    public static function set(string $key, string $value, bool $overwrite = false): void
    {
        if (!$overwrite && self::raw($key) !== null) {
            return;
        }

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;

        // CLI 与部分 FPM 配置下 getenv 需要显式设置
        putenv($key . '=' . $value);
    }

    /**
     * 原始值读取（不做类型转换）。
     *
     * @param string $key 变量名
     * @return string|null 原始字符串，缺失返回 null
     */
    private static function raw(string $key): ?string
    {
        $value = getenv($key);

        if ($value !== false) {
            return $value;
        }

        if (isset($_ENV[$key]) && is_scalar($_ENV[$key])) {
            return (string) $_ENV[$key];
        }

        if (isset($_SERVER[$key]) && is_scalar($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }

        return null;
    }

    /**
     * 类型归一化。
     *
     * @param string $value 原始字符串
     * @return mixed 转换后的值
     */
    private static function normalize(string $value): mixed
    {
        return match (strtolower(trim($value))) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => self::normalizeNumber($value),
        };
    }

    /**
     * 数字字符串转数字（保留前导零的字符串不转换）。
     *
     * @param string $value 原始字符串
     * @return mixed 数字或原字符串
     */
    private static function normalizeNumber(string $value): mixed
    {
        $trimmed = trim($value);

        if ($trimmed === '' || preg_match('/^0\d+$/', $trimmed) === 1) {
            return $value;
        }

        if (preg_match('/^-?\d+$/', $trimmed) === 1) {
            return (int) $trimmed;
        }

        if (preg_match('/^-?\d+\.\d+$/', $trimmed) === 1) {
            return (float) $trimmed;
        }

        return $value;
    }
}
