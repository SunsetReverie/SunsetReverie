<?php

declare(strict_types=1);

namespace SunsetReverie\Upload;

use SunsetReverie\Api\DomainContext;
use SunsetReverie\Http\UploadedFile;
use SunsetReverie\Exception\ErrorCode;
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
    /**
     * 是否用内容哈希做文件名。
     *
     * 开启后：同一内容永远落在同一路径 —— 存在性检查即去重，
     * 不需要额外的 hash → path 索引表。
     */
    private readonly bool $contentAddressed;

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
        $this->contentAddressed = (bool) ($config['content_addressed'] ?? false);
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

        $relative = $this->buildPath($directory, $mime, $hash);

        /*
         * content-addressed 模式下，路径由内容哈希决定，所以「同一内容 =
         * 同一路径」——存在性检查就是去重，不需要额外的 hash → path 索引表。
         *
         * 关掉时（默认）走随机文件名，与旧行为一致。
         */
        if ($this->contentAddressed && $this->storage->exists($relative)) {
            return new StoredFile(
                path: $relative,
                url: $this->storage->url($relative),
                size: $size,
                mime: $mime,
                hash: $hash,
                originalName: $originalName,
            );
        }

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
    /**
     * 保存文件并按需生成多档缩略图。
     *
     * 缩略图与原图同名，后缀加宽度：`{原名去尾}_{宽度}.jpg`。
     *
     * 三处刻意的设计：
     *
     * 1. **`$thumbnailer` 是 callable，不是 ImageManager**。
     *    架构约束 ARCH-3 规定「组件之间不得互相引用」，所以这里不能直接
     *    依赖 Image 组件。传闭包把处理逻辑交给调用方，Upload 保持零依赖。
     *
     * 2. **`$thumbnailer` 可选**。不传就只存原图，等价于 `store()`。
     *
     * 3. **云存储下静默跳过缩略图**，在返回值里给出原因。
     *    云存储的 `storage->path()` 返回空串，拿不到文件；强行支持要
     *    「下载 → 处理 → 回传」，那是调用方的决策，不该藏在框架里。
     *
     * @param UploadedFile $file 上传文件
     * @param string $directory 子目录（如 gallery）
     * @param list<int> $widths 缩略图宽度（等比缩放，比原图宽的自动跳过）
     * @param callable(string, string, int): void|null $thumbnailer
     *        缩略图生成器，签名 `fn (string $sourcePath, string $targetPath, int $width): void`
     * @return array{file: StoredFile, thumbs: array<int, string>, note: string|null}
     *         thumbs 是「宽度 => URL」；note 非空表示缩略图被跳过及原因
     * @throws UploadException 校验不通过或写入失败时抛出
     */
    public function storeWithThumbnails(
        UploadedFile $file,
        string $directory = '',
        array $widths = [200, 800, 1600],
        ?callable $thumbnailer = null,
    ): array {
        $stored = $this->store($file, $directory);

        if ($thumbnailer === null || !str_starts_with($stored->mime, 'image/')) {
            return ['file' => $stored, 'thumbs' => [], 'note' => null];
        }

        $absolute = $this->storage->path($stored->path);

        if ($absolute === '') {
            return [
                'file' => $stored,
                'thumbs' => [],
                'note' => '当前存储驱动不提供本地路径，缩略图需自行下载处理后回传',
            ];
        }

        // 用 PHP 内置的 getimagesize 读宽度——不引入 Image 组件也能判断「该不该缩放」
        $size = @getimagesize($absolute);

        if ($size === false) {
            return [
                'file' => $stored,
                'thumbs' => [],
                'note' => '无法读取图片尺寸，已跳过缩略图',
            ];
        }

        $sourceWidth = $size[0];
        $thumbs = [];

        foreach ($widths as $width) {
            // 比原图还宽就跳过：放大没有意义，只会浪费空间
            if ($width <= 0 || $sourceWidth <= $width) {
                continue;
            }

            /*
             * 缩略图跟原图同名，后缀加宽度。
             *
             * 不能放 `dirname/thumbs/{width}.jpg`：那个目录是**同目录所有图共用**的，
             * 第二张图会直接覆盖第一张的缩略图。content-addressed 模式下
             * 前 4 位 hash 相同的图会撞进同一个目录；随机名模式下更糟——
             * 同一天上传的所有图都在 `2026/11/05/` 下。
             */
            $thumbRelative = preg_replace('/\.[a-z0-9]+$/i', '', $stored->path) . '_' . $width . '.jpg';
            $thumbAbsolute = $this->storage->path($thumbRelative);

            $thumbnailer($absolute, $thumbAbsolute, $width);

            if (!is_file($thumbAbsolute)) {
                continue;
            }

            $thumbs[$width] = $this->storage->url($thumbRelative);
        }

        return ['file' => $stored, 'thumbs' => $thumbs, 'note' => null];
    }

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
            throw new UploadException('该文件类型不允许上传', 415, ErrorCode::UnsupportedMediaType);
        }

        $mime = $file->detectedMimeType();
        $allowedMimes = (array) ($this->config['allowed_mimes'] ?? []);

        if ($allowedMimes !== [] && !in_array($mime, $allowedMimes, true)) {
            throw UploadException::invalidType($mime);
        }

        // 图片再做结构校验：防止把脚本改名为 .jpg 蒙混过关
        if (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml' && !$file->isRealImage()) {
            throw new UploadException('图片文件已损坏或格式不正确', 422, ErrorCode::ValidationFailed);
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
    private function buildPath(string $directory, string $mime, string $hash = ''): string
    {
        $extension = self::MIME_EXTENSIONS[$mime] ?? 'bin';

        $directory = trim($directory, '/');

        if ($directory !== '' && preg_match('/^[A-Za-z0-9_-]+(\/[A-Za-z0-9_-]+)*$/', $directory) !== 1) {
            throw new UploadException('非法的上传目录', 500, ErrorCode::UploadFailed);
        }

        // 域隔离：admin 与 app 上传的文件落在不同物理目录
        $domain = $this->domainContext?->name() ?? '';

        if ($domain !== '' && ($this->config['isolate_by_domain'] ?? true)) {
            $directory = $domain . ($directory === '' ? '' : '/' . $directory);
        }

        $prefix = $directory === '' ? '' : $directory . '/';

        /*
         * content-addressed：文件名就是内容哈希。
         *
         * 好处不只是去重——同一内容的 URL 永远相同，CDN 回源和浏览器缓存
         * 都能直接命中；换机器、换存储也不会产生孤儿副本。
         *
         * 代价：删一个文件可能影响多个引用方（图床场景通常由业务层做引用计数，
         * 或者干脆不删）。
         */
        if ($this->contentAddressed && $hash !== '') {
            // 两层分桶，避免单目录文件过多（单目录超万个后 inode 查找会明显变慢）
            return $prefix
                . substr($hash, 0, 2) . '/'
                . substr($hash, 2, 2) . '/'
                . $hash . '.' . $extension;
        }

        $date = date('Y/m/d');
        $name = bin2hex(random_bytes(16)) . '.' . $extension;

        return $prefix . $date . '/' . $name;
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
            throw new UploadException('未配置上传签名密钥（upload.sign_secret）', 500, ErrorCode::UploadFailed);
        }

        return hash_hmac('sha256', $path . '|' . $expires, $secret);
    }
}
