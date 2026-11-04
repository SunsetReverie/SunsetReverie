<?php

declare(strict_types=1);

namespace SunsetReverie\Mail;

use SunsetReverie\Mail\Exception\MailException;

/**
 * SMTP 发送器（零依赖，手写协议）。
 *
 * 支持三种连接方式：
 *
 *   encryption = null   明文（25 端口，或内网 relay）
 *   encryption = 'ssl'  隐式 TLS（465 端口，QQ / 163 / Gmail 的 SMTPS）
 *   encryption = 'tls'  显式 TLS / STARTTLS（587 端口）
 *
 * 协议命令：EHLO、AUTH LOGIN、DATA。STARTTLS 模式下会走完整握手：
 *
 *   连接 → 220 → EHLO(250, 含 STARTTLS) → STARTTLS → 220 → TLS 握手
 *        → 重新 EHLO → AUTH LOGIN → MAIL FROM → RCPT TO → DATA → QUIT
 *
 * **握手后必须重新 EHLO**——RFC 3207 要求客户端在 TLS 建立后丢弃之前
 * 收到的所有扩展信息。漏了这一步，部分服务器会拒绝后续命令。
 *
 * 默认校验对端证书（verifyPeer = true）。只有连自签证书的内网 relay
 * 才应该关掉——关掉等于放弃中间人防护。
 *
 * 正文统一用 base64 编码：避免行长度限制、避免 8bit 乱码、
 * 也避免内容里出现单独一行 "."（SMTP 的结束标记）。
 */
final class SmtpMailer implements MailerInterface
{
    /** @var resource|null 当前连接（send 期间使用） */
    private $socket = null;

    /**
     * @param string $host SMTP 主机
     * @param int $port 端口（明文 25 / STARTTLS 587 / SMTPS 465 / mailpit 1025）
     * @param string $username 用户名（空表示不认证）
     * @param string $password 密码
     * @param string $from 发件人地址
     * @param string $fromName 发件人显示名
     * @param int $timeout 超时秒数
     * @param string|null $encryption 加密方式：null / 'ssl' / 'tls'
     * @param bool $verifyPeer 是否校验对端证书（仅内网自签证书才关）
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port = 25,
        private readonly string $username = '',
        private readonly string $password = '',
        private readonly string $from = 'noreply@example.com',
        private readonly string $fromName = '',
        private readonly int $timeout = 10,
        private readonly ?string $encryption = null,
        private readonly bool $verifyPeer = true,
    ) {
        if ($this->encryption !== null && !in_array($this->encryption, ['ssl', 'tls'], true)) {
            throw new MailException(sprintf(
                '不支持的加密方式 [%s]，可用：null（明文）/ ssl（SMTPS，465）/ tls（STARTTLS，587）',
                $this->encryption
            ));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function send(Message $message): void
    {
        if (!filter_var($message->to, FILTER_VALIDATE_EMAIL)) {
            throw new MailException(sprintf('收件人地址不合法：%s', $message->to));
        }

        $this->socket = $this->open();

        try {
            $this->expect('220');
            $this->command('EHLO ' . $this->helo(), '250');

            // STARTTLS：明文打招呼 → 升级 → 重新打招呼
            if ($this->encryption === 'tls') {
                $this->startTls();
                $this->command('EHLO ' . $this->helo(), '250');
            }

            if ($this->username !== '') {
                $this->command('AUTH LOGIN', '334');
                $this->command(base64_encode($this->username), '334');
                $this->command(base64_encode($this->password), '235');
            }

            $this->command(sprintf('MAIL FROM:<%s>', $this->from), '250');
            $this->command(sprintf('RCPT TO:<%s>', $message->to), '250');
            $this->command('DATA', '354');

            $this->write($this->buildPayload($message) . "\r\n.\r\n");
            $this->expect('250');

            $this->command('QUIT', '221');
        } finally {
            if ($this->socket !== null) {
                @fclose($this->socket);
                $this->socket = null;
            }
        }
    }

    /**
     * 建立连接。
     *
     * 用 stream_socket_client 而不是 fsockopen：后者不接受 stream context，
     * 没法设置 SNI 和证书校验选项。
     *
     * @return resource 已建立的连接
     * @throws MailException 连接失败时抛出
     */
    private function open()
    {
        $context = stream_context_create([
            'ssl' => [
                // 关掉校验只适合内网自签证书；公网服务必须开着
                'verify_peer' => $this->verifyPeer,
                'verify_peer_name' => $this->verifyPeer,
                // SNI：同一 IP 上可能托管多个域名，不传会被返回默认证书
                'peer_name' => $this->host,
                // 不允许降到旧版本（1.0 / 1.1 已废弃，且多数服务已停用）
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                    | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            ],
        ]);

        // ssl:// 表示「连接即 TLS」；tcp:// 先明文，需要时再升级
        $scheme = $this->encryption === 'ssl' ? 'ssl' : 'tcp';
        $remote = sprintf('%s://%s:%d', $scheme, $this->host, $this->port);

        $socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new MailException(sprintf(
                '无法连接 SMTP %s:%d（%s）%s',
                $this->host,
                $this->port,
                $errstr !== '' ? $errstr : 'unknown',
                $this->encryption === 'ssl' ? '——SMTPS 需要服务端支持隐式 TLS' : ''
            ));
        }

