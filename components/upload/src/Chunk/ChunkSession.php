<?php

declare(strict_types=1);

namespace SunsetReverie\Upload\Chunk;

/**
 * 分片上传会话（不可变，落盘为 session.json）。
 *
 * 字段含义：
 * - uploadId：会话标识，同时是临时目录名
 * - filename：客户端原始文件名（仅记录用途）
 * - size：文件总字节数
 * - chunkSize：单片大小
 * - hash：整文件 SHA-256（合并时校验）
 * - total：分片总数
 * - domain：发起会话的域（用于归属校验）
 * - expiresAt：过期时间戳
 */
final class ChunkSession
{
    /**
     * @param string $uploadId 会话 ID
     * @param string $filename 原始文件名
     * @param int $size 总字节数
     * @param int $chunkSize 分片大小
     * @param string $hash 整文件 SHA-256
     * @param int $total 分片总数
     * @param string $domain 所属域
     * @param int $expiresAt 过期时间戳
     * @param string $directory 落盘子目录
     */
    public function __construct(
        public readonly string $uploadId,
        public readonly string $filename,
        public readonly int $size,
        public readonly int $chunkSize,
        public readonly string $hash,
        public readonly int $total,
        public readonly string $domain,
        public readonly int $expiresAt,
        public readonly string $directory = '',
    ) {
    }

    /**
     * 转为可落盘的数组。
     *
     * @return array<string, mixed> 会话数据
     */
    public function toArray(): array
    {
        return [
            'upload_id' => $this->uploadId,
            'filename' => $this->filename,
            'size' => $this->size,
            'chunk_size' => $this->chunkSize,
            'hash' => $this->hash,
            'total' => $this->total,
            'domain' => $this->domain,
            'expires_at' => $this->expiresAt,
            'directory' => $this->directory,
        ];
    }

    /**
     * 由数组恢复。
     *
     * @param array<string, mixed> $data 会话数据
     * @return self 会话
     */
    public static function fromArray(array $data): self
    {
        return new self(
            uploadId: (string) ($data['upload_id'] ?? ''),
            filename: (string) ($data['filename'] ?? ''),
            size: (int) ($data['size'] ?? 0),
            chunkSize: (int) ($data['chunk_size'] ?? 0),
            hash: (string) ($data['hash'] ?? ''),
            total: (int) ($data['total'] ?? 0),
            domain: (string) ($data['domain'] ?? ''),
            expiresAt: (int) ($data['expires_at'] ?? 0),
            directory: (string) ($data['directory'] ?? ''),
        );
    }

    /**
     * 是否已过期。
     *
     * @return bool 过期返回 true
     */
    public function isExpired(): bool
    {
        return $this->expiresAt > 0 && $this->expiresAt < time();
    }
}
