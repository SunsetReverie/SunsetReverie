<?php

declare(strict_types=1);

namespace Tests\Unit;

use SunsetReverie\Mail\Exception\MailException;
use SunsetReverie\Mail\Message;
use SunsetReverie\Mail\SmtpMailer;
use Tests\TestCase;

/**
 * SMTP 的连接方式：明文 / STARTTLS / SMTPS。
 *
 * 背景：早期实现只支持明文（`fsockopen` 裸连），而 QQ、163、Gmail、
 * 阿里云邮件推送这些主流服务都要求 465（SMTPS）或 587（STARTTLS）。
 * 实测 QQ 587 会直接回：
 *
 *   530 Login fail. A secure connection is requiered(such as ssl)
 *
 * 也就是说内置 SMTP 在公网发信这条路是堵死的。
 *
 * 这里不连真实服务（跑测试时不该依赖网络和凭据），而是用 fork 起一个
 * 假 SMTP 服务器，验证客户端的**命令序列**是否正确——这才是容易写错的地方：
 *
 *   - STARTTLS 之后必须重新 EHLO（RFC 3207）
 *   - 465 必须先建 TLS 再读欢迎行
 *   - 服务器不支持 STARTTLS 时要明确报错，而不是继续明文发信
 */
final class SmtpTlsTest extends TestCase
{
    /** @var list<int> 已 fork 的子进程 */
    private array $children = [];

    protected function tearDown(): void
    {
        foreach ($this->children as $pid) {
            @posix_kill($pid, SIGKILL);
            @pcntl_waitpid($pid, $status);
        }

        $this->children = [];
    }

    /**
     * 起一个假 SMTP 服务器。
     *
     * 子进程按 $replies 顺序应答，每收到一行发一条。
     * 返回监听端口。
     *
     * @param list<string> $replies 依次要发的应答（含 CRLF）
     * @param string $record 子进程收到的命令写到这里（临时文件路径）
     * @return int 端口
     */
    private function startFakeServer(array $replies, string $record): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($server === false) {
            $this->fail('无法创建假 SMTP 服务器：' . $errstr);
        }