        stream_set_timeout($socket, $this->timeout);

        return $socket;
    }

    /**
     * 请求 STARTTLS 并升级连接。
     *
     * 单独包一层是为了让报错信息带上「STARTTLS」这个词。
     * 走通用 command() 时，服务器回 500 只会抛「应答异常，期望 220」——
     * 使用者看不出是哪一步失败，也不知道该换成 ssl 还是明文。
     *
     * @return void
     * @throws MailException 服务器拒绝或握手失败时抛出
     */
    private function startTls(): void
    {
        try {
            $this->command('STARTTLS', '220');
        } catch (MailException $e) {
            throw new MailException(sprintf(
                'STARTTLS 被拒绝（%s:%d）：%s —— 服务端可能不支持 STARTTLS，'
                . '改用 ssl（465 端口）或明文（25 端口 / 内网 relay）',
                $this->host,
                $this->port,
                $e->getMessage()
            ));
        }

        $this->upgradeToTls();
    }

    /**
     * 把已建立的明文连接升级为 TLS（STARTTLS 的第二步）。
     *
     * @return void
     * @throws MailException 握手失败时抛出
     */
    private function upgradeToTls(): void
    {
        if ($this->socket === null) {
            throw new MailException('STARTTLS 失败：连接已关闭');
        }

        $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

        $result = @stream_socket_enable_crypto($this->socket, true, $method);

        if ($result !== true) {
            throw new MailException(sprintf(
                'STARTTLS 握手失败（%s:%d）——服务端可能只支持 TLS 1.0/1.1，'
                . '或证书校验未通过（自签证书可把 verify_peer 设为 false）',
                $this->host,
                $this->port
            ));
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
     * @param string $line 命令
     * @param string $expect 期望的应答码前缀
     * @return void
     * @throws MailException 应答码不符时抛出
     */
    private function command(string $line, string $expect): void
    {
        $this->write($line . "\r\n");
        $this->expect($expect);
    }

    /**
     * 写入数据。
     *
     * @param string $data 数据
     * @return void
     * @throws MailException 写入失败时抛出
     */
    private function write(string $data): void
    {
        if ($this->socket === null) {
            throw new MailException('SMTP 连接已关闭');
        }

        if (@fwrite($this->socket, $data) === false) {
            throw new MailException('SMTP 写入失败（连接可能已被关闭）');
        }
    }

    /**
     * 读取应答并校验。
     *
     * 多行应答（250-xxx）需要读到最后一个 "250 " 行为止。
     *
     * @param string $expect 期望的应答码前缀
     * @return void
     * @throws MailException 应答码不符或读超时时抛出
     */
    private function expect(string $expect): void
    {
        if ($this->socket === null) {
            throw new MailException('SMTP 连接已关闭');
        }

        $response = '';

        while (($line = fgets($this->socket, 1024)) !== false) {
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
