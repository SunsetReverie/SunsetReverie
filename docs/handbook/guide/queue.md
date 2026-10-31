# 队列

```php
use SunsetReverie\Queue\QueueManager;
```

::: warning 没有 `Queue` 门面
和 `Mail` / `Upload` / `Image` 一样，用构造函数注入：

```php
final class NotifyService
{
    public function __construct(private readonly QueueManager $queue)
    {
    }
}
```

下面的示例用 `$this->queue` 表示注入进来的实例。
:::

## 两个驱动

| 驱动 | 行为 | 适合 |
| --- | --- | --- |
| `database` | 写 `jobs` 表，由 worker 消费 | **生产** |
| `sync` | 立即同步执行，不入库 | **测试**（或确实要同步的业务） |

```php
// config/queue.php
'driver' => env('QUEUE_DRIVER', 'database'),
'default_queue' => env('QUEUE_NAME', 'default'),

'drivers' => [
    'database' => [
        'connection' => env('QUEUE_DB_CONNECTION'),   // null = 默认连接
        'jobs_table' => 'jobs',
        'failed_table' => 'failed_jobs',
    ],
    'sync' => [],
],
```

::: tip `sync` 驱动的实际用途
测试时不想起 worker，又不想让代码分叉：

```ini
# .env.testing
QUEUE_DRIVER=sync
```

入队变成同步执行，`push()` 返回时任务已经跑完。断言的时序因此变得确定。
:::

## 定义一个任务

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\User\UserService;
use SunsetReverie\Mail\MailManager;
use SunsetReverie\Mail\Message;
use SunsetReverie\Queue\Job;
use SunsetReverie\Foundation\Application;
use Throwable;

final class SendWelcomeMail implements Job
{
    public function __construct(
        private readonly int $userId,
        private readonly string $email,
    ) {
    }

    /**
     * 执行任务。
     *
     * @return void
     */
    public function handle(): void
    {
        $app = Application::getInstance();

        $app->mail()->send(new Message(
            to: $this->email,
            subject: '欢迎加入',
            html: '<h1>你好</h1>',
        ));
    }

    /**
     * 最终失败时的回调（进死信之后调用）。
     *
     * @param Throwable $exception 最后一次异常
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        // 落审计表、发告警。这里再抛异常会被吞掉（不影响死信记录）
    }

    /**
     * 最多尝试几次。
     *
     * @return int 次数
     */
    public function tries(): int
    {
        return 3;
    }

    /**
     * 第 N 次失败后等多久再重试。
     *
     * @param int $attempt 已失败的次数（从 1 开始）
     * @return int 秒
     */
    public function backoff(int $attempt): int
    {
        // 指数退避：10 → 30 → 90 秒
        return 10 * (3 ** ($attempt - 1));
    }
}
```

### `Job` 接口的四个方法

| 方法 | 必须实现 | 说明 |
| --- | --- | --- |
| `handle(): void` | ✅ | **无参数**——需要什么从容器或 `Application` 拿 |
| `failed(Throwable $exception): void` | ✅ | 进死信后调用，用来告警/落审计 |
| `tries(): int` | ✅ | 最多尝试几次（含首次） |
| `backoff(int $attempt): int` | ✅ | 第 `$attempt` 次失败后等几秒 |

::: danger `handle()` 没有参数
不是 `handle(Container $container)`——传参给不了，因为 worker 只调 `$job->handle()`。

需要容器里的东西时：

```php
// 方式 1：Application 访问器
Application::getInstance()->mail()->send(...);

// 方式 2：构造函数注入（任务对象本身由容器解析）
public function __construct(
    private readonly MailManager $mail,
    private readonly int $userId,
) {}
```

方式 2 更干净，但要求**标量参数能在入队时带上**（构造函数参数会被序列化进 payload）。
:::

### 任务类不能带资源

payload 是 `json_encode` 的结果，所以构造函数参数只能是标量、数组、简单对象。

```php
// ❌ 文件句柄不能序列化
public function __construct(private $handle) {}

