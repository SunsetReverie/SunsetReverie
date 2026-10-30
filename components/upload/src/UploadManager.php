<?php

declare(strict_types=1);

namespace SunsetReverie\Upload;

use SunsetReverie\Api\DomainContext;
use SunsetReverie\Http\UploadedFile;
use SunsetReverie\Upload\Exception\UploadException;
use SunsetReverie\Upload\Storage\StorageInterface;

/**
 * 上传管理器：安全校验 → 落盘 → 返回可访问信息。
 *
 * 安全链路（顺序不可调换）：
 * 1. 上传状态校验（PHP 层面的错误先拦掉）；
 * 2. 大小校验；
 * 3. 危险扩展名拦截（即便 MIME 合法也拒绝 `.php` 之类）；
 * 4. **真实 MIME** 白名单（finfo 读文件内容，不信客户端声明）；
 * 5. 图片再验一次结构（getimagesize），防止"改了后缀的脚本"。
 *
 * 文件名一律服务端生成（UUID + MIME 映射的扩展名），
 * 客户端原始文件名只用于展示，绝不参与路径拼接。
 */
final class UploadManager
{
    /** MIME → 扩展名映射（落盘扩展名由此决定，不取客户端值） */
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
        'application/pdf' => 'pdf',
        'application/zip' => 'zip',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
    ];

    /** 危险扩展名（即便 MIME 合法也拒绝，防止二次解析漏洞） */
    private const DANGEROUS_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'phtml', 'phar', 'pht',
        'jsp', 'jspx', 'asp', 'aspx', 'cgi', 'pl', 'sh', 'bash',
        'exe', 'dll', 'bat', 'cmd', 'com', 'htaccess', 'ini',
    ];

    /**
     * @param array<string, mixed> $config 上传配置
     * @param StorageInterface $storage 存储驱动
     * @param DomainContext|null $domainContext 域上下文（配置了域时自动按域分目录）
     */
    public function __construct(
        private readonly array $config,
        private StorageInterface $storage,
        private readonly ?DomainContext $domainContext = null,
    ) {
    }

    /**
     * 保存上传文件。
     *
     * @param UploadedFile $file 上传文件
     * @param string $directory 子目录（如 avatars、articles），不含首尾斜杠
     * @return StoredFile 存储结果
     * @throws UploadException 校验不通过或写入失败时抛出
     */
    public function store(UploadedFile $file, string $directory = ''): StoredFile
    {
        $mime = $this->validate($file);

        // 必须在落盘前取值：storage->put 会移走临时文件，之后无法再计算摘要
        $hash = $file->hash();
        $size = $file->size();
        $originalName = $file->originalName();

        $relative = $this->buildPath($directory, $mime);

        $this->storage->put($relative, $file->tmpPath());

        return new StoredFile(
            path: $relative,
            url: $this->storage->url($relative),
            size: $size,
            mime: $mime,
            hash: $hash,
            originalName: $originalName,
        );
    }

    /**
     * 生成带时效的签名 URL（私有资源使用）。
     *
     * **不指向上传目录**：`/uploads/*` 由 Web 服务器直接 serve，无法鉴权；
     * 这里指向内置的验证路由 `/files`，由 FileController 校验签名后输出。
     *
     * @param string $path 相对存储路径
     * @param int $ttl 有效期（秒）
     * @param string $route 验证路由路径
     * @return string 签名 URL
     * @throws UploadException 未配置签名密钥时抛出
     */
    public function signedUrl(string $path, int $ttl = 900, string $route = '/files'): string
    {
        $expires = time() + max(1, $ttl);
        $signature = $this->sign($path, $expires);

        return sprintf(
            '%s?path=%s&expires=%d&sign=%s',
            $route,
            rawurlencode($path),
            $expires,
            $signature
        );
    }

    /**
     * 校验签名 URL 是否有效。
     *
     * @param string $path 相对存储路径
     * @param int $expires 过期时间戳
     * @param string $signature 签名
     * @return bool 有效返回 true
     */
    public function verifySignature(string $path, int $expires, string $signature): bool
    {
        if ($expires < time()) {
            return false;
        }

        return hash_equals($this->sign($path, $expires), $signature);
    }

    /**
     * 删除文件。
     *
     * @param string $path 相对存储路径
     * @return bool 成功返回 true
     */
    public function delete(string $path): bool
    {
        return $this->storage->delete($path);
    }

    /**
     * 替换存储驱动（文档 7.10 承诺的扩展点：新增存储后端不改 core/）。
     *
     * 典型用法：在 ServiceProvider::boot() 里
     *   $app->upload()->extend(new S3Storage($config));
     *
     * @param StorageInterface $storage 存储驱动
     * @return self 当前实例
     */
    public function extend(StorageInterface $storage): self
    {
        $this->storage = $storage;

        return $this;
    }

    /**
     * 单文件大小上限（字节）。
     *
     * @return int 上限
     */
    public function maxSize(): int
    {
        return (int) ($this->config['max_size'] ?? 10 * 1024 * 1024);
    }

    /**
     * 获取存储驱动。
     *
     * @return StorageInterface 驱动
     */
    public function storage(): StorageInterface
    {
        return $this->storage;
    }

    /**
     * 执行安全校验并返回真实 MIME。
     *
     * @param UploadedFile $file 上传文件
     * @return string 通过校验的真实 MIME
     * @throws UploadException 校验失败时抛出
     */
    private function validate(UploadedFile $file): string
    {
        if (!$file->isValid()) {
            throw new UploadException($file->errorMessage() . '，请重新选择文件');
        }

        $maxSize = (int) ($this->config['max_size'] ?? 10 * 1024 * 1024);

        if ($file->size() > $maxSize) {
            throw UploadException::tooLarge($maxSize);
        }

        $clientExtension = $file->clientExtension();

        if ($clientExtension !== '' && in_array($clientExtension, self::DANGEROUS_EXTENSIONS, true)) {
            throw new UploadException('该文件类型不允许上传', 415);
        }

        $mime = $file->detectedMimeType();
        $allowedMimes = (array) ($this->config['allowed_mimes'] ?? []);

        if ($allowedMimes !== [] && !in_array($mime, $allowedMimes, true)) {
            throw UploadException::invalidType($mime);
        }

        // 图片再做结构校验：防止把脚本改名为 .jpg 蒙混过关
        if (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml' && !$file->isRealImage()) {
            throw new UploadException('图片文件已损坏或格式不正确', 422);
        }

        return $mime;
    }

    /**
     * 生成存储路径：{dir}/{Y}/{m}/{d}/{uuid}.{ext}。
     *
     * @param string $directory 子目录
     * @param string $mime 真实 MIME
     * @return string 相对路径
     */
    private function buildPath(string $directory, string $mime): string
    {
        $extension = self::MIME_EXTENSIONS[$mime] ?? 'bin';
        $date = date('Y/m/d');
        $name = bin2hex(random_bytes(16)) . '.' . $extension;

        $directory = trim($directory, '/');

        if ($directory !== '' && preg_match('/^[A-Za-z0-9_-]+(\/[A-Za-z0-9_-]+)*$/', $directory) !== 1) {
            throw new UploadException('非法的上传目录');
        }

        // 域隔离：admin 与 app 上传的文件落在不同物理目录
        $domain = $this->domainContext?->name() ?? '';

        if ($domain !== '' && ($this->config['isolate_by_domain'] ?? true)) {
            $directory = $domain . ($directory === '' ? '' : '/' . $directory);
        }

        return ($directory === '' ? '' : $directory . '/') . $date . '/' . $name;
    }

    /**
     * 计算签名。
     *
     * @param string $path 相对路径
     * @param int $expires 过期时间戳
     * @return string 十六进制签名
     * @throws UploadException 未配置密钥时抛出
     */
    private function sign(string $path, int $expires): string
    {
        $secret = (string) ($this->config['sign_secret'] ?? '');

        if ($secret === '') {
            throw new UploadException('未配置上传签名密钥（upload.sign_secret）', 500);
        }

        return hash_hmac('sha256', $path . '|' . $expires, $secret);
    }
}
