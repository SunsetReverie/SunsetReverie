<?php

declare(strict_types=1);

namespace SunsetReverie\Config;

use SunsetReverie\Config\Exception\EnvParseException;

/**
 * .env 解析器（零依赖）。
 *
 * 支持：注释、空行、单/双引号、${VAR} 引用展开、行尾注释。
 * 不支持：多行值、export 前缀（保持极简，避免隐藏行为）。
 */
final class EnvLoader
{
    /**
     * 加载 .env 文件到环境变量。
     *
     * @param string $file 文件路径
     * @return int 成功解析的变量数量
     * @throws EnvParseException 语法错误时抛出
     */
    public static function load(string $file): int
    {
        if (!is_file($file) || !is_readable($file)) {
            return 0;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $loaded = 0;

        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $position = strpos($trimmed, '=');

            if ($position === false) {
                throw new EnvParseException('缺少等号', $lineNumber, $file);
            }

            $key = trim(substr($trimmed, 0, $position));
            $rawValue = trim(substr($trimmed, $position + 1));

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $key) !== 1) {
                throw new EnvParseException(sprintf('非法变量名 [%s]', $key), $lineNumber, $file);
            }

            Env::set($key, self::parseValue($rawValue, $lineNumber, $file));
            $loaded++;
        }

        return $loaded;
    }

    /**
     * 解析值：去除引号、展开变量引用、处理行尾注释。
     *
     * @param string $raw 原始值
     * @param int $line 行号
     * @param string $file 文件路径
     * @return string 解析后的值
     * @throws EnvParseException 引号未闭合时抛出
     */
    private static function parseValue(string $raw, int $line, string $file): string
    {
        if ($raw === '') {
            return '';
        }

        $quote = $raw[0];

        if ($quote === '"' || $quote === "'") {
            $end = strrpos($raw, $quote);

            if ($end === false || $end === 0) {
                throw new EnvParseException('引号未闭合', $line, $file);
            }

            $value = substr($raw, 1, $end - 1);

            return $quote === '"' ? self::expand($value) : $value;
        }

        // 未加引号：截断行尾注释（# 前需有空白）
        if (preg_match('/\s+#/', $raw, $m, PREG_OFFSET_CAPTURE) === 1) {
            $raw = substr($raw, 0, (int) $m[0][1]);
        }

        return self::expand(trim($raw));
    }

    /**
     * 展开 ${VAR} 引用。
     *
     * @param string $value 原始值
     * @return string 展开后的值
     */
    private static function expand(string $value): string
    {
        return (string) preg_replace_callback(
            '/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            static fn (array $m): string => Env::string($m[1], ''),
            $value
        );
    }
}
