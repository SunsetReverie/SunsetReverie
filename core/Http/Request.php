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

    /**
     * @param string $method HTTP 方法（大写）
     * @param string $path 请求路径（不含查询串）
     * @param array<string, mixed> $query 查询参数
     * @param array<string, mixed> $post 表单参数
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
        private readonly array $post,
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
     * @return self 请求实例
     */
    public static function create(
        string $method = 'GET',
        string $path = '/',
        array $query = [],
        string $rawBody = '',
        array $headers = [],
    ): self {
        return new self(
            method: strtoupper($method),
            path: $path,
            query: $query,
            post: [],
            rawBody: $rawBody,
            requestId: Str::uuid7(),
            ip: '127.0.0.1',
            headers: $headers,
        );
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
     * 读取输入（优先表单，其次查询）。
     *
     * @param string $key 字段名
     * @param mixed $default 默认值
     * @return mixed 字段值
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
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
