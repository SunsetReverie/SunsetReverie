<?php

declare(strict_types=1);

namespace SunsetReverie\Api;

/**
 * 当前请求所属域的上下文（scoped 单例）。
 *
 * 用途：
 * - 业务错误码按域段位拼接（51 + 1 → 51001）；
 * - 缓存键、上传目录按域隔离。
 *
 * 由 ResolveDomain 中间件在请求早期写入；未解析时所有方法安全降级。
 */
final class DomainContext
{
    private ?ApiDomain $domain = null;

    /**
     * 设置当前域。
     *
     * @param ApiDomain $domain 域定义
     * @return void
     */
    public function set(ApiDomain $domain): void
    {
        $this->domain = $domain;
    }

    /**
     * 重置（长驻模式下每请求开始时调用）。
     *
     * @return void
     */
    public function reset(): void
    {
        $this->domain = null;
    }

    /**
     * 获取当前域。
     *
     * @return ApiDomain|null 域定义
     */
    public function current(): ?ApiDomain
    {
        return $this->domain;
    }

    /**
     * 获取当前域名（未解析返回空串）。
     *
     * @return string 域名
     */
    public function name(): string
    {
        // $domain 可空（CLI 下没有请求域），?-> 是必需的
        // @phpstan-ignore nullsafe.neverNull
        return $this->domain?->name ?? '';
    }

    /**
     * 按域段位拼接业务错误码。
     *
     * 例：admin 域 `error_segment = 51`，传入 7 → 返回 51007。
     * 未配置段位时原样返回，便于单域项目直接使用业务码。
     *
     * @param int $sequence 域内序号（1～999）
     * @return int 完整错误码
     * @throws \InvalidArgumentException 序号越界时抛出
     */
    public function businessCode(int $sequence): int
    {
        if ($sequence < 1 || $sequence > 999) {
            throw new \InvalidArgumentException(sprintf(
                '业务错误码序号必须在 1～999 之间，收到 [%d]',
                $sequence
            ));
        }

        $segment = $this->domain?->errorSegment;

        return $segment === null ? $sequence : $segment * 1000 + $sequence;
    }
}
