<?php

declare(strict_types=1);

namespace SunsetReverie\Upload\Chunk;

use SunsetReverie\Http\UploadedFile;
use SunsetReverie\Upload\Exception\UploadException;
use SunsetReverie\Upload\StoredFile;
use SunsetReverie\Upload\UploadManager;

/**
 * 分片上传：四步协议（init → chunk → merge → status）。
 *
 * 为什么必须校验最终哈希：分片是并行上传的，客户端或代理可能丢块、重块、乱序。
 * 只拼接不校验会得到"看着成功、内容已损坏"的文件，这类问题在生产极难排查。
 *
 * 存储布局：
 *   storage/uploads/.chunks/{upload_id}/session.json   会话
 *   storage/uploads/.chunks/{upload_id}/{index}.part   分片
 *   storage/uploads/.index/{hash}                      秒传索引（内容为相对路径）
 */
final class ChunkedUploadManager
{
    /** upload_id 合法格式（同时用作目录名，必须严格限制） */
    private const UPLOAD_ID_PATTERN = '/^[0-9a-f]{32}$/';

    /**
     * @param UploadManager $upload 负责最终落盘与类型校验
     * @param string $chunkRoot 分片根目录（绝对路径）
     * @param string $indexRoot 秒传索引目录（绝对路径）
     * @param int $defaultChunkSize 默认分片大小
     * @param int $minChunkSize 最小分片大小
     * @param int $maxChunkSize 最大分片大小
     * @param int $sessionTtl 会话有效期（秒）
     */
    public function __construct(
        private readonly UploadManager $upload,
        private readonly string $chunkRoot,
        private readonly string $indexRoot,
        private readonly int $defaultChunkSize = 5 * 1024 * 1024,
        private readonly int $minChunkSize = 1024 * 1024,
        private readonly int $maxChunkSize = 16 * 1024 * 1024,
        private readonly int $sessionTtl = 86400,
    ) {
    }

    /**
     * 初始化会话。
     *
     * @param string $filename 原始文件名（仅记录）
     * @param int $size 文件总字节数
     * @param string $hash 整文件 SHA-256（64 位十六进制）
     * @param int|null $chunkSize 期望分片大小，null 用默认值
     * @param string $domain 所属域
     * @param string $directory 落盘子目录
     * @return array<string, mixed> 会话信息（含 instant 标记）
     * @throws UploadException 参数非法或文件过大时抛出
     */
    public function init(
        string $filename,
        int $size,
        string $hash,
        ?int $chunkSize = null,
        string $domain = '',
        string $directory = '',
    ): array {
        $maxSize = $this->upload->maxSize();

        if ($size <= 0) {
            throw new UploadException('文件大小不合法');
        }

        if ($maxSize > 0 && $size > $maxSize) {
            throw UploadException::tooLarge($maxSize);
        }

        if (preg_match('/^[0-9a-f]{64}$/', strtolower($hash)) !== 1) {
            throw new UploadException('hash 必须是 64 位十六进制 SHA-256');
        }

        $chunkSize ??= $this->defaultChunkSize;

        if ($chunkSize < $this->minChunkSize || $chunkSize > $this->maxChunkSize) {
            throw new UploadException(sprintf(
                '分片大小必须在 %s～%s 之间',
                $this->humanSize($this->minChunkSize),
                $this->humanSize($this->maxChunkSize)
            ));
        }

        // 秒传：同内容已存在则直接返回
        $existing = $this->findByHash($hash);

        if ($existing !== null) {
            return [
                'upload_id' => null,
                'chunk_size' => $chunkSize,
                'total' => 0,
                'uploaded' => [],
                'instant' => true,
                'file' => $existing->toArray(),
            ];
        }

        $uploadId = bin2hex(random_bytes(16));
        $total = (int) ceil($size / $chunkSize);
        $session = new ChunkSession(
            uploadId: $uploadId,
            filename: $filename,
            size: $size,
            chunkSize: $chunkSize,
            hash: strtolower($hash),
            total: $total,
            domain: $domain,
            expiresAt: time() + $this->sessionTtl,
            directory: $directory,
        );

        $directoryPath = $this->sessionDirectory($uploadId);

        if (!is_dir($directoryPath) && !@mkdir($directoryPath, 0755, true) && !is_dir($directoryPath)) {
            throw new UploadException('无法创建分片临时目录，请检查 storage 权限');
        }

        $this->writeSession($session);

        return [
            'upload_id' => $uploadId,
            'chunk_size' => $chunkSize,
            'total' => $total,
            'uploaded' => [],
            'instant' => false,
        ];
    }