        $name = (string) stream_socket_get_name($server, false);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('fork 失败');
        }

        if ($pid === 0) {
            // ── 子进程：扮演服务器 ──
            $client = @stream_socket_accept($server, 5);

            if ($client === false) {
                exit(1);
            }

            stream_set_timeout($client, 5);

            $log = '';

            foreach ($replies as $reply) {
                fwrite($client, $reply);

                // 221（QUIT 应答）之后不再等命令
                if (str_starts_with($reply, '221')) {
                    break;
                }

                /*
                 * 354 之后客户端发的是整封报文（多行），要读到单独一行 "."
                 * 为止——只读一行会让后面的命令序列全部错位。
                 */
                if (str_starts_with($reply, '354')) {
                    $payload = '';

                    while (($line = fgets($client, 4096)) !== false) {
                        $payload .= $line;

                        if (rtrim($line, "\r\n") === '.') {
                            break;
                        }
                    }

                    $log .= sprintf("[DATA] %d bytes\r\n", strlen($payload));

                    continue;
                }

                $line = fgets($client, 4096);

                if ($line === false) {
                    break;
                }

                $log .= $line;
            }

            file_put_contents($record, $log);

            @fclose($client);
            @fclose($server);

            exit(0);
        }

        $this->children[] = $pid;

        return $port;
    }

    /**
     * 明文模式：完整走一遍 EHLO → AUTH → MAIL → RCPT → DATA → QUIT。
     *
     * @return void
     */
    public function testPlainConversation(): void
    {
        $record = sys_get_temp_dir() . '/sr-smtp-plain-' . getmypid() . '.log';

        $port = $this->startFakeServer([
            "220 fake ESMTP\r\n",
            "250-fake greets you\r\n250-AUTH LOGIN\r\n250 SIZE 10240000\r\n",   // EHLO
            "334 VXNlcm5hbWU6\r\n",   // AUTH LOGIN
            "334 UGFzc3dvcmQ6\r\n",   // username
            "235 accepted\r\n",       // password
            "250 sender ok\r\n",      // MAIL FROM
            "250 recipient ok\r\n",   // RCPT TO
            "354 start mail input\r\n",   // DATA
            "250 queued\r\n",         // 报文内容
            "221 bye\r\n",            // QUIT
        ], $record);

        $mailer = new SmtpMailer(
            host: '127.0.0.1',
            port: $port,
            username: 'u@example.com',
            password: 'secret',
            from: 'noreply@example.com',
            timeout: 5,
        );

        $mailer->send(new Message(to: 'a@example.com', subject: '测试', html: '<p>hi</p>'));

        // 等子进程写日志
        for ($i = 0; $i < 50 && !is_file($record); $i++) {
            usleep(20000);
        }

        $commands = is_file($record) ? (string) file_get_contents($record) : '';
        @unlink($record);

        $this->assertStringContains('EHLO', $commands, '应发 EHLO');
        $this->assertStringContains('AUTH LOGIN', $commands, '应发 AUTH LOGIN');
        $this->assertStringContains('MAIL FROM:<noreply@example.com>', $commands, 'MAIL FROM 应带发件人');
        $this->assertStringContains('RCPT TO:<a@example.com>', $commands, 'RCPT TO 应带收件人');
        $this->assertStringContains('DATA', $commands, '应发 DATA');
        $this->assertStringContains('QUIT', $commands, '应发 QUIT');
    }

    /**
     * STARTTLS 模式：握手前先发 STARTTLS 命令。
     *
     * 这里服务器回 500（不支持），所以握手会失败——测试的是
     * 「客户端确实发了 STARTTLS」以及「失败时明确报错，不静默降级成明文」。
     *
     * @return void
     */
    public function testStartTlsIsRequestedAndFailureIsLoud(): void
    {
        $record = sys_get_temp_dir() . '/sr-smtp-tls-' . getmypid() . '.log';

        $port = $this->startFakeServer([
            "220 fake ESMTP\r\n",
            "250-fake greets you\r\n250 SIZE 10240000\r\n",   // EHLO：没有 STARTTLS 扩展
            "500 command not recognized\r\n",                  // STARTTLS → 拒绝
        ], $record);

        $mailer = new SmtpMailer(
            host: '127.0.0.1',
            port: $port,
            from: 'noreply@example.com',
            timeout: 5,
            encryption: 'tls',
        );

        $e = $this->assertThrows(
            MailException::class,
            static fn () => $mailer->send(new Message(to: 'a@example.com', subject: 's', html: 'h'))
        );

        $this->assertStringContains('STARTTLS', $e->getMessage());

        for ($i = 0; $i < 50 && !is_file($record); $i++) {
            usleep(20000);
        }

        $commands = is_file($record) ? (string) file_get_contents($record) : '';
        @unlink($record);

        $this->assertStringContains('STARTTLS', $commands, '客户端应主动发 STARTTLS');

        // 关键：失败后不能继续用明文发信
        $this->assertFalse(
            str_contains($commands, 'AUTH LOGIN'),
            'STARTTLS 失败后不应该继续认证（那等于明文泄露密码）'
        );
        $this->assertFalse(
            str_contains($commands, 'MAIL FROM'),
            'STARTTLS 失败后不应该继续发信'
        );
    }

    /**
     * 非法的 encryption 值应在构造时就报错。
     *
     * 拼错成 'starttls' / 'SSL ' 之类很常见，等到发信时才报错更难查。
     *
     * @return void
     */
    public function testRejectsUnknownEncryption(): void
    {
        foreach (['starttls', 'SSL', 'tlsv1', 'none'] as $bad) {
            $e = $this->assertThrows(
                MailException::class,
                static fn () => new SmtpMailer(host: '127.0.0.1', port: 25, encryption: $bad)
            );

            $this->assertStringContains('不支持的加密方式', $e->getMessage());
            $this->assertStringContains($bad, $e->getMessage());
        }
    }

    /**
     * SMTPS 模式下，连接阶段就完成 TLS 握手。
     *
     * 用一个不支持 TLS 的端口（假服务器回明文）验证：
     * ssl 模式不会退化成明文，而是直接失败。
     *
     * @return void
     */
    public function testSslDoesNotFallBackToPlaintext(): void
    {
        $record = sys_get_temp_dir() . '/sr-smtp-ssl-' . getmypid() . '.log';

        // 服务器只发了明文欢迎行就挂起——TLS 握手拿不到有效数据
        $port = $this->startFakeServer([
            "220 fake ESMTP\r\n",
            "250 ok\r\n",
        ], $record);

        $mailer = new SmtpMailer(
            host: '127.0.0.1',
            port: $port,
            from: 'noreply@example.com',
            timeout: 3,
            encryption: 'ssl',
        );

        $this->assertThrows(
            MailException::class,
            static fn () => $mailer->send(new Message(to: 'a@example.com', subject: 's', html: 'h'))
        );

        @unlink($record);
    }

    /**
     * null 表示明文，构造不报错。
     *
     * 注意：空字符串 `''` 会被构造函数拒绝——`.env` 里 `MAIL_ENCRYPTION=` 读出来
     * 就是空串，所以归一化放在 MailManager::normalizeEncryption()，
     * 由它把空串转成 null 再交给 SmtpMailer。
     *
     * 这样分工的好处：SmtpMailer 只接受明确的三种值，拼错立刻报错；
     * 而「没配」这类输入在边界处就被处理掉了。
     *
     * @return void
     */
    public function testNullEncryptionIsPlaintext(): void
    {
        // 构造不抛异常即通过——非法值（如 'starttls'）会在构造时就抛
        new SmtpMailer(host: '127.0.0.1', port: 25, encryption: null);

        $this->assertTrue(true, 'null 是合法的加密方式（明文）');
    }
}
