<?php

declare(strict_types=1);

namespace SunsetReverie\Support;

/**
 * 字符串工具：框架内部使用，不引入任何第三方依赖。
 */
final class Str
{
    /**
     * 生成 UUID v7（时间有序，便于数据库索引）。
     *
     * 布局：48 位毫秒时间戳 + 4 位版本 + 12 位随机 + 2 位变体 + 62 位随机。
     *
     * @return string 36 位小写 UUID
     */
    public static function uuid7(): string
    {
        $milliseconds = (int) (microtime(true) * 1000);
        $timeHex = str_pad(dechex($milliseconds), 12, '0', STR_PAD_LEFT);
        $random = bin2hex(random_bytes(10));
        $variant = dechex(8 | (hexdec($random[3]) & 0x3));

        return sprintf(
            '%s-%s-7%s-%s%s-%s',
            substr($timeHex, 0, 8),
            substr($timeHex, 8, 4),
            substr($random, 0, 3),
            $variant,
            substr($random, 4, 3),
            substr($random, 7, 12)
        );
    }

    /**
     * 生成指定长度的随机字符串（大小写字母 + 数字）。
     *
     * @param int $length 长度
     * @return string 随机串
     * @throws \InvalidArgumentException 长度小于 1 时抛出
     */
    public static function random(int $length = 16): string
    {
        if ($length < 1) {
            throw new \InvalidArgumentException('随机串长度必须大于 0');
        }

        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $max = strlen($alphabet) - 1;
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $alphabet[random_int(0, $max)];
        }

        return $result;
    }

    /**
     * 驼峰转蛇形（命名规范统一用）。
     *
     * @param string $value 输入
     * @return string 蛇形结果
     */
    public static function snake(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }

    /**
     * 判断字符串是否以给定子串开头（PHP 8 已内置，此处保留语义别名）。
     *
     * @param string $haystack 被搜索的字符串
     * @param string $needle 前缀
     * @return bool 是则 true
     */
    public static function startsWith(string $haystack, string $needle): bool
    {
        return str_starts_with($haystack, $needle);
    }
}
