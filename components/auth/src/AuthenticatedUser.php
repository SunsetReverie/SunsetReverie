<?php

declare(strict_types=1);

namespace SunsetReverie\Auth;

/**
 * 已认证主体（不可变值对象）。
 *
 * v0.5 直接从 Token 载荷构造，不做数据库查询；
 * 需要完整用户信息时，业务在控制器里用 id 自行查询（或后续接入 UserProvider）。
 */
final class AuthenticatedUser
{
    /**
     * @param string $id 主体标识（通常是用户 ID）
     * @param array<string, mixed> $claims 全部 Token 声明
     */
    public function __construct(
        public readonly string $id,
        private readonly array $claims = [],
    ) {
    }

    /**
     * 读取任意声明。
     *
     * @param string $key 声明名
     * @param mixed $default 默认值
     * @return mixed 声明值
     */
    public function claim(string $key, mixed $default = null): mixed
    {
        return $this->claims[$key] ?? $default;
    }

    /**
     * 获取全部声明。
     *
     * @return array<string, mixed> 声明数组
     */
    public function claims(): array
    {
        return $this->claims;
    }

    /**
     * 判断是否拥有某个角色（约定声明名 roles）。
     *
     * @param string $role 角色名
     * @return bool 拥有返回 true
     */
    public function hasRole(string $role): bool
    {
        $roles = $this->claims['roles'] ?? [];

        return is_array($roles) && in_array($role, $roles, true);
    }
}
