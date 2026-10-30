<?php

declare(strict_types=1);

namespace SunsetReverie\Mail;

use SunsetReverie\Mail\Exception\MailException;

/**
 * 明文 SMTP 发送器（零依赖，用 fsockopen 手写协议）。
 *
 * 支持：EHLO、AUTH LOGIN、DATA。
 * **不支持 STARTTLS / SMTPS**——需要加密通道时应使用内网 relay 或
 * 部署本地 smtp relay（如 postfix / mailpit）后再由它转发。
 *
 * 为什么不做 TLS：证书校验、SNI、协议降级这些细节手写极易出错，
 * 而"发邮件"这件事 90% 的场景是走内网 relay 或第三方 API，
 * 与其提供一个半可靠的 TLS 实现，不如明确边界。
 *
 * 正文统一用 base64 编码：避免行长度限制、避免 8bit 乱码、
 * 也避免内容里出现单独一行 "."（SMTP 的结束标记）。
 */
final class SmtpMailer implements MailerInterface
{
    /**
     * @param string $host SMTP 主机
     * @param int $port 端口（mailpit 默认 1025）
     * @param string $username 用户名（空表示不认证）
     * @param string $password 密码
     * @param string $from 发件人地址
     * @param string $fromName 发件人显示名
     * @param int $timeout 超时秒数
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port = 25,
        private readonly string $username = '',
        private readonly string $password = '',
        private readonly string $from = 'noreply@example.com',
        private readonly string $fromName = '',
        private readonly int $timeout = 10,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function send(Message $message): void
    {
        if (!filter_var($message->to, FILTER_VALIDATE_EMAIL)) {
            throw new MailException(sprintf('收件人地址不合法：%s', $message->to));
        }

        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);

        if ($socket === false) {
            throw new MailException(sprintf('无法连接 SMTP %s:%d（%s）', $this->host, $this->port, $errstr ?: 'unknown'));
        }

        stream_set_timeout($socket, $this->timeout);

        try {
            $this->expect($socket, '220');
            $this->command($socket, 'EHLO ' . $this->helo(), '250');

            if ($this->username !== '') {
                $this->command($socket, 'AUTH LOGIN', '334');
                $this->command($socket, base64_encode($this->username), '334');
                $this->command($socket, base64_encode($this->password), '235');
            }

            $this->command($socket, sprintf('MAIL FROM:<%s>', $this->from), '250');
            $this->command($socket, sprintf('RCPT TO:<%s>', $message->to), '250');
            $this->command($socket, 'DATA', '354');

            fwrite($socket, $this->buildPayload($message) . "\r\n.\r\n");
            $this->expect($socket, '250');

            $this->command($socket, 'QUIT', '221');
        } finally {
            @fclose($socket);
        }
    }

    /**
     * 拼装 MIME 报文。
     *
     * @param Message $message 邮件
     * @return string 报文（已包含 DATA 段的头部与正文）
     */
    private function buildPayload(Message $message): string
    {
        $boundary = 'sr-' . bin2hex(random_bytes(8));

        $headers = [
            sprintf('From: %s', $this->fromName !== ''
                ? sprintf('=?UTF-8?B?%s?= <%s>', base64_encode($this->fromName), $this->from)
                : sprintf('<%s>', $this->from)),
            sprintf('To: %s', $message->toName !== ''
                ? sprintf('=?UTF-8?B?%s?= <%s>', base64_encode($message->toName), $message->to)
                : sprintf('<%s>', $message->to)),
            sprintf('Subject: =?UTF-8?B?%s?=', base64_encode($message->subject)),
            sprintf('Date: %s', date(DATE_RFC2822)),
            'MIME-Version: 1.0',
            sprintf('Content-Type: multipart/alternative; boundary="%s"', $boundary),
        ];

        // 正文分行 base64：单行过长会被部分 MTA 截断
        $encode = static fn (string $body): string => chunk_split(base64_encode($body), 76, "\r\n");

        $body = [
            sprintf('--%s', $boundary),
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            $encode($message->plainText()),
            sprintf('--%s', $boundary),
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            $encode($message->html),
            sprintf('--%s--', $boundary),
        ];

        return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $body);
    }

    /**
     * 发送一条命令并校验应答码。
     *
     * @param resource $socket 连接
     * @param string $line 命令
     * @param string $expect 期望的应答码前缀
     * @return void
     * @throws MailException 应答码不符时抛出
     */
    private function command($socket, string $line, string $expect): void
    {
        fwrite($socket, $line . "\r\n");
        $this->expect($socket, $expect);
    }

    /**
     * 读取应答并校验。
     *
     * 多行应答（250-xxx）需要读到最后一个 "250 " 行为止。
     *
     * @param resource $socket 连接
     * @param string $expect 期望的应答码前缀
     * @return void
     * @throws MailException 应答码不符或读超时时抛出
     */
    private function expect($socket, string $expect): void
    {
        $response = '';

        while (($line = fgets($socket, 1024)) !== false) {
            $response .= $line;

            // 多行应答：第 4 个字符是 "-" 表示还有后续
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        if ($response === '') {
            throw new MailException('SMTP 无响应（连接可能已被关闭）');
        }

        if (!str_starts_with($response, $expect)) {
            throw new MailException(sprintf(
                'SMTP 应答异常，期望 %s，实际：%s',
                $expect,
                trim($response)
            ));
        }
    }

    /**
     * EHLO 用的主机名。
     *
     * @return string 主机名
     */
    private function helo(): string
    {
        $host = gethostname();

        return is_string($host) && $host !== '' ? $host : 'localhost';
    }
}
