# 邮件

```php
use SunsetReverie\Mail\MailManager;
```

::: warning 没有 `Mail` 门面
`Mail`、`Upload`、`Image`、`Queue` 这四类**没有静态门面**——它们需要配置，
用构造函数注入或 `Application` 访问器。

只有这 7 个有门面：`Auth` `Cache` `Config` `DB` `Log` `Route` `Validator`。
:::

## 两个驱动

| 驱动 | 行为 | 适合 |
| --- | --- | --- |
| `log` | 写进日志，不真发 | **开发环境**（默认） |
| `smtp` | 连 SMTP 服务器发送 | 生产 |

```php
// config/mail.php
'default' => env('MAIL_MAILER', 'log'),     // 默认是 log，生产记得改
```

## 一封邮件

```php
use SunsetReverie\Mail\Message;

$message = new Message(
    to: 'user@example.com',
    subject: '【夕梦】你的文章收到了新评论',
    html: '<h1>新评论</h1><p>有人说：写得好</p>',
    text: '新评论：写得好',       // 留空会自动从 HTML 剥离
    toName: '夕梦用户',
);

$this->mail->send($message);
```

`$this->mail` 从构造函数注入：

```php
final class NotifyService
{
    public function __construct(private readonly MailManager $mail)
    {
    }
}
```

不想注入时可以用访问器：

```php
Application::getInstance()->mail()->send($message);
```

### `Message` 的五个字段

| 字段 | 必填 | 说明 |
| --- | --- | --- |
| `to` | ✅ | 收件人邮箱 |
| `subject` | ✅ | 主题 |
| `html` | ✅ | HTML 正文 |
| `text` | — | 纯文本正文，留空则从 HTML 剥离标签 |
| `toName` | — | 收件人显示名 |

::: danger 只有这五个字段
**不支持**：附件、抄送（CC）、密送（BCC）、回复地址、自定义头。

这不是"还没做"，是刻意的：这些能力一旦加上，`Message` 就不再是纯标量结构，
没法 JSON 序列化进队列。

需要附件时——**发一个下载链接**，不要把文件塞进邮件。
:::

### 为什么只有标量

类的注释写明了：

> 只用标量属性：**进队列时要 JSON 序列化**，不能带对象或资源。

`Message` 为了能进队列而设计的，这是它能被 `Queue` 直接搬运的前提。

## SMTP 的限制

```php
'smtp' => [
    'driver' => 'smtp',
    'host' => env('MAIL_HOST', '127.0.0.1'),
    'port' => (int) env('MAIL_PORT', 1025),
    'username' => env('MAIL_USERNAME', ''),
    'password' => env('MAIL_PASSWORD', ''),
    'from' => env('MAIL_FROM', 'noreply@example.com'),
    'from_name' => env('MAIL_FROM_NAME', '夕梦'),
],
```

::: danger 不支持 STARTTLS / SMTPS
`SmtpMailer` 只实现：**EHLO、AUTH LOGIN、DATA**。

类的注释解释了为什么：

> 证书校验、SNI、协议降级这些细节手写极易出错，而"发邮件"这件事 90% 的场景
> 是走内网 relay 或第三方 API，与其提供一个半可靠的 TLS 实现，不如明确边界。

**所以连 465（SMTPS）和 587（STARTTLS）会失败**。
:::

### 怎么解决

| 场景 | 做法 |
| --- | --- |
| 内网有 SMTP relay | 直连 relay 的 25 端口（通常不加密） |
| 必须走公网 SMTP（如 Gmail） | **本机装一个 relay**（postfix / msmtp），由它做 TLS |
| 云厂商邮件服务 | 用它们的 HTTP API，自己写一个 Mailer（见下） |

本机 relay 方案实际长这样：

```ini
# .env
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1        # 本机的 postfix
MAIL_PORT=25
```

postfix 负责 `smtp.gmail.com:587` + TLS + 认证，你的代码只连 localhost。

**好处**：证书、协议、重试都交给成熟的 MTA，代码保持简单。

## 用队列

发邮件是**秒级操作**（SMTP 往返 1–3 秒），不该阻塞请求。

```php
// 接口里：只入队，几毫秒返回
$this->queue->push(new SendWelcomeMail($userId));

// Job 里：真正的发送
final class SendWelcomeMail implements Job
{
    public function __construct(private readonly int $userId)
    {
    }

    public function handle(): void
    {
        // handle() 没有参数，需要 MailManager 时：
        Application::getInstance()->mail()->send(new Message(...));
    }
}
```

实测数据：入队约 **50 μs**，而 SMTP 发送 **1–3 秒**——差 5 个数量级。

详见队列（组件章节，待补）。

## 测试

### 开发：用 `log` 驱动

```ini
MAIL_MAILER=log
```

邮件内容会写进 `storage/logs/`，不真发。

### 本地收邮件：mailpit

想看到真实邮件（尤其调 HTML 模板）时装一个：

```bash
brew install mailpit && mailpit
# 然后 http://localhost:8025 看收件箱
```

```ini
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
```

mailpit 不加密、不需要认证，和框架的 SMTP 实现正好匹配。

### 集成测试：自己写个假 SMTP

如果不想装 mailpit，可以起一个极简的 TCP 服务器接收并记录邮件——xi-blog 的回归脚本就这么做的：

```text
起一个监听 2525 的 socket，接受 EHLO / AUTH / DATA / QUIT
收到的 .eml 存到 /tmp/mails/
```

对框架的要求：`SmtpMailer` 的实现足够简单（纯文本协议），假服务器几十行就能写。

## 自定义 Mailer

```php
$this->mail->extend('api', new MyApiMailer());
```

实现 `MailerInterface`：

```php
interface MailerInterface
{
    public function send(Message $message): void;
}
```

云厂商的 HTTP API（SendGrid / 阿里云邮件推送）走这条路——比 SMTP 更可靠。

## 常见错误

### 邮件发出去了但对方没收到

按顺序排查：

| 检查 | 怎么看 |
| --- | --- |
| 用的哪个驱动 | `MAIL_MAILER` 是不是还是 `log` |
| SMTP 连上了吗 | 日志里有没有连接错误 |
| 被退信了吗 | 发件域的 SPF / DKIM 记录 |
| 进垃圾箱了吗 | 换个人工邮箱试试 |

**`log` 驱动不会真发**——这是最常见的"发了但没收到"。

### `SMTP 连接失败`

```text
MailException: SMTP 连接失败：Connection refused
```

1. `MAIL_HOST` / `MAIL_PORT` 对不对
2. 服务在跑吗（`telnet host port`）
3. 防火墙 / 安全组

### `认证失败`

`MAIL_USERNAME` / `MAIL_PASSWORD`。

注意：很多服务（Gmail、Outlook）需要**应用专用密码**，不是账号密码。

### 中文乱码

框架的正文用 base64 编码 + UTF-8，正常不会乱码。

如果乱码，检查 `subject` 是否自己做了编码处理——**不要自己编码**，框架会处理。

### 邮件进了垃圾箱

发件域要做三件事：

```text
1. SPF 记录（声明哪些 IP 可以代表你发信）
2. DKIM 签名（由 relay / MTA 做）
3. DMARC 策略
```

这三项都在**域名 DNS** 层面配置，和框架无关。

## 下一步

- **队列（组件章节，待补）** —— 异步发邮件
- **日志（组件章节，待补）** —— 邮件失败时的排查依据
- **[缓存](./cache)** —— 验证码这类短期数据
