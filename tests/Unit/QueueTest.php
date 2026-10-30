<?php

declare(strict_types=1);

namespace Tests\Unit;

use SunsetReverie\Database\Connection;
use SunsetReverie\Database\ConnectionManager;
use SunsetReverie\Queue\Driver\DatabaseDriver;
use SunsetReverie\Queue\Job;
use SunsetReverie\Queue\Worker;
use Tests\TestCase;
use Throwable;

/**
 * 队列核心行为测试。
 *
 * 重点在两个容易被忽略的地方：
 * 1. `pop()` 的**原子性**——并发 worker 不能拿到同一任务
 * 2. **重试与死信**——失败任务既不能丢，也不能无限重试
 */
final class QueueTest extends TestCase
{
    /** @var Connection 内存库 */
    private Connection $db;

    /** @var DatabaseDriver 驱动 */
    private DatabaseDriver $driver;

    /**
     * 建表。
     *
     * @return void
     */
    protected function setUp(): void
    {
        $manager = new ConnectionManager([
            'default' => 'sqlite',
            'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]);

        $this->db = $manager->connection();

        $this->db->affect(
            'CREATE TABLE jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                queue TEXT NOT NULL,
                payload TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                available_at TEXT NOT NULL,
                reserved_token TEXT,
                reserved_at TEXT,
                created_at TEXT NOT NULL
            )'
        );

        $this->db->affect(
            'CREATE TABLE failed_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                queue TEXT NOT NULL,
                payload TEXT NOT NULL,
                exception TEXT NOT NULL,
                failed_at TEXT NOT NULL
            )'
        );

        $this->driver = new DatabaseDriver($this->db);
    }

    /**
     * 入队后 size 应增加，pop 后应减少。
     *
     * @return void
     */
    public function testPushAndPop(): void
    {
        $this->driver->push('default', ['class' => CountingJob::class, 'data' => ['tag' => 'a']]);

        $this->assertSame(1, $this->driver->size('default'));

        $job = $this->driver->pop('default');

        $this->assertTrue($job !== null);
        $this->assertSame(CountingJob::class, $job['payload']['class']);
        $this->assertSame('a', $job['payload']['data']['tag']);
        $this->assertSame(1, $job['attempts'], 'pop 时应累加尝试次数');
    }

    /**
     * 已被抢占的任务不能被第二个 worker 拿到。
     *
     * @return void
     */
    public function testPopIsExclusive(): void
    {
        $this->driver->push('default', ['class' => CountingJob::class, 'data' => []]);

        $first = $this->driver->pop('default');
        $second = $this->driver->pop('default');

        $this->assertTrue($first !== null, '第一个 worker 应拿到任务');
        $this->assertNull($second, '第二个 worker 不应拿到同一个任务');
    }

    /**
     * 延迟任务在到期前不可取。
     *
     * @return void
     */
    public function testDelayedJobIsNotAvailable(): void
    {
        $this->driver->push('default', ['class' => CountingJob::class, 'data' => []], 60);

        $this->assertSame(1, $this->driver->size('default'), '延迟任务仍在队列里');
        $this->assertNull($this->driver->pop('default'), '未到期不应被取走');
    }

    /**
     * 任务成功后被删除。
     *
     * @return void
     */
    public function testWorkerDeletesSucceededJob(): void
    {
        CountingJob::$runs = 0;
        $this->driver->push('default', ['class' => CountingJob::class, 'data' => []]);

        $worker = new Worker($this->driver, 'default');

        $this->assertSame(1, count($worker->workUntilEmpty()));
        $this->assertSame(1, CountingJob::$runs);
        $this->assertSame(0, $this->driver->size('default'), '成功的任务应被删除');
    }