// ✅ 传路径，handle() 里再打开
public function __construct(private readonly string $path) {}
```

## 入队

```php
$this->queue->push(new SendWelcomeMail($userId, $email));
```

三个参数：

```php
push(Job $job, int $delaySeconds = 0, ?string $queue = null)
```

```php
// 延迟 60 秒
$this->queue->push(new SendWelcomeMail(...), 60);

// 指定队列（不同队列可以分配给不同的 worker）
$this->queue->push(new ExportJob(...), 0, 'export');
```

**`push()` 是毫秒级操作**（一次 INSERT）。实测：

| 操作 | 耗时 |
| --- | --- |
| `push()`（入队） | 约 50 μs |
| SMTP 发送邮件 | 1–3 秒 |

差 5 个数量级。这就是「发邮件要入队」的量化理由。

## 消费

```bash
php bin/sr queue:work              # 常驻，直到手动停止
php bin/sr queue:work --once       # 处理到队列空就退出（调试用）
php bin/sr queue:work --max=100    # 处理 100 个后退出
php bin/sr queue:size              # 队列长度
php bin/sr queue:clear             # 清空（不含死信）
```

对应 `Worker` 的三个方法：

| 方法 | 用途 |
| --- | --- |
| `work(int $maxJobs = 0)` | 常驻循环（0 = 无限） |
| `processOne()` | 处理**一个** |
| `workUntilEmpty(int $maxJobs = 0)` | 处理**到空**就返回 |

命令行对应关系：

| 命令 | 调的方法 |
| --- | --- |
| `queue:work` | `work()`——常驻，Ctrl+C 退出 |
| `queue:work --once` | `workUntilEmpty()`——**处理完队列里所有任务再退出** |
| `queue:work --max=N` | 两种模式下都限制最多处理 N 个 |

## 重试与退避

框架的逻辑：

```php
$maxTries = $instance->tries();

if ($attempts >= $maxTries) {
    $this->driver->fail($job['id'], ..., $job['payload']);   // 进死信
    $instance->failed($e);                                    // 通知
} else {
    $delay = $instance->backoff($attempts);
    $this->driver->release($job['id'], $delay);               // 稍后重试
}
```

**退避策略完全由任务决定**——框架只调 `backoff($attempt)`。

### 推荐的退避实现

```php
public function backoff(int $attempt): int
{
    // 指数退避 + 抖动
    $base = 10 * (2 ** ($attempt - 1));      // 10, 20, 40, 80...
    return $base + random_int(0, 10);         // 加随机抖动，避免同时重试
}
```

**为什么要抖动**：100 个任务因为同一个原因同时失败，如果不加抖动，
它们会在同一秒集中重试——把刚恢复的下游又打挂。

### `tries()` 设多少

| 任务性质 | 建议 |
| --- | --- |
| 发邮件、推送通知 | 3 次（重试多了意义不大） |
| 数据同步、第三方 API | 5 次（对端可能临时抖动） |
| 本地计算（必然确定性） | 1 次（失败就是 bug，重试没用） |

## 死信

超过 `tries()` 的任务进 `failed_jobs` 表：

```bash
php bin/sr queue:failed           # 查看
php bin/sr queue:retry <id>       # 单条重新入队
php bin/sr queue:retry --all      # 全部重新入队
```

表里记录了 `exception`（`类名: 消息`），排查时先看这个。

::: warning 死信表会一直增长
框架不自动清理。生产环境建议定期归档：

```sql
DELETE FROM failed_jobs WHERE failed_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

或者分析完后手工清空。**不要无条件清空**——死信是排查问题的唯一线索。
:::

## 令牌抢占（并发安全）

多个 worker 同时跑时，**同一个任务不能被两个 worker 取到**。

框架的做法不是 `SELECT ... FOR UPDATE`，而是「令牌抢占」：

```sql
-- 1. 原子地占一个任务，写入随机令牌
UPDATE jobs
   SET reserved_token = '{随机令牌}', reserved_at = NOW()
 WHERE available_at <= NOW() AND (reserved_token IS NULL OR reserved_at < 过期时间)
 ORDER BY id
 LIMIT 1;

-- 2. 把刚占到的那个读出来
SELECT * FROM jobs WHERE reserved_token = '{同一个令牌}';
```

