<?php

declare(strict_types=1);

namespace SunsetReverie\Upload\Storage;

use SunsetReverie\Upload\Exception\UploadException;

/**
 * 本地磁盘存储。
 *
 * 约束：
 * - 根目录**必须在 public/ 之外**，由 Web 服务器通过 alias 暴露 `/uploads`；
 * - 落盘使用 `rename()`（同分区原子操作），失败回退到"复制 + 删除"；
 * - 目录权限 0755、文件 0644，不做可执行权限。
 */
final class LocalStorage implements StorageInterface
{
    /**
     * @param string $root 物理根目录（绝对路径）
     * @param string $urlPrefix 对外 URL 前缀
     */
    public function __construct(
        private readonly string $root,
        private readonly string $urlPrefix = '/uploads',
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function put(string $path, string $sourcePath): void
    {
        $target = $this->path($path);
        $directory = dirname($target);

        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new UploadException(sprintf('上传目录不可创建：[%s]', $directory));
        }

        if (!@rename($sourcePath, $target)) {
            if (!@copy($sourcePath, $target)) {
                throw new UploadException(sprintf('文件写入失败：[%s]', $target));
            }

            @unlink($sourcePath);
        }

        @chmod($target, 0644);
    }

    /**
     * {@inheritDoc}
     */
    public function putContents(string $path, string $contents): void
    {
        $target = $this->path($path);
        $directory = dirname($target);

        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new UploadException(sprintf('上传目录不可创建：[%s]', $directory));
        }

        if (@file_put_contents($target, $contents) === false) {
            throw new UploadException(sprintf('文件写入失败：[%s]', $target));
        }

        @chmod($target, 0644);
    }

    /**
     * {@inheritDoc}
     */
    public function exists(string $path): bool
    {
        return is_file($this->path($path));
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $path): bool
    {
        $target = $this->path($path);

        return is_file($target) ? @unlink($target) : true;
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
        $normalized = $this->normalize($path);

        return rtrim($this->root, '/') . '/' . $normalized;
    }

    /**
     * 归一化路径并阻断目录穿越。
     *
     * @param string $path 相对路径
     * @return string 安全路径
     * @throws UploadException 含穿越片段时抛出
     */
    private function normalize(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');

        if ($path === '' || str_contains($path, '..')) {
            throw new UploadException(sprintf('非法存储路径：[%s]', $path));
        }

        return $path;
    }
}