    /**
     * 失败任务应放回队列并延迟重试。
     *
     * @return void
     */
    public function testFailedJobIsReleased(): void
    {
        $this->driver->push('default', ['class' => FailingJob::class, 'data' => ['tries' => 3]]);

        $worker = new Worker($this->driver, 'default');

        // 只处理一次：workUntilEmpty 会立刻重试同一个任务（backoff=0），
        // 断言「放回队列」必须在单次处理之后检查
        $result = $worker->processOne();

        $this->assertTrue($result !== null);
        $this->assertSame(1, $this->driver->size('default'), '失败后应回到队列等待重试');
        $this->assertSame(0, count($this->driver->failed()), '未耗尽尝试次数不应进死信');

        // 再确认它确实可以再次被取出（reserved_token 已清空）
        $this->assertTrue($this->driver->pop('default') !== null, '释放后的任务应可再次抢占');
    }

    /**
     * 尝试次数耗尽后进死信，并从队列移除。
     *
     * @return void
     */
    public function testExhaustedJobGoesToDeadLetter(): void
    {
        // tries=1：第一次失败即耗尽
        $this->driver->push('default', ['class' => OneShotFailingJob::class, 'data' => []]);

        $worker = new Worker($this->driver, 'default');
        $worker->workUntilEmpty();

        $this->assertSame(0, $this->driver->size('default'), '死信后应从队列移除');
        $this->assertSame(1, count($this->driver->failed()), '应写入死信表');

        $failed = $this->driver->failed()[0];
        $this->assertStringContains('OneShotFailingJob', (string) $failed['payload']);
        $this->assertStringContains('任务故意失败', (string) $failed['exception']);
    }

    /**
     * 死信可以重新入队。
     *
     * @return void
     */
    public function testRetryFailedJob(): void
    {
        $this->driver->push('default', ['class' => OneShotFailingJob::class, 'data' => []]);
        (new Worker($this->driver, 'default'))->workUntilEmpty();

        $failed = $this->driver->failed()[0];

        $this->assertTrue($this->driver->retryFailed((string) $failed['id']));
        $this->assertSame(1, $this->driver->size('default'), '重试应重新入队');
        $this->assertSame(0, count($this->driver->failed()), '重试后死信应删除');
    }

    /**
     * 清空队列不影响死信。
     *
     * @return void
     */
    public function testClearDoesNotTouchDeadLetter(): void
    {
        $this->driver->push('default', ['class' => OneShotFailingJob::class, 'data' => []]);
        (new Worker($this->driver, 'default'))->workUntilEmpty();

        $this->driver->push('default', ['class' => CountingJob::class, 'data' => []]);
        $this->assertSame(1, $this->driver->clear('default'));

        $this->assertSame(0, $this->driver->size('default'));
        $this->assertSame(1, count($this->driver->failed()), '清空队列不应删除死信');
    }
}

/**
 * 计数任务（成功）。
 */
final class CountingJob implements Job
{
    /** @var int 执行次数 */
    public static int $runs = 0;

    public function handle(): void
    {
        self::$runs++;
    }

    public function failed(Throwable $exception): void
    {
    }

    public function tries(): int
    {
        return 3;
    }

    public function backoff(int $attempt): int
    {
        return 0;
    }
}

/**
 * 失败任务（可指定尝试次数）。
 */
final class FailingJob implements Job
{
    public function __construct(private readonly int $tries = 3)
    {
    }

    public function handle(): void
    {
        throw new \RuntimeException('任务故意失败');
    }

    public function failed(Throwable $exception): void
    {
    }

    public function tries(): int
    {
        return $this->tries;
    }

    public function backoff(int $attempt): int
    {
        // 退避 0 秒，避免测试等待
        return 0;
    }
}

/**
 * 只尝试一次就放弃的任务。
 */
final class OneShotFailingJob implements Job
{
    public function handle(): void
    {
        throw new \RuntimeException('任务故意失败');
    }

    public function failed(Throwable $exception): void
    {
    }

    public function tries(): int
    {
        return 1;
    }

    public function backoff(int $attempt): int
    {
        return 0;
    }
}