    /**
     * 上传一个分片（同序号重复上传视为覆盖，幂等）。
     *
     * @param string $uploadId 会话 ID
     * @param int $index 分片序号（从 0 开始）
     * @param UploadedFile $file 分片文件
     * @return array<string, mixed> 进度信息
     * @throws UploadException 会话非法或序号越界时抛出
     */
    public function chunk(string $uploadId, int $index, UploadedFile $file): array
    {
        $session = $this->loadSession($uploadId);

        if ($index < 0 || $index >= $session->total) {
            throw new UploadException(sprintf('分片序号越界：应在 0～%d 之间', $session->total - 1));
        }

        if (!$file->isValid()) {
            throw new UploadException('分片文件无效：' . $file->errorMessage());
        }

        $target = $this->sessionDirectory($uploadId) . '/' . $index . '.part';

        if (!@rename($file->tmpPath(), $target)) {
            if (!@copy($file->tmpPath(), $target)) {
                throw new UploadException('分片写入失败，请重试');
            }

            @unlink($file->tmpPath());
        }

        $uploaded = $this->uploadedIndexes($uploadId);

        return [
            'index' => $index,
            'received' => count($uploaded),
            'total' => $session->total,
            'uploaded' => $uploaded,
        ];
    }

    /**
     * 合并分片并落盘。
     *
     * @param string $uploadId 会话 ID
     * @return StoredFile 最终文件信息
     * @throws UploadException 分片缺失、哈希不符或写入失败时抛出
     */
    public function merge(string $uploadId): StoredFile
    {
        $session = $this->loadSession($uploadId);

        $missing = $this->missingIndexes($session);

        if ($missing !== []) {
            throw new UploadException(sprintf(
                '还有 %d 个分片未上传：%s',
                count($missing),
                implode(',', array_slice($missing, 0, 10))
            ));
        }

        $lock = $this->acquireLock($uploadId);
        $assembled = $this->sessionDirectory($uploadId) . '/assembled.bin';

        try {
            $this->assemble($session, $assembled);

            // 完整性校验：拼接后重算，不一致说明丢块/错位
            $actual = hash_file('sha256', $assembled);

            if (!is_string($actual) || !hash_equals($session->hash, $actual)) {
                throw new UploadException('文件校验失败：分片内容不完整或已损坏，请重新上传');
            }

            if (filesize($assembled) !== $session->size) {
                throw new UploadException('文件大小与声明不一致，请重新上传');
            }

            $stored = $this->upload->store(
                UploadedFile::fake($assembled, $session->filename, 'application/octet-stream'),
                $session->directory
            );

            $this->writeIndex($session->hash, $stored->path);
            $this->destroy($uploadId);

            return $stored;
        } finally {
            @unlink($assembled);
            $this->releaseLock($lock);
        }
    }

    /**
     * 查询会话进度。
     *
     * @param string $uploadId 会话 ID
     * @return array<string, mixed> 进度信息
     * @throws UploadException 会话不存在或已过期时抛出
     */
    public function status(string $uploadId): array
    {
        $session = $this->loadSession($uploadId);
        $uploaded = $this->uploadedIndexes($uploadId);

        return [
            'upload_id' => $session->uploadId,
            'total' => $session->total,
            'uploaded' => $uploaded,
            'received' => count($uploaded),
            'expires_at' => $session->expiresAt,
        ];
    }