**为什么不用 `FOR UPDATE`**：那需要把事务保持在「select 到 delete」之间，
而 worker 执行任务可能耗时几秒——长事务会拖住整个表。

### 三库的差异

| 数据库 | 抢占 SQL |
| --- | --- |
| MySQL / SQLite | `UPDATE ... ORDER BY id LIMIT 1`（原生支持） |
| PostgreSQL | **不支持** `UPDATE ... ORDER BY ... LIMIT`，改走「子查询锁定 id」 |

时间字段用字符串比较（`Y-m-d H:i:s`），三种库的字典序与时间序一致。

### 租约：worker 崩溃怎么办

```php
private const LEASE_SECONDS = 300;   // 5 分钟
```

任务被抢占后 `reserved_token` 会保留 **300 秒**。超时说明 worker 已经崩了，
任务可以被重新抢占。

**所以单个任务的执行时间不能超过 5 分钟**——否则会被另一个 worker 重复执行。

| 任务耗时 | 处理 |
| --- | --- |
| < 5 分钟 | 正常 |
| > 5 分钟 | 拆成多个小任务，或者增大 `LEASE_SECONDS` |

## 部署

worker 是**常驻进程**，用 supervisor 管：

```ini
[program:queue-worker]
command=php /path/to/project/bin/sr queue:work
directory=/path/to/project
autostart=true
autorestart=true
user=www-data
numprocs=2
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=/var/log/queue-worker.log
```

### 改代码后必须重启 worker

```bash
supervisorctl restart queue-worker
```

::: warning 没有 `queue:restart` 命令
Laravel 有 `queue:restart`（发信号让 worker 自己退出重启），
这个框架**没有**——40 个命令里没有它。

所以改代码后只有两条路：

| worker 的启动方式 | 重启方法 |
| --- | --- |
| supervisor 托管 | `supervisorctl restart queue-worker` |
| 自己 `nohup` 起的 | `kill` 后重新启动 |
:::


**worker 是常驻 PHP 进程，不会自动加载新代码。** 改完任务类不重启，
跑的还是旧代码——症状是「明明改了却没生效」，和 opcache 那个坑类似。

::: warning 没有 `queue:restart` 命令
当前 40 个命令里没有这个。改代码后手工 reload：

```bash
killall -USR2 php-fpm      # 对 FPM 生效
supervisorctl restart queue-worker
```

如果 worker 跑在 FPM 之外的 CLI 进程里，只有后者管用。
:::

## 常见错误

### 任务入队了但一直不执行

| 检查 | 命令 |
| --- | --- |
| worker 在跑吗 | `ps aux \| grep queue:work` |
| 队列里有吗 | `php bin/sr queue:size` |
| `available_at` 是不是未来 | `SELECT available_at FROM jobs` |
| 队列名对得上吗 | `push()` 的第三个参数 vs worker 监听的队列 |

### 任务重复执行

| 原因 | 处理 |
| --- | --- |
| 任务耗时 > 300 秒（租约过期） | 拆分任务 |
| worker 崩了但任务已经开始 | 任务要**幂等**（重复执行不出错） |

**幂等是队列任务的基本要求**：网络抖动、进程被杀、租约超时都可能导致重跑。

```php
public function handle(): void
{
    // 先查是否已处理过
    if (DB::table('orders')->where('id', $this->orderId)->where('notified', 1)->exists()) {
        return;
    }

    // ... 处理 ...

    DB::table('orders')->where('id', $this->orderId)->update(['notified' => 1]);
}
```

### `failed_jobs` 里全是同一个错误

说明是系统性问题（下游挂了、配置错了），不是偶发。

**不要直接 `queue:retry --all`**——会立刻再失败一轮，把下游打得更惨。
先修根因。

### 延迟任务不生效

```php
$this->queue->push($job, 60);     // ✅ 延迟 60 秒
$this->queue->push($job, -10);    // → 夹到 0（立即执行）
```

`available_at` 用 `date('Y-m-d H:i:s', $now + max(0, $delaySeconds))` 计算。

## 下一步

- **[邮件](./mail)** —— 队列最典型的用法
- **[日志](./log)** —— 任务失败后的排查依据
- **[缓存](./cache)** —— 幂等检查可以缓存加速
