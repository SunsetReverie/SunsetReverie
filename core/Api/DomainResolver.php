<?php

declare(strict_types=1);

namespace SunsetReverie\Api;

use SunsetReverie\Http\Request;

/**
 * 域解析器：请求 → API 域。
 *
 * 四段式优先级（命中即返回，不继续往下猜）：
 * 1. **Host**：请求域名与域配置的 hosts 匹配（多域名部署）；
 * 2. **Prefix**：路径首段等于域前缀（单域名多路径部署，最常用）；
 * 3. **Header**：`X-Api-Domain`（前置网关透传场景，需网关可信）；
 * 4. **Default**：配置中的 default 域。
 *
 * 安全约束：Header 方式会让客户端自选认证体系，只有在网关已校验并剥离
 * 该头的前提下才应启用（`api.allow_header_detection`）。
 */
final class DomainResolver
{
    /**
     * @param DomainRegistry $registry 域注册表
     * @param bool $allowHeaderDetection 是否允许通过请求头决定域
     */
    public function __construct(
        private readonly DomainRegistry $registry,
        private readonly bool $allowHeaderDetection = false,
    ) {
    }

    /**
     * 解析请求所属的域。
     *
     * @param Request $request 当前请求
     * @return ApiDomain 域定义
     */
    public function resolve(Request $request): ApiDomain
    {
        if ($this->registry->isEmpty()) {
            return new ApiDomain(name: $this->registry->defaultName());
        }

        $byHost = $this->resolveByHost($request);

        if ($byHost !== null) {
            return $byHost;
        }

        $byPrefix = $this->resolveByPrefix($request);

        if ($byPrefix !== null) {
            return $byPrefix;
        }

        if ($this->allowHeaderDetection) {
            $byHeader = $this->resolveByHeader($request);

            if ($byHeader !== null) {
                return $byHeader;
            }
        }

        return $this->registry->defaultDomain();
    }

    /**
     * 按 Host 解析。
     *
     * @param Request $request 当前请求
     * @return ApiDomain|null 域定义
     */
    private function resolveByHost(Request $request): ?ApiDomain
    {
        $host = $request->host();

        if ($host === '') {
            return null;
        }

        foreach ($this->registry->all() as $domain) {
            if ($domain->matchesHost($host)) {
                return $domain;
            }
        }

        return null;
    }

    /**
     * 按路径前缀解析（取最长匹配，避免 `/app` 抢走 `/app-admin`）。
     *
     * @param Request $request 当前请求
     * @return ApiDomain|null 域定义
     */
    private function resolveByPrefix(Request $request): ?ApiDomain
    {
        $matched = null;

        foreach ($this->registry->all() as $domain) {
            if (!$domain->matchesPrefix($request->path)) {
                continue;
            }

            if ($matched === null || strlen($domain->prefix) > strlen($matched->prefix)) {
                $matched = $domain;
            }
        }

        return $matched;
    }

    /**
     * 按请求头解析。
     *
     * @param Request $request 当前请求
     * @return ApiDomain|null 域定义
     */
    private function resolveByHeader(Request $request): ?ApiDomain
    {
        $name = (string) $request->header('x-api-domain', '');

        return $name !== '' && $this->registry->has($name) ? $this->registry->get($name) : null;
    }
}