    /**
     * 清理过期会话。
     *
     * @return int 清理数量
     */
    public function prune(): int
    {
        $count = 0;

        foreach (glob($this->chunkRoot . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $sessionFile = $directory . '/session.json';

            if (!is_file($sessionFile)) {
                continue;
            }

            $data = json_decode((string) file_get_contents($sessionFile), true);
            $session = is_array($data) ? ChunkSession::fromArray($data) : null;

            if ($session === null || $session->isExpired()) {
                $this->removeDirectory($directory);
                $count++;
            }
        }

        return $count;
    }

    /**
     * 按内容哈希查找已存在文件（秒传）。
     *
     * @param string $hash SHA-256
     * @return StoredFile|null 已存在文件
     */
    public function findByHash(string $hash): ?StoredFile
    {
        $indexFile = $this->indexRoot . '/' . strtolower($hash);

        if (!is_file($indexFile)) {
            return null;
        }

        $path = trim((string) file_get_contents($indexFile));

        if ($path === '' || !$this->upload->storage()->exists($path)) {
            return null;
        }

        $absolute = $this->upload->storage()->path($path);

        return new StoredFile(
            path: $path,
            url: $this->upload->storage()->url($path),
            size: is_file($absolute) ? (int) filesize($absolute) : 0,
            mime: is_file($absolute) ? (string) (mime_content_type($absolute) ?: 'application/octet-stream') : 'application/octet-stream',
            hash: strtolower($hash),
            originalName: basename($path),
        );
    }

    /**
     * 拼接分片（流式，避免一次性读入内存）。
     *
     * @param ChunkSession $session 会话
     * @param string $target 目标文件
     * @return void
     * @throws UploadException 写入失败时抛出
     */
    private function assemble(ChunkSession $session, string $target): void
    {
        $handle = @fopen($target, 'wb');

        if ($handle === false) {
            throw new UploadException('无法创建合并文件');
        }

        try {
            for ($index = 0; $index < $session->total; $index++) {
                $part = $this->sessionDirectory($session->uploadId) . '/' . $index . '.part';

                if (!is_file($part)) {
                    throw new UploadException(sprintf('分片 %d 丢失', $index));
                }

                $source = @fopen($part, 'rb');

                if ($source === false) {
                    throw new UploadException(sprintf('分片 %d 无法读取', $index));
                }

                stream_copy_to_stream($source, $handle);
                fclose($source);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * 已上传的分片序号（升序）。
     *
     * @param string $uploadId 会话 ID
     * @return list<int> 序号列表
     */
    private function uploadedIndexes(string $uploadId): array
    {
        $indexes = [];

        foreach (glob($this->sessionDirectory($uploadId) . '/*.part') ?: [] as $file) {
            $indexes[] = (int) basename($file, '.part');
        }

        sort($indexes);

        return $indexes;
    }

    /**
     * 缺失的分片序号。
     *
     * @param ChunkSession $session 会话
     * @return list<int> 缺失序号
     */
    private function missingIndexes(ChunkSession $session): array
    {
        $uploaded = $this->uploadedIndexes($session->uploadId);
        $missing = [];

        for ($index = 0; $index < $session->total; $index++) {
            if (!in_array($index, $uploaded, true)) {
                $missing[] = $index;
            }
        }

        return $missing;
    }

    /**
     * 读取会话。
     *
     * @param string $uploadId 会话 ID
     * @return ChunkSession 会话
     * @throws UploadException 不存在或过期时抛出
     */
    private function loadSession(string $uploadId): ChunkSession
    {
        if (preg_match(self::UPLOAD_ID_PATTERN, $uploadId) !== 1) {
            throw new UploadException('upload_id 格式不合法');
        }

        $file = $this->sessionDirectory($uploadId) . '/session.json';

        if (!is_file($file)) {
            throw new UploadException('上传会话不存在或已完成，请重新初始化');
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (!is_array($data)) {
            throw new UploadException('上传会话已损坏，请重新初始化');
        }

        $session = ChunkSession::fromArray($data);

        if ($session->isExpired()) {
            $this->destroy($uploadId);

            throw new UploadException('上传会话已过期，请重新初始化');
        }

        return $session;
    }

    /**
     * 写入会话。
     *
     * @param ChunkSession $session 会话
     * @return void
     * @throws UploadException 写入失败时抛出
     */
    private function writeSession(ChunkSession $session): void
    {
        $file = $this->sessionDirectory($session->uploadId) . '/session.json';
        $json = json_encode($session->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if ($json === false || @file_put_contents($file, $json) === false) {
            throw new UploadException('会话写入失败，请检查 storage 权限');
        }
    }

    /**
     * 写入秒传索引。
     *
     * @param string $hash SHA-256
     * @param string $path 相对路径
     * @return void
     */
    private function writeIndex(string $hash, string $path): void
    {
        if (!is_dir($this->indexRoot) && !@mkdir($this->indexRoot, 0755, true) && !is_dir($this->indexRoot)) {
            return;
        }

        @file_put_contents($this->indexRoot . '/' . strtolower($hash), $path);
    }

    /**
     * 销毁会话（删除目录）。
     *
     * @param string $uploadId 会话 ID
     * @return void
     */
    private function destroy(string $uploadId): void
    {
        $this->removeDirectory($this->sessionDirectory($uploadId));
    }

    /**
     * 递归删除目录。
     *
     * @param string $directory 目录
     * @return void
     */
    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (glob($directory . '/*') ?: [] as $file) {
            is_dir($file) ? $this->removeDirectory($file) : @unlink($file);
        }

        @rmdir($directory);
    }

    /**
     * 会话目录。
     *
     * @param string $uploadId 会话 ID
     * @return string 绝对路径
     */
    private function sessionDirectory(string $uploadId): string
    {
        return rtrim($this->chunkRoot, '/') . '/' . $uploadId;
    }

    /**
     * 获取合并锁（防止并发 merge 产生孤儿文件）。
     *
     * @param string $uploadId 会话 ID
     * @return resource|null 锁句柄
     */
    private function acquireLock(string $uploadId)
    {
        $file = $this->sessionDirectory($uploadId) . '/merge.lock';
        $handle = @fopen($file, 'c');

        if ($handle === false) {
            return null;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            throw new UploadException('该文件正在合并中，请稍后重试');
        }

        return $handle;
    }

    /**
     * 释放合并锁。
     *
     * @param resource|null $handle 锁句柄
     * @return void
     */
    private function releaseLock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * 字节数转可读文本。
     *
     * @param int $bytes 字节数
     * @return string 可读大小
     */
    private function humanSize(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? round($bytes / 1024 / 1024, 1) . ' MB'
            : round($bytes / 1024, 1) . ' KB';
    }
}
