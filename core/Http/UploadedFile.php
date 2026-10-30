<?php

declare(strict_types=1);

namespace SunsetReverie\Http;

/**
 * 上传文件值对象（对应 $_FILES 中的一项）。
 *
 * 安全原则：**客户端声明的 MIME 与文件名一律不可信**。
 * - `clientMimeType()` 只用于记录/展示；
 * - 落盘与校验必须使用 `detectedMimeType()`（基于文件内容，finfo 检测）。
 */
final class UploadedFile
{
    /**
     * @param string $tmpPath 临时文件路径
     * @param string $originalName 客户端原始文件名（不可信）
     * @param string $clientMimeType 客户端声明的 MIME（不可信）
     * @param int $size 字节数
     * @param int $error UPLOAD_ERR_* 错误码
     * @param bool $trusted 是否跳过 is_uploaded_file 检查（仅 fake() 使用）
     */
    public function __construct(
        private readonly string $tmpPath,
        private readonly string $originalName,
        private readonly string $clientMimeType,
        private readonly int $size,
        private readonly int $error = UPLOAD_ERR_OK,
        private readonly bool $trusted = false,
    ) {
    }

    /**
     * 由磁盘上已有文件构造（测试、命令行导入、队列重放等场景）。
     *
     * 注意：会跳过 `is_uploaded_file()` 检查，**不得**用于处理真实 HTTP 上传。
     *
     * @param string $path 文件路径
     * @param string $originalName 期望的原始文件名
     * @param string $clientMimeType 期望的客户端 MIME
     * @return self 上传文件
     */
    public static function fake(string $path, string $originalName = 'test.bin', string $clientMimeType = 'application/octet-stream'): self
    {
        return new self(
            tmpPath: $path,
            originalName: $originalName,
            clientMimeType: $clientMimeType,
            size: (int) (@filesize($path) ?: 0),
            error: UPLOAD_ERR_OK,
            trusted: true,
        );
    }

    /**
     * 由 $_FILES 单项构造。
     *
     * @param array<string, mixed> $file $_FILES 中的一项
     * @return self 上传文件
     */
    public static function fromArray(array $file): self
    {
        return new self(
            tmpPath: (string) ($file['tmp_name'] ?? ''),
            originalName: (string) ($file['name'] ?? ''),
            clientMimeType: (string) ($file['type'] ?? ''),
            size: (int) ($file['size'] ?? 0),
            error: (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE),
        );
    }

    /**
     * 上传是否成功。
     *
     * @return bool 成功返回 true
     */
    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK
            && $this->tmpPath !== ''
            && is_file($this->tmpPath)
            && ($this->trusted || is_uploaded_file($this->tmpPath));
    }

    /**
     * 获取错误说明（用于给用户看的提示）。
     *
     * @return string 中文错误说明
     */
    public function errorMessage(): string
    {
        return match ($this->error) {
            UPLOAD_ERR_OK => '上传成功',
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '文件超过服务器允许的大小',
            UPLOAD_ERR_PARTIAL => '文件只上传了一部分',
            UPLOAD_ERR_NO_FILE => '没有选择文件',
            UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录',
            UPLOAD_ERR_CANT_WRITE => '服务器写入临时文件失败',
            UPLOAD_ERR_EXTENSION => '上传被 PHP 扩展中断',
            default => '上传失败',
        };
    }

    /**
     * 客户端原始文件名。
     *
     * @return string 文件名
     */
    public function originalName(): string
    {
        return $this->originalName;
    }

    /**
     * 客户端文件名的小写扩展名（不可信，仅用于提示）。
     *
     * @return string 扩展名
     */
    public function clientExtension(): string
    {
        return strtolower(pathinfo($this->originalName, PATHINFO_EXTENSION));
    }

    /**
     * 字节数。
     *
     * @return int 大小
     */
    public function size(): int
    {
        return $this->size;
    }

    /**
     * 临时文件路径。
     *
     * @return string 路径
     */
    public function tmpPath(): string
    {
        return $this->tmpPath;
    }

    /**
     * 客户端声明的 MIME（不可信）。
     *
     * @return string MIME
     */
    public function clientMimeType(): string
    {
        return $this->clientMimeType;
    }

    /**
     * 基于文件内容检测的真实 MIME。
     *
     * @return string 检测到的 MIME，失败返回 application/octet-stream
     */
    public function detectedMimeType(): string
    {
        if (!is_file($this->tmpPath)) {
            return 'application/octet-stream';
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($this->tmpPath);

        return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    }

    /**
     * 文件内容 SHA-256（用于去重/秒传）。
     *
     * @return string 十六进制摘要
     */
    public function hash(): string
    {
        $hash = @hash_file('sha256', $this->tmpPath);

        return is_string($hash) ? $hash : '';
    }

    /**
     * 是否通过图片结构校验（防止伪造扩展名的非图片）。
     *
     * @return bool 是合法图片返回 true
     */
    public function isRealImage(): bool
    {
        if (!is_file($this->tmpPath)) {
            return false;
        }

        $info = @getimagesize($this->tmpPath);

        return is_array($info) && ($info[0] ?? 0) > 0 && ($info[1] ?? 0) > 0;
    }
}
