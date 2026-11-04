<?php

declare(strict_types=1);

namespace SunsetReverie\Mail;

use SunsetReverie\Log\LogManager;
use SunsetReverie\Mail\Exception\MailException;

/**
 * 邮件管理器。
 *
 * 配置（config/mail.php）：
 *
 *   'default' => env('MAIL_MAILER', 'log'),
 *   'mailers' => [
 *       'log'   => ['driver' => 'log'],
 *       'smtp'  => ['driver' => 'smtp', 'host' => ..., 'port' => ..., ...],
 *   ],
 */
final class MailManager
{
    /** @var array<string, MailerInterface> 已创建的发送器 */
    private array $mailers = [];

    /**
     * @param array<string, mixed> $config 邮件配置
     * @param LogManager $log 日志（log 驱动使用）
     */
    public function __construct(
        private readonly array $config,
        private readonly LogManager $log,
    ) {
    }

    /**
     * 取发送器。
     *
     * @param string|null $name 名称（null 表示默认）
     * @return MailerInterface 发送器
     * @throws MailException 配置不存在时抛出
     */
    public function mailer(?string $name = null): MailerInterface
    {
        $name ??= (string) ($this->config['default'] ?? 'log');

        if (isset($this->mailers[$name])) {
            return $this->mailers[$name];
        }

        $mailerConfig = (array) ($this->config['mailers'][$name] ?? []);

        if ($mailerConfig === []) {
            throw new MailException(sprintf('未配置邮件发送器 [%s]，请在 config/mail.php 中定义', $name));
        }

        $this->mailers[$name] = match ((string) ($mailerConfig['driver'] ?? 'log')) {
            'log' => new LogMailer($this->log),
            'smtp' => new SmtpMailer(
                host: (string) ($mailerConfig['host'] ?? '127.0.0.1'),
                port: (int) ($mailerConfig['port'] ?? 25),
                username: (string) ($mailerConfig['username'] ?? ''),
                password: (string) ($mailerConfig['password'] ?? ''),
                from: (string) ($mailerConfig['from'] ?? 'noreply@example.com'),
                fromName: (string) ($mailerConfig['from_name'] ?? ''),
                timeout: (int) ($mailerConfig['timeout'] ?? 10),
                // null / 'ssl'（465）/ 'tls'（587）；空字符串归一成 null
                encryption: $this->normalizeEncryption($mailerConfig['encryption'] ?? null),
                verifyPeer: (bool) ($mailerConfig['verify_peer'] ?? true),
            ),
            default => throw new MailException(sprintf(
                '不支持的邮件驱动 [%s]，可用：log / smtp',
                (string) ($mailerConfig['driver'] ?? '')
            )),
        };

        return $this->mailers[$name];
    }

    /**
     * 直接发送。
     *
     * @param Message $message 邮件
     * @param string|null $mailer 发送器名
     * @return void
     */
    public function send(Message $message, ?string $mailer = null): void
    {
        $this->mailer($mailer)->send($message);
    }

    /**
     * 替换发送器（测试用）。
     *
     * @param string $name 名称
     * @param MailerInterface $mailer 发送器
     * @return self 当前实例
     */
    public function extend(string $name, MailerInterface $mailer): self
    {
        $this->mailers[$name] = $mailer;

        return $this;
    }

    /**
     * 归一化加密配置。
     *
     * `.env` 里没设置时读到的是空字符串（`MAIL_ENCRYPTION=`），
     * 直接传给 SmtpMailer 会被当成「不支持的值」报错。
     * 这里把空串、空格归一成 null（明文连接）。
     *
     * @param mixed $value 配置值
     * @return string|null null / 'ssl' / 'tls'
     */
    private function normalizeEncryption(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));

        return $normalized === '' ? null : $normalized;
    }
}
