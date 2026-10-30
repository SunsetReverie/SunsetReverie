<?php

declare(strict_types=1);

namespace SunsetReverie\Log;

use SunsetReverie\Log\Exception\LogException;
use SunsetReverie\Log\Formatter\FormatterInterface;
use SunsetReverie\Log\Formatter\JsonFormatter;
use SunsetReverie\Log\Formatter\LineFormatter;
use SunsetReverie\Log\Handler\FileHandler;
use SunsetReverie\Log\Handler\HandlerInterface;
use SunsetReverie\Log\Handler\NullHandler;
use SunsetReverie\Log\Handler\StreamHandler;

/**
 * 日志通道管理器：按配置构建 Logger，通道实例复用。
 *
 * 支持四种驱动：single（单文件）、daily（按天）、stderr（容器）、null（丢弃），
 * 以及 stack（聚合多个通道）。
 */
final class LogManager
{
    /** 内置默认通道（配置文件缺省时使用，保证框架开箱可用） */
    private const DEFAULT_CHANNELS = [
        'single' => ['driver' => 'single', 'path' => 'storage/logs/app.log'],
        'daily' => ['driver' => 'daily', 'path' => 'storage/logs', 'days' => 14],
        'stderr' => ['driver' => 'stderr'],
        'stdout' => ['driver' => 'stdout'],
        'null' => ['driver' => 'null'],
    ];

    /** @var array<string, Logger> 已创建的通道 */
    private array $channels = [];

    /** @var array<string, mixed> 合并默认值后的配置 */
    private readonly array $config;

    /**
     * @param array<string, mixed> $config 日志配置（default + level + channels）
     * @param string $basePath 项目根目录
     */
    public function __construct(array $config, private readonly string $basePath = '')
    {
        $this->config = [
            'default' => $config['default'] ?? 'daily',
            'level' => $config['level'] ?? 'debug',
            'access_log' => $config['access_log'] ?? true,
            'channels' => array_merge(self::DEFAULT_CHANNELS, (array) ($config['channels'] ?? [])),
        ];
    }

    /**
     * 获取通道。
     *
     * @param string|null $name 通道名，null 表示默认通道
     * @return Logger 日志记录器
     * @throws LogException 通道未配置时抛出
     */
    public function channel(?string $name = null): Logger
    {
        // $this->config 由构造函数构建成固定结构，'default' 必然存在
        $name ??= (string) $this->config['default'];

        if (isset($this->channels[$name])) {
            return $this->channels[$name];
        }

        $logger = $this->createLogger($name);
        $this->channels[$name] = $logger;

        return $logger;
    }

    /**
     * 未定义的方法转发到默认通道（供 Log 门面使用）。
     *
     * @param string $method 方法名
     * @param array<int, mixed> $arguments 参数
     * @return mixed 调用结果
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->channel()->{$method}(...$arguments);
    }

    /**
     * 注册现成的 Logger（测试或自定义通道使用）。
     *
     * @param string $name 通道名
     * @param Logger $logger 记录器
     * @return void
     */
    public function register(string $name, Logger $logger): void
    {
        $this->channels[$name] = $logger;
    }

    /**
     * 清除已创建的通道。
     *
     * @param string|null $name 通道名，null 表示全部
     * @return void
     */
    public function purge(?string $name = null): void
    {
        if ($name === null) {
            $this->channels = [];

            return;
        }

        unset($this->channels[$name]);
    }

    /**
     * 创建通道。
     *
     * @param string $name 通道名
     * @return Logger 日志记录器
     * @throws LogException 配置缺失时抛出
     */
    private function createLogger(string $name): Logger
    {
        $config = $this->config['channels'][$name] ?? null;

        if (!is_array($config)) {
            throw new LogException(sprintf(
                '未找到日志通道配置 [%s]，请在 config/log.php 中定义',
                $name
            ));
        }

        $driver = (string) ($config['driver'] ?? 'single');

        // stack：聚合多个子通道
        if ($driver === 'stack') {
            $handlers = [];

            foreach ((array) ($config['channels'] ?? []) as $child) {
                foreach ($this->handlersOf((string) $child) as $handler) {
                    $handlers[] = $handler;
                }
            }

            return new Logger($handlers, $name);
        }

        return new Logger($this->handlersOf($name), $name);
    }

    /**
     * 构造某通道的处理器列表。
     *
     * @param string $name 通道名
     * @return list<HandlerInterface> 处理器
     * @throws LogException 配置缺失时抛出
     */
    private function handlersOf(string $name): array
    {
        $config = $this->config['channels'][$name] ?? null;

        if (!is_array($config)) {
            throw new LogException(sprintf('未找到日志通道配置 [%s]', $name));
        }

        // 通道配置优先，其次全局；$this->config['level'] 必然存在
        $level = Level::fromString((string) ($config['level'] ?? $this->config['level']));
        $formatter = $this->formatter((string) ($config['formatter'] ?? 'json'));

        return match ((string) ($config['driver'] ?? 'single')) {
            'single' => [new FileHandler(
                $this->resolvePath((string) ($config['path'] ?? 'storage/logs/app.log')),
                $formatter,
                $level,
                $name,
            )],
            'daily' => [new FileHandler(
                $this->resolvePath((string) ($config['path'] ?? 'storage/logs')),
                $formatter,
                $level,
                $name,
                true,
                (int) ($config['days'] ?? 14),
            )],
            'stderr' => [new StreamHandler($formatter, $level, $name, 'stderr')],
            'stdout' => [new StreamHandler($formatter, $level, $name, 'stdout')],
            'null' => [new NullHandler()],
            default => throw new LogException(sprintf(
                '不支持的日志驱动 [%s]，可用：single / daily / stderr / stdout / null / stack',
                (string) ($config['driver'] ?? '')
            )),
        };
    }

    /**
     * 构造格式化器。
     *
     * @param string $name 格式化器名（json / line）
     * @return FormatterInterface 格式化器
     */
    private function formatter(string $name): FormatterInterface
    {
        return $name === 'line' ? new LineFormatter() : new JsonFormatter();
    }

    /**
     * 解析相对路径。
     *
     * @param string $path 路径
     * @return string 绝对路径
     */
    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1) {
            return $path;
        }

        return $this->basePath === '' ? $path : $this->basePath . '/' . ltrim($path, '/');
    }
}
