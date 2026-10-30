<?php

declare(strict_types=1);

namespace SunsetReverie\Log\Handler;

use SunsetReverie\Log\Exception\LogException;
use SunsetReverie\Log\Formatter\FormatterInterface;
use SunsetReverie\Log\Level;

/**
 * 文件处理器：支持单文件与按天切分。
 *
 * 写入使用 `FILE_APPEND | LOCK_EX`：同一文件的并发追加由内核 flock 保证，
 * 不会出现两条日志互相穿插。目录不可写时抛出 LogException（不静默丢弃）。
 */
final class FileHandler implements HandlerInterface
{
    /**
     * @param string $path 单文件路径，或按天模式的目录
     * @param FormatterInterface $formatter 格式化器
     * @param Level $minimum 最低记录级别
     * @param string $channel 通道名
     * @param bool $daily 是否按天切分
     * @param int $days 保留天数（0 表示不清理）
     */
    public function __construct(
        private readonly string $path,
        private readonly FormatterInterface $formatter,
        private readonly Level $minimum = Level::Debug,
        private readonly string $channel = 'app',
        private readonly bool $daily = false,
        private readonly int $days = 0,
    ) {
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
        $file = $this->resolveFile();

        $directory = dirname($file);

        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new LogException(sprintf('日志目录不可创建：[%s]', $directory));
        }

        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new LogException(sprintf('日志写入失败：[%s]', $file));
        }

        if ($this->daily && $this->days > 0) {
            $this->prune();
        }
    }

    /**
     * 解析当前应写入的文件路径。
     *
     * @return string 文件路径
     */
    public function resolveFile(): string
    {
        if (!$this->daily) {
            return $this->path;
        }

        return rtrim($this->path, '/') . '/' . $this->channel . '-' . date('Y-m-d') . '.log';
    }

    /**
     * 清理超过保留天数的日志文件。
     *
     * @return void
     */
    private function prune(): void
    {
        $files = glob(rtrim($this->path, '/') . '/' . $this->channel . '-*.log') ?: [];
        $deadline = strtotime('-' . $this->days . ' days');

        if ($deadline === false) {
            return;
        }

        foreach ($files as $file) {
            $modified = @filemtime($file);

            if ($modified !== false && $modified < $deadline) {
                @unlink($file);
            }
        }
    }
}
