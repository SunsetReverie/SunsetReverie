<?php

declare(strict_types=1);

namespace SunsetReverie\Mail;

use SunsetReverie\Log\LogManager;

/**
 * 日志邮件发送器（不发真邮件，只写日志）。
 *
 * 用途：
 * - 本地开发：不想配 SMTP，但想确认「邮件内容正确、队列链路通」
 * - 测试：断言邮件内容而不依赖外部服务
 */
final class LogMailer implements MailerInterface
{
    /**
     * @param LogManager $log 日志管理器
     */
    public function __construct(private readonly LogManager $log)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function send(Message $message): void
    {
        // LogManager 没有 info()，只靠 __call 转发；显式取 channel 更清楚
        $this->log->channel()->info('邮件（未实际发送）', [
            'to' => $message->to,
            'subject' => $message->subject,
            'text' => $message->plainText(),
        ]);
    }
}
