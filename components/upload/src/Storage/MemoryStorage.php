<?php

declare(strict_types=1);

namespace SunsetReverie\Upload\Storage;

/**
 * 内存存储：仅用于单元测试，进程结束即丢失。
 */
final class MemoryStorage implements StorageInterface
{
    /** @var array<string, string> 路径 => 内容 */
    private array $files = [];

    /**
     * @param string $urlPrefix URL 前缀
     */
    public function __construct(private readonly string $urlPrefix = '/uploads')
    {
    }

    /**
     * {@inheritDoc}
     */
    public function put(string $path, string $sourcePath): void
    {
        $this->files[$path] = (string) file_get_contents($sourcePath);
    }

    /**
     * {@inheritDoc}
     */
    public function putContents(string $path, string $contents): void
    {
        $this->files[$path] = $contents;
    }

    /**
     * {@inheritDoc}
     */
    public function exists(string $path): bool
    {
        return isset($this->files[$path]);
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $path): bool
    {
        unset($this->files[$path]);

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function url(string $path): string
    {
        return rtrim($this->urlPrefix, '/') . '/' . ltrim($path, '/');
    }

    /**
     * {@inheritDoc}
     */
    public function path(string $path): string
    {
        return '';
    }

    /**
     * 获取已存内容（测试断言之用）。
     *
     * @param string $path 相对路径
     * @return string|null 内容
     */
    public function contents(string $path): ?string
    {
        return $this->files[$path] ?? null;
    }
}
