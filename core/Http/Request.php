<?php

declare(strict_types=1);

namespace SunsetReverie\Http;

use SunsetReverie\Support\Str;

/**
 * HTTP 请求对象（只读）。
 *
 * 设计要点：
 * 1. 一次性封装超全局变量，业务代码不再直接读 $_GET / $_POST / $_SERVER；
 * 2. JSON 解析失败不抛异常，由调用方通过 isValidJson() 判断；
 * 3. 请求 ID 优先复用合法客户端值，否则生成 UUIDv7。
 */
final class Request
{
    /** @var array<string, string> 规范化后的请求头（小写键） */
    private array $headers = [];

    /** @var array<string, mixed> 请求属性（中间件写入：认证用户、域信息等） */
    private array $attributes = [];

    /**
     * @param string $method HTTP 方法（大写）
     * @param string $path 请求路径（不含查询串）
     * @param array<string, mixed> $query 查询参数
     * @param array<string, mixed> $post 表单参数
     * @param array<string, mixed> $files 上传文件（$_FILES 结构）
     * @param string $rawBody 原始请求体
     * @param string $requestId 请求追踪 ID
     * @param string $ip 客户端 IP
     * @param array<string, string> $headers 请求头
     * @param array<string, mixed> $server 服务器参数
     */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query,
        /** @var array<string, mixed> 表单数据 */
        private readonly array $post,
        private readonly array $files,
        private readonly string $rawBody,
        public readonly string $requestId,
        public readonly string $ip,
        array $headers = [],
        private readonly array $server = [],
    ) {
        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = $value;
        }
    }

    /**
     * 从当前 PHP 运行环境捕获请求。
     *
     * @return self 请求实例
     */
    public static function capture(): self
    {
        $server = $_SERVER;
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';

        $headers = [];

        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[$name] = (string) $value;
            }
        }

        if (isset($server['CONTENT_TYPE'])) {
            $headers['Content-Type'] = (string) $server['CONTENT_TYPE'];
        }

        $requestId = $headers['X-Request-Id'] ?? $headers['x-request-id'] ?? '';

        if (!self::isValidRequestId($requestId)) {
            $requestId = Str::uuid7();
        }

        return new self(
            method: $method,
            path: $path,
            query: $_GET,
            post: $_POST,
            files: $_FILES,
            rawBody: (string) file_get_contents('php://input'),
            requestId: $requestId,
            ip: (string) ($server['REMOTE_ADDR'] ?? '0.0.0.0'),
            headers: $headers,
            server: $server,
        );
    }

    /**
     * 构造一个测试用请求（不依赖超全局变量）。
     *
     * @param string $method HTTP 方法
     * @param string $path 请求路径
     * @param array<string, mixed> $query 查询参数
     * @param string $rawBody 原始请求体
     * @param array<string, string> $headers 请求头
     * @param array<string, mixed> $files 上传文件
     * @return self 请求实例
     */
    public static function create(
        string $method = 'GET',
        string $path = '/',
        array $query = [],
        string $rawBody = '',
        array $headers = [],
        array $files = [],
    ): self {
        return new self(
            method: strtoupper($method),
            path: $path,
            query: $query,
            post: [],
            files: $files,
            rawBody: $rawBody,
            requestId: Str::uuid7(),
            ip: '127.0.0.1',
            headers: $headers,
        );
    }

    /**
     * 获取请求 Host（含端口时保留）。
     *
     * @return string 主机名
     */
    public function host(): string
    {
        return (string) ($this->headers['host'] ?? $this->server['HTTP_HOST'] ?? '');
    }

    /**
     * 读取请求头（大小写不敏感）。
     *
     * @param string $name 头名称
     * @param string|null $default 默认值
     * @return string|null 头值或默认值
     */
    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /**
     * 取分页大小（统一夹取）。
     *
     * 为什么要有这个方法：分页上限原来散落在每个领域服务里写 `min(50, ...)`，
     * 各写各的数字——改上限要翻遍代码，漏改一处就能被拉出 10 万条。
     *
     * @param int $default 客户端未指定时的默认值
     * @param int|null $max 上限（null 表示读 config('pagination.max_per_page')）
     * @return int 夹取后的每页条数
     */
    public function perPage(int $default = 20, ?int $max = null): int
    {
        $max ??= (int) config('pagination.max_per_page', 50);

        return max(1, min(max(1, $max), (int) $this->query('per_page', (string) $default)));
    }

    /**
     * 取页码（从 1 开始）。
     *
     * @return int 页码
     */
    public function page(): int
    {
        return max(1, (int) $this->query('page', '1'));
    }

    /**
     * 读取查询参数。
     *
     * @param string $key 参数名
     * @param mixed $default 默认值
     * @return mixed 参数值
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * 读取请求体字段（表单或 JSON）。
     *
     * @param string|null $key 字段名；null 表示返回整个请求体
     * @param mixed $default 默认值
     * @return mixed 字段值或数组
     */
    public function post(?string $key = null, mixed $default = null): mixed
    {
        $data = $this->bodyData();

        return $key === null ? $data : ($data[$key] ?? $default);
    }

    /**
     * 合并查询参数与请求体（查询参数在前，请求体覆盖同名键）。
     *
     * @return array<string, mixed> 全部输入
     */
    public function all(): array
    {
        return array_merge($this->query, $this->bodyData());
    }

    /**
     * 读取输入（请求体优先，其次查询参数）。
     *
     * @param string $key 字段名
     * @param mixed $default 默认值
     * @return mixed 字段值
     */
    public function input(string $key, mixed $default = null): mixed
    {
        $data = $this->bodyData();

        return $data[$key] ?? $this->query[$key] ?? $default;
    }

    /**
     * 解析请求体：表单优先，其次 JSON。
     *
     * @return array<string, mixed> 请求体数据
     */
    private function bodyData(): array
    {
        if ($this->post !== []) {
            return $this->post;
        }

        if ($this->rawBody === '') {
            return [];
        }

        if ($this->isJson()) {
            return $this->json();
        }

        // 表单编码：真实请求由 PHP 填入 $_POST，这里覆盖手工构造 Request 的场景
        $contentType = strtolower((string) $this->header('content-type', ''));

        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            // parse_str 产出 array<int|string, array|string>，这里按表单语义收敛
            parse_str($this->rawBody, $data);

            /** @var array<string, mixed> $data */
            return $data;
        }

        return [];
    }

    /**
     * 获取原始请求体。
     *
     * @return string 请求体
     */
    public function body(): string
    {
        return $this->rawBody;
    }

    /**
     * 解析 JSON 请求体。
     *
     * @return array<string, mixed> 解析结果
     * @throws \SunsetReverie\Exception\HttpException 非法 JSON 时抛出
     */
    public function json(): array
    {
        if ($this->rawBody === '') {
            return [];
        }

        $decoded = json_decode($this->rawBody, true);

        if (!is_array($decoded)) {
            throw \SunsetReverie\Exception\HttpException::fromCode(
                \SunsetReverie\Exception\ErrorCode::InvalidJson
            );
        }

        return $decoded;
    }

    /**
     * 获取上传文件。
     *
     * 返回的对象即使上传失败也会带出错误码（用 isValid() 判断），
     * 便于直接给出"文件过大"这类提示，而不是笼统的"没有文件"。
     *
     * @param string $key 字段名
     * @return UploadedFile|null 文件对象
     */
    public function file(string $key): ?UploadedFile
    {
        $file = $this->files[$key] ?? null;

        if (!is_array($file)) {
            return null;
        }

        return UploadedFile::fromArray($file);
    }

    /**
     * 是否存在指定字段的上传。
     *
     * @param string $key 字段名
     * @return bool 存在返回 true
     */
    public function hasFile(string $key): bool
    {
        $file = $this->files[$key] ?? null;

        return is_array($file)
            && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    }

    /**
     * 读取请求属性（中间件写入的派生数据）。
     *
     * @param string $key 属性名
     * @param mixed $default 默认值
     * @return mixed 属性值
     */
    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * 写入请求属性（返回新实例，保持不可变语义）。
     *
     * @param string $key 属性名
     * @param mixed $value 值
     * @return self 新请求实例
     */
    public function withAttribute(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$key] = $value;

        return $clone;
    }

    /**
     * 是否为 JSON 请求。
     *
     * @return bool 是则 true
     */
    public function isJson(): bool
    {
        return str_contains(strtolower((string) $this->header('content-type')), 'application/json');
    }

    /**
     * 获取客户端 IP（生产环境必须配合可信代理解析）。
     *
     * @return string IP 地址
     */
    public function ip(): string
    {
        return $this->ip;
    }

    /**
     * 校验客户端传入的请求 ID 是否合法（长度 8~64，仅字母数字与连字符）。
     *
     * @param string $value 待校验值
     * @return bool 合法返回 true
     */
    private static function isValidRequestId(string $value): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9\-]{8,64}$/', $value);
    }
}
