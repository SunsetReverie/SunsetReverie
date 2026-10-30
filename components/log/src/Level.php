<?php

declare(strict_types=1);

namespace SunsetReverie\Log;

/**
 * 日志级别（PSR-3 的八级，按严重程度递增）。
 */
enum Level: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Notice = 'notice';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';
    case Alert = 'alert';
    case Emergency = 'emergency';

    /**
     * 严重程度数值（越大越严重）。
     *
     * @return int 严重度
     */
    public function severity(): int
    {
        return match ($this) {
            self::Debug => 100,
            self::Info => 200,
            self::Notice => 250,
            self::Warning => 300,
            self::Error => 400,
            self::Critical => 500,
            self::Alert => 550,
            self::Emergency => 600,
        };
    }

    /**
     * 是否达到指定最低级别。
     *
     * @param self $minimum 最低级别
     * @return bool 达到返回 true
     */
    public function allows(self $minimum): bool
    {
        return $this->severity() >= $minimum->severity();
    }

    /**
     * 由字符串解析级别（非法值回退到 debug）。
     *
     * @param string $value 级别字符串
     * @return self 级别
     */
    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::Debug;
    }
}
