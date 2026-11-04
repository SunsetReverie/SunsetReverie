<?php

declare(strict_types=1);

namespace SunsetReverie\Auth;

use Closure;
use SunsetReverie\Api\DomainContext;
use SunsetReverie\Cache\CacheManager;
use SunsetReverie\Cache\CacheStoreInterface;
use SunsetReverie\Container\Container;
use SunsetReverie\Http\Request;
use SunsetReverie\Auth\Exception\AuthenticationException;
use SunsetReverie\Auth\Guard\JwtGuard;
use SunsetReverie\Auth\Jwt\JwtCodec;

/**
 * 认证管理器：按配置创建守卫，支持 extend 注册自定义守卫。
 */
final class AuthManager
{
    /** @var array<string, object> 已创建的守卫 */
    private array $guards = [];

    /** @var array<string, Closure(array<string, mixed>): object> 自定义守卫工厂 */
    private array $extensions = [];

    /**
     * @param array<string, mixed> $config 认证配置（default + guards）
     * @param DomainContext|null $domainContext 域上下文（多域时校验 aud）
     */
    public function __construct(
        private readonly array $config,
        private readonly ?DomainContext $domainContext = null,
        private readonly ?Container $container = null,
    ) {
    }

    /**
     * 当前登录用户（无请求上下文或未登录时返回 null）。
     *
     * 供 `Auth::user()` 这类无参调用使用；控制器里更推荐直接用
     * `$request->attribute(Authenticate::ATTRIBUTE)`，依赖更显式。
     *
     * @return AuthenticatedUser|null 当前用户
     */
    public function user(): ?AuthenticatedUser
    {
        if ($this->container === null || !$this->container->has(Request::class)) {
            return null;
        }

        $request = $this->container->get(Request::class);
        $user = $request instanceof Request
            ? $request->attribute(\SunsetReverie\Auth\Middleware\Authenticate::ATTRIBUTE)
            : null;

        return $user instanceof AuthenticatedUser ? $user : null;
    }

    /**
     * 是否已登录。
     *
     * @return bool 已登录返回 true
     */
    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * 当前用户 ID（未登录返回 null）。
     *
     * @return string|null 用户标识
     */
    public function id(): ?string
    {
        return $this->user()?->id;
    }

    /**
     * 获取守卫。
     *
     * @param string|null $name 守卫名，null 表示默认
     * @return object 守卫实例
     * @throws AuthenticationException 配置或驱动缺失时抛出
     */
    public function guard(?string $name = null): object
    {
        $name ??= (string) ($this->config['default'] ?? 'jwt');

        if (isset($this->guards[$name])) {
            return $this->guards[$name];
        }

        $guard = $this->createGuard($name);
        $this->guards[$name] = $guard;

        return $guard;
    }

    /**
     * 获取 JWT 守卫（最常用，做类型收敛）。
     *
     * @param string|null $name 守卫名
     * @return JwtGuard JWT 守卫
     * @throws AuthenticationException 守卫不是 JWT 类型时抛出
     */
    public function jwt(?string $name = null): JwtGuard
    {
        $guard = $this->guard($name);

        if (!$guard instanceof JwtGuard) {
            throw new AuthenticationException('当前守卫不是 JWT 类型');
        }

        return $guard;
    }

    /**
     * 注册自定义守卫工厂。
     *
     * @param string $driver 驱动名
     * @param Closure(array<string, mixed>): object $factory 工厂
     * @return self 当前实例
     * @throws AuthenticationException 同名驱动已注册时抛出
     */
    public function extend(string $driver, Closure $factory): self
    {
        if (isset($this->extensions[$driver])) {
            throw new AuthenticationException(sprintf('守卫驱动 [%s] 已注册', $driver));
        }

        $this->extensions[$driver] = $factory;

        return $this;
    }

    /**
     * 创建守卫实例。
     *
     * @param string $name 守卫名
     * @return object 守卫
     * @throws AuthenticationException 配置缺失或驱动不支持时抛出
     */
    private function createGuard(string $name): object
    {
        $config = $this->config['guards'][$name] ?? null;

        if (!is_array($config)) {
            throw new AuthenticationException(sprintf('未找到守卫配置 [%s]', $name));
        }

        $driver = (string) ($config['driver'] ?? 'jwt');

        if (isset($this->extensions[$driver])) {
            return ($this->extensions[$driver])($config);
        }

        if ($driver !== 'jwt') {
            throw new AuthenticationException(sprintf(
                '不支持的守卫驱动 [%s]，可用：jwt，或通过 extend() 注册',
                $driver
            ));
        }

        return new JwtGuard(
            new JwtCodec((string) ($config['secret'] ?? ''), (int) ($config['leeway'] ?? 30)),
            (int) ($config['ttl'] ?? 7200),
            (string) ($config['claim_key'] ?? 'sub'),
            $this->domainContext,
            (bool) ($config['enforce_audience'] ?? true),
            // refresh token 要能撤销，必须有缓存；缓存不可用时为 null，
            // 此时 refresh/issueTokens 会抛明确异常，issue/user 照常工作
            $this->cacheStore(),
            (int) ($config['refresh_ttl'] ?? 2592000),
            (int) ($config['refresh_grace'] ?? 30),
            (bool) ($config['enforce_version'] ?? false),
        );
    }

    /**
     * 取默认缓存存储。
     *
     * 缓存组件没装或没配时返回 null——认证本身不该因为缓存缺失而整体不可用，
     * 只是用不了双 Token。
     *
     * @return CacheStoreInterface|null 缓存存储
     */
    private function cacheStore(): ?CacheStoreInterface
    {
        $container = $this->container;

        // 没有容器（比如手工 new 出来测试）时没有缓存可用
        if ($container === null) {
            return null;
        }

        try {
            $manager = $container->get(CacheManager::class);
        } catch (\Throwable) {
            return null;
        }

        if (!$manager instanceof CacheManager) {
            return null;
        }

        try {
            return $manager->store();
        } catch (\Throwable) {
            return null;
        }
    }
}
