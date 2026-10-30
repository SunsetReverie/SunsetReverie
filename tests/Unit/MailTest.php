<?php

declare(strict_types=1);

namespace Tests\Unit;

use SunsetReverie\Mail\Exception\MailException;
use SunsetReverie\Mail\Message;
use SunsetReverie\Mail\SmtpMailer;
use Tests\TestCase;

/**
 * 邮件组件测试。
 *
 * SMTP 部分只测「连不上时明确失败」——真实收发依赖外部服务，
 * 放在集成验证里跑（见 xi-blog 的假 SMTP 服务器验证）。
 */
final class MailTest extends TestCase
{
    /**
     * 未提供纯文本时，应从 HTML 剥离出可读文本。
     *
     * @return void
     */
    public function testPlainTextIsDerivedFromHtml(): void
    {
        $message = new Message(
            to: 'a@example.com',
            subject: '测试',
            html: '<p>你好</p><p>第二段</p><script>alert(1)</script>',
        );

        $text = $message->plainText();

        $this->assertStringContains('你好', $text);
        $this->assertStringContains('第二段', $text);
        $this->assertFalse(str_contains($text, '<p>'), '不应残留标签');
        $this->assertFalse(str_contains($text, 'alert'), 'script 内容应被剔除');
    }

    /**
     * 显式提供纯文本时优先使用。
     *
     * @return void
     */
    public function testExplicitTextWins(): void
    {
        $message = new Message(to: 'a@example.com', subject: 's', html: '<p>html</p>', text: '手动文本');

        $this->assertSame('手动文本', $message->plainText());
    }

    /**
     * 序列化往返（进队列再取出）应保持一致。
     *
     * @return void
     */
    public function testArrayRoundTrip(): void
    {
        $original = new Message(
            to: 'user@example.com',
            subject: '评论回复通知',
            html: '<p>有人回复了你</p>',
            toName: '夕梦',
        );

        $restored = Message::fromArray($original->toArray());

        $this->assertSame($original->to, $restored->to);
        $this->assertSame($original->subject, $restored->subject);
        $this->assertSame($original->html, $restored->html);
        $this->assertSame($original->toName, $restored->toName);
    }

    /**
     * 收件人非法时应明确报错（而不是交给 SMTP 后失败）。
     *
     * @return void
     */
    public function testRejectsInvalidRecipient(): void
    {
        $mailer = new SmtpMailer(host: '127.0.0.1', port: 25);

        $e = $this->assertThrows(
            MailException::class,
            static fn () => $mailer->send(new Message(to: 'not-an-email', subject: 's', html: 'h'))
        );

        $this->assertStringContains('收件人地址不合法', $e->getMessage());
    }

    /**
     * 连接失败应抛出 MailException（队列靠异常重试）。
     *
     * @return void
     */
    public function testConnectionFailureThrows(): void
    {
        // 用不可能的端口，连接会立即失败
        $mailer = new SmtpMailer(host: '127.0.0.1', port: 1, timeout: 1);

        $this->assertThrows(
            MailException::class,
            static fn () => $mailer->send(new Message(to: 'a@example.com', subject: 's', html: 'h'))
        );
    }
}
