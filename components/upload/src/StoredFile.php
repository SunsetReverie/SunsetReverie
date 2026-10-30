<?php

declare(strict_types=1);

namespace SunsetReverie\Upload;

/**
 * 已存储文件的信息（不可变）。
 *
 * 调用方通常只需要 `url`（公开资源）或 `path`（后续生成签名 URL / 删除）。
 */
final class StoredFile
{
    /**
     * @param string $path 相对存储路径（如 app/2026/10/30/xxx.jpg）
     * @param string $url 公开访问 URL
     * @param int $size 字节数
     * @param string $mime 真实 MIME
     * @param string $hash 内容 SHA-256
     * @param string $originalName 客户端原始文件名（仅用于展示/记录）
     */
    public function __construct(
        public readonly string $path,
        public readonly string $url,
        public readonly int $size,
        public readonly string $mime,
        public readonly string $hash,
        public readonly string $originalName,
    ) {
    }

    /**
     * 转为数组（响应体输出用）。
     *
     * @return array<string, mixed> 文件信息
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'url' => $this->url,
            'size' => $this->size,
            'mime' => $this->mime,
            'hash' => $this->hash,
            'name' => $this->originalName,
        ];
    }
}
