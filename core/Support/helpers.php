<?php

/**
 * 全局辅助函数（无命名空间）。
 *
 * 只放"短小、无状态、几乎所有项目都会用"的函数；
 * 每个函数都必须用 function_exists 保护，避免与第三方库冲突。
 */

declare(strict_types=1);

if (!function_exists('env')) {
    /**
     * 读取环境变量（自动类型转换）。
     *
     * @param string $key 变量名
     * @param mixed $default 默认值
     * @return mixed 变量值
     */
    function env(string $key, mixed $default = null): mixed
    {
        return \SunsetReverie\Config\Env::get($key, $default);
    }
}

if (!function_exists('config')) {
    /**
     * 读取配置项（点语法）。
     *
     * @param string $key 键名，如 app.name
     * @param mixed $default 默认值
     * @return mixed 配置值
     */
    function config(string $key, mixed $default = null): mixed
    {
        try {
            return \SunsetReverie\Foundation\Application::getInstance()->config()->get($key, $default);
        } catch (\RuntimeException) {
            return $default;
        }
    }
}
