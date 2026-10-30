<?php

declare(strict_types=1);

namespace SunsetReverie\Log\Handler;

use SunsetReverie\Log\Formatter\FormatterInterface;
use SunsetReverie\Log\Level;

/**
 * 流处理器：写到 stderr / stdout。
 *
 * 容器环境（Docker、K8s）推荐使用：日志由运行时统一采集，
 * 不需要在容器内做文件轮转。
 */
final class StreamHandler implements HandlerInterface
{
    /** @var resource 输出流 */
    private $stream;

    /**
     * @param FormatterInterface $formatter 格式化器
     * @param Level $minimum 最低记录级别
     * @param string $channel 通道名
     * @param string $target 输出目标：stderr 或 stdout
     */
    public function __construct(
        private readonly FormatterInterface $formatter,
        private readonly Level $minimum = Level::Debug,
        private readonly string $channel = 'app',
        string $target = 'stderr',
    ) {
        $stream = @fopen('php://' . $target, 'wb');

        if ($stream === false) {
            throw new \RuntimeException(sprintf('无法打开输出流 php://%s', $target));
        }

        $this->stream = $stream;
    }

    /**
     * {@inheritDoc}
     */
    public function isHandling(Level $level): bool
    {
        return $level->allows($this->minimum);
    }

    /**
     * {@inheritDoc}
     */
    public function handle(Level $level, string $message, array $context): void
    {
        if (!$this->isHandling($level)) {
            return;
        }

        $line = $this->formatter->format($level, $message, $context, $this->channel) . PHP_EOL;

        @fwrite($this->stream, $line);
    }
}
