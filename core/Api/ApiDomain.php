<?php

declare(strict_types=1);

namespace SunsetReverie\Api;

/**
 * API 域定义（不可变值对象）。
 *
 * 一个域 = 一套接口（后台 / 前台 / App / 小程序），
 * 拥有独立的路由前缀、中间件栈、守卫与错误码段位。
 */
final class ApiDomain
{
    /**
     * @param string $name 域名标识（admin / web / app / miniapp）
     * @param list<string> $hosts 绑定域名（为空表示不按域名区分）
     * @param string $prefix 路径前缀（不含斜杠）
     * @param list<string> $middleware 域级中间件
     * @param string|null $guard 默认守卫名
     * @param list<string> $versions 支持的版本号（如 v1、v2）
     * @param int|null $errorSegment 业务错误码段位（51 → 51001）
     */
    public function __construct(
        public readonly string $name,
        public readonly array $hosts = [],
        public readonly string $prefix = '',
        public readonly array $middleware = [],
        public readonly ?string $guard = null,
        public readonly array $versions = [],
        public readonly ?int $errorSegment = null,
        /** @var array<string, string> 域级中间件别名（别名 => 类名），覆盖全局同名别名 */
        public readonly array $middlewareAliases = [],
    ) {
    }

    /**
     * 由配置数组构造。
     *
     * @param string $name 域名标识
     * @param array<string, mixed> $config 域配置
     * @return self 域定义
     */
    public static function fromArray(string $name, array $config): self
    {
        return new self(
            name: $name,
            hosts: array_values((array) ($config['hosts'] ?? [])),
            prefix: trim((string) ($config['prefix'] ?? $name), '/'),
            middleware: array_values((array) ($config['middleware'] ?? [])),
            guard: isset($config['guard']) ? (string) $config['guard'] : null,
            versions: array_values((array) ($config['versions'] ?? [])),
            errorSegment: isset($config['error_segment']) ? (int) $config['error_segment'] : null,
            middlewareAliases: array_map('strval', (array) ($config['middleware_aliases'] ?? [])),
        );
    }

    /**
     * 是否绑定了该 Host（忽略端口与大小写）。
     *
     * @param string $host 请求 Host
     * @return bool 匹配返回 true
     */
    public function matchesHost(string $host): bool
    {
        if ($this->hosts === []) {
            return false;
        }

        $target = strtolower(explode(':', $host)[0]);

        foreach ($this->hosts as $candidate) {
            if (strtolower($candidate) === $target) {
                return true;
            }
        }

        return false;
    }

    /**
     * 路径是否以本域前缀开头。
     *
     * @param string $path 请求路径
     * @return bool 匹配返回 true
     */
    public function matchesPrefix(string $path): bool
    {
        if ($this->prefix === '') {
            return false;
        }

        return $path === '/' . $this->prefix
            || str_starts_with($path, '/' . $this->prefix . '/');
    }
}
