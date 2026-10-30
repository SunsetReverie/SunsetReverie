<?php

declare(strict_types=1);

namespace SunsetReverie\Api;

use SunsetReverie\Api\Exception\DomainNotFoundException;

/**
 * 域注册表：从 `config/api.php` 构建，提供按名查询与遍历。
 */
final class DomainRegistry
{
    /** @var array<string, ApiDomain> 域定义 */
    private array $domains = [];

    /** @var string 默认域名 */
    private readonly string $default;

    /**
     * @param array<string, mixed> $config api 配置（default + domains）
     * @throws DomainNotFoundException 配置非法时抛出
     */
    public function __construct(array $config)
    {
        $definitions = (array) ($config['domains'] ?? []);

        foreach ($definitions as $name => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $this->domains[(string) $name] = ApiDomain::fromArray((string) $name, $definition);
        }

        $this->default = (string) ($config['default'] ?? array_key_first($this->domains) ?? 'web');

        if ($this->domains !== [] && !isset($this->domains[$this->default])) {
            throw new DomainNotFoundException(sprintf(
                '默认域 [%s] 未在 domains 中定义，可用：%s',
                $this->default,
                implode(', ', array_keys($this->domains))
            ));
        }

        $this->assertPrefixesAreUnique();
    }

    /**
     * 是否配置了任何域。
     *
     * @return bool 有域返回 true
     */
    public function isEmpty(): bool
    {
        return $this->domains === [];
    }

    /**
     * 获取全部域。
     *
     * @return array<string, ApiDomain> 域名 => 域定义
     */
    public function all(): array
    {
        return $this->domains;
    }

    /**
     * 按名获取域。
     *
     * @param string $name 域名
     * @return ApiDomain 域定义
     * @throws DomainNotFoundException 不存在时抛出
     */
    public function get(string $name): ApiDomain
    {
        return $this->domains[$name] ?? throw new DomainNotFoundException(sprintf(
            '未定义的 API 域 [%s]，可用：%s',
            $name,
            implode(', ', array_keys($this->domains)) ?: '（无）'
        ));
    }

    /**
     * 是否存在该域。
     *
     * @param string $name 域名
     * @return bool 存在返回 true
     */
    public function has(string $name): bool
    {
        return isset($this->domains[$name]);
    }

    /**
     * 获取默认域名。
     *
     * @return string 域名
     */
    public function defaultName(): string
    {
        return $this->default;
    }

    /**
     * 获取默认域。
     *
     * @return ApiDomain 域定义
     * @throws DomainNotFoundException 无域配置时抛出
     */
    public function defaultDomain(): ApiDomain
    {
        return $this->get($this->default);
    }

    /**
     * 校验各域前缀不重复（前缀是 URL 的第一段，重复会导致路由归属不明）。
     *
     * @return void
     * @throws DomainNotFoundException 前缀重复时抛出
     */
    private function assertPrefixesAreUnique(): void
    {
        $seen = [];

        foreach ($this->domains as $domain) {
            $prefix = $domain->prefix;

            if ($prefix === '') {
                continue;
            }

            if (isset($seen[$prefix])) {
                throw new DomainNotFoundException(sprintf(
                    '域 [%s] 与 [%s] 使用了相同前缀 [%s]，请修改 config/api.php',
                    $domain->name,
                    $seen[$prefix],
                    $prefix
                ));
            }

            $seen[$prefix] = $domain->name;
        }
    }
}
