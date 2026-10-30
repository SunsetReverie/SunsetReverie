<?php

declare(strict_types=1);

namespace Tests\Unit;

use SunsetReverie\Http\UploadedFile;
use SunsetReverie\Upload\Chunk\ChunkedUploadManager;
use SunsetReverie\Upload\Exception\UploadException;
use SunsetReverie\Upload\Storage\LocalStorage;
use SunsetReverie\Upload\UploadManager;
use Tests\TestCase;

/**
 * 分片上传测试（真实文件系统，便于验证拼接与索引）。
 */
final class ChunkedUploadTest extends TestCase
{
    /** @var string 根目录 */
    private string $root;

    /** @var ChunkedUploadManager 被测管理器 */
    private ChunkedUploadManager $chunks;

    /** @var string 测试用 PNG 内容 */
    private string $payload;

    /**
     * 准备目录与管理器。
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/sr-chunk-' . uniqid('', true);
        mkdir($this->root . '/chunks', 0755, true);
        mkdir($this->root . '/index', 0755, true);
        mkdir($this->root . '/uploads', 0755, true);

        $upload = new UploadManager(
            ['max_size' => 10 * 1024 * 1024, 'allowed_mimes' => ['image/png']],
            new LocalStorage($this->root . '/uploads', '/uploads')
        );

        $this->chunks = new ChunkedUploadManager(
            $upload,
            $this->root . '/chunks',
            $this->root . '/index',
            30,
            1,
            1024 * 1024,
            86400
        );

        $this->payload = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true
        ) ?: '';
    }

    /**
     * 清理目录。
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    /**
     * 完整流程：init → 分片 → 合并。
     *
     * @return void
     */
    public function testFullFlow(): void
    {
        $hash = hash('sha256', $this->payload);
        $init = $this->chunks->init('avatar.png', strlen($this->payload), $hash, 30, 'admin', 'avatars');

        $this->assertFalse($init['instant']);
        $this->assertSame(3, $init['total'], '70 字节按 30 分片应为 3 片');

        $uploadId = (string) $init['upload_id'];

        foreach ($this->split(30) as $index => $part) {
            $progress = $this->chunks->chunk($uploadId, $index, $this->partFile($part));
            $this->assertSame($index + 1, $progress['received']);
        }

        $stored = $this->chunks->merge($uploadId);

        $this->assertSame(hash('sha256', $this->payload), $stored->hash);
        $this->assertSame(strlen($this->payload), $stored->size);
        $this->assertSame('image/png', $stored->mime);
        $this->assertStringContains('avatars/', $stored->path);

        $absolute = $this->root . '/uploads/' . $stored->path;
        $this->assertSame($this->payload, (string) file_get_contents($absolute), '合并后内容应与原文件一致');
    }

    /**
     * 分片缺失时合并应失败并指出缺哪片。
     *
     * @return void
     */
    public function testMergeFailsWithMissingChunks(): void
    {
        $init = $this->chunks->init('avatar.png', strlen($this->payload), hash('sha256', $this->payload), 30);
        $uploadId = (string) $init['upload_id'];

        $parts = $this->split(30);
        $this->chunks->chunk($uploadId, 0, $this->partFile($parts[0]));

        $e = $this->assertThrows(UploadException::class, fn () => $this->chunks->merge($uploadId));

        $this->assertStringContains('还有 2 个分片未上传', $e->getMessage());
    }

    /**
     * 分片被篡改时哈希校验应拦下。
     *
     * @return void
     */
    public function testMergeRejectsTamperedContent(): void
    {
        $hash = hash('sha256', $this->payload);
        $init = $this->chunks->init('avatar.png', strlen($this->payload), $hash, 30);
        $uploadId = (string) $init['upload_id'];

        $parts = $this->split(30);
        $parts[1] = str_repeat('X', strlen($parts[1]));

        foreach ($parts as $index => $part) {
            $this->chunks->chunk($uploadId, $index, $this->partFile($part));
        }

        $e = $this->assertThrows(UploadException::class, fn () => $this->chunks->merge($uploadId));

        $this->assertStringContains('校验失败', $e->getMessage());
    }

    /**
     * 相同内容再次 init 应秒传。
     *
     * @return void
     */
    public function testInstantUploadByHash(): void
    {
        $hash = hash('sha256', $this->payload);
        $init = $this->chunks->init('avatar.png', strlen($this->payload), $hash, 30);
        $uploadId = (string) $init['upload_id'];

        foreach ($this->split(30) as $index => $part) {
            $this->chunks->chunk($uploadId, $index, $this->partFile($part));
        }

        $first = $this->chunks->merge($uploadId);

        $second = $this->chunks->init('copy.png', strlen($this->payload), $hash, 30);

        $this->assertTrue($second['instant']);
        $this->assertNull($second['upload_id']);
        $this->assertSame($first->path, $second['file']['path']);
    }

    /**
     * 分片序号越界应报错。
     *
     * @return void
     */
    public function testRejectsOutOfRangeIndex(): void
    {
        $init = $this->chunks->init('avatar.png', strlen($this->payload), hash('sha256', $this->payload), 30);
        $uploadId = (string) $init['upload_id'];

        $this->assertThrows(
            UploadException::class,
            fn () => $this->chunks->chunk($uploadId, 99, $this->partFile('x'))
        );
    }

    /**
     * 非法 upload_id 应被拒绝（防路径穿越）。
     *
     * @return void
     */
    public function testRejectsIllegalUploadId(): void
    {
        $this->assertThrows(UploadException::class, fn () => $this->chunks->status('../../etc'));
    }

    /**
     * 分片大小超出范围应报错。
     *
     * @return void
     */
    public function testRejectsIllegalChunkSize(): void
    {
        $this->assertThrows(
            UploadException::class,
            fn () => $this->chunks->init('a.png', 100, hash('sha256', 'x'), 999999999)
        );
    }

    /**
     * 过期会话应被 prune 清理。
     *
     * @return void
     */
    public function testPruneRemovesExpiredSessions(): void
    {
        $manager = new ChunkedUploadManager(
            new UploadManager(
                ['max_size' => 1024, 'allowed_mimes' => ['image/png']],
                new LocalStorage($this->root . '/uploads')
            ),
            $this->root . '/chunks',
            $this->root . '/index',
            30,
            1,
            1024 * 1024,
            -1
        );

        $manager->init('a.png', 10, hash('sha256', 'abcdefghij'), 30);

        $this->assertSame(1, $manager->prune());
    }

    /**
     * 按大小切分内容。
     *
     * @param int $size 分片大小
     * @return list<string> 分片内容
     */
    private function split(int $size): array
    {
        // str_split 要求长度 ≥ 1
        return str_split($this->payload, max(1, $size));
    }

    /**
     * 生成分片上传文件。
     *
     * @param string $content 分片内容
     * @return UploadedFile 上传文件
     */
    private function partFile(string $content): UploadedFile
    {
        $path = $this->root . '/part-' . uniqid('', true) . '.tmp';
        file_put_contents($path, $content);

        return UploadedFile::fake($path, 'part', 'application/octet-stream');
    }

    /**
     * 递归删除目录。
     *
     * @param string $directory 目录
     * @return void
     */
    private function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (glob($directory . '/*') ?: [] as $file) {
            is_dir($file) ? $this->remove($file) : @unlink($file);
        }

        // 隐藏目录（.chunks / .index）
        foreach (glob($directory . '/.*', GLOB_ONLYDIR) ?: [] as $file) {
            if (basename($file) !== '.' && basename($file) !== '..') {
                $this->remove($file);
            }
        }

        @rmdir($directory);
    }
}
