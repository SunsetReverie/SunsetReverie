<?php

declare(strict_types=1);

namespace SunsetReverie\Queue\Driver;

use SunsetReverie\Database\Connection;

/**
 * 数据库队列驱动。
 *
 * 零外部依赖（不需要 Redis/RabbitMQ），代价是依赖数据库的并发能力。
 *
 * `pop()` 的原子性靠「令牌抢占」实现：
 *
 *   1. UPDATE ... SET reserved_token = {随机令牌} WHERE 可执行 ORDER BY id LIMIT 1
 *   2. SELECT ... WHERE reserved_token = {同一个令牌}
 *
 * 相比 `SELECT ... FOR UPDATE`，这样不需要长事务，
 * 且 MySQL / SQLite 都支持 UPDATE ... ORDER BY ... LIMIT。
 * PostgreSQL 不支持该语法，改走「子查询锁定 id」分支。
 *
 * 时间字段用字符串比较（`Y-m-d H:i:s`），三种数据库的字典序与时间序一致。
 */
final class DatabaseDriver implements QueueDriverInterface
{
    /** 抢占令牌有效期（秒）：超过此时长视为 worker 已崩溃，可被重新抢占 */
    private const LEASE_SECONDS = 300;

    /**
     * @param Connection $db 数据库连接
     * @param string $jobsTable 任务表
     * @param string $failedTable 死信表
     */
    public function __construct(
        private readonly Connection $db,
        private readonly string $jobsTable = 'jobs',
        private readonly string $failedTable = 'failed_jobs',
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function push(string $queue, array $payload, int $delaySeconds = 0): string
    {
        $now = time();

        $id = $this->db->table($this->jobsTable)->insertGetId([
            'queue' => $queue,
            'payload' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'available_at' => date('Y-m-d H:i:s', $now + max(0, $delaySeconds)),
            'reserved_token' => null,
            'created_at' => date('Y-m-d H:i:s', $now),
        ]);

        return (string) $id;
    }

    /**
     * {@inheritDoc}
     */
    public function pop(string $queue): ?array
    {
        $token = $this->uuid();
        $now = date('Y-m-d H:i:s');
        $stale = date('Y-m-d H:i:s', time() - self::LEASE_SECONDS);

        // 可执行条件：未抢占，或抢占已过期（worker 崩溃时任务不会永久卡住）
        $claimable = 'queue = ? AND available_at <= ? AND (reserved_token IS NULL OR reserved_at < ?)';

        $set = 'SET reserved_token = ?, reserved_at = ?, attempts = attempts + 1';

        // 三种数据库支持「带限制的 UPDATE」方式不同，必须分支
        $sql = match ($this->db->driver()) {
            // PostgreSQL：用子查询锁 id，SKIP LOCKED 让并发 worker 各取各的
            'pgsql' => sprintf(
                'UPDATE %1$s %2$s
                 WHERE id = (SELECT id FROM %1$s WHERE %3$s ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED)',
                $this->jobsTable,
                $set,
                $claimable
            ),
            // SQLite：默认编译选项不含 SQLITE_ENABLE_UPDATE_DELETE_LIMIT，
            // 不支持 UPDATE ... ORDER BY，只能走派生表子查询
            // （直接子查询同表会报 "no such column"，包一层派生表才合法）
            'sqlite' => sprintf(
                'UPDATE %1$s %2$s
                 WHERE id = (SELECT id FROM (SELECT id FROM %1$s WHERE %3$s ORDER BY id LIMIT 1) AS pick)',
                $this->jobsTable,
                $set,
                $claimable
            ),
            // MySQL：原生支持 UPDATE ... ORDER BY ... LIMIT
            default => sprintf(
                'UPDATE %1$s %2$s WHERE %3$s ORDER BY id LIMIT 1',
                $this->jobsTable,
                $set,
                $claimable
            ),
        };

        $affected = $this->db->affect($sql, [$token, $now, $queue, $now, $stale]);

        if ($affected === 0) {
            return null;
        }

        $row = $this->db->table($this->jobsTable)->where('reserved_token', $token)->first();

        if ($row === null) {
            return null;
        }

        $payload = json_decode((string) $row['payload'], true);

        if (!is_array($payload) || !isset($payload['class'])) {
            // 载荷损坏：直接进死信，避免反复重试
            $this->fail((string) $row['id'], '任务载荷无法解析', $queue, []);

            return null;
        }

        return [
            'id' => (string) $row['id'],
            'payload' => ['class' => (string) $payload['class'], 'data' => (array) ($payload['data'] ?? [])],
            'attempts' => (int) $row['attempts'],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function release(string $id, int $delaySeconds): void
    {
        $this->db->affect(
            sprintf('UPDATE %s SET reserved_token = NULL, reserved_at = NULL, available_at = ? WHERE id = ?', $this->jobsTable),
            [date('Y-m-d H:i:s', time() + max(0, $delaySeconds)), $id]
        );
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $id): void
    {
        $this->db->table($this->jobsTable)->where('id', $id)->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function fail(string $id, string $reason, string $queue, array $payload): void
    {
        $now = date('Y-m-d H:i:s');

        // 先写死信再删任务：顺序反过来的话，删除成功后写死信失败就丢任务了
        $this->db->table($this->failedTable)->insert([
            'queue' => $queue,
            'payload' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE),
            'exception' => mb_substr($reason, 0, 4000),
            'failed_at' => $now,
        ]);

        $this->delete($id);
    }

    /**
     * {@inheritDoc}
     */
    public function size(string $queue): int
    {
        return $this->db->table($this->jobsTable)->where('queue', $queue)->count();
    }

    /**
     * {@inheritDoc}
     */
    public function clear(string $queue): int
    {
        return $this->db->table($this->jobsTable)->where('queue', $queue)->delete();
    }

    /**
     * 死信列表。
     *
     * @param int $limit 条数
     * @return list<array<string, mixed>> 死信
     */
    public function failed(int $limit = 20): array
    {
        return $this->db->table($this->failedTable)->orderBy('failed_at', 'desc')->limit($limit)->get();
    }

    /**
     * 从死信重新入队。
     *
     * @param string $id 死信 ID
     * @return bool 是否成功
     */
    public function retryFailed(string $id): bool
    {
        $row = $this->db->table($this->failedTable)->where('id', $id)->first();

        if ($row === null) {
            return false;
        }

        $payload = json_decode((string) $row['payload'], true);

        if (!is_array($payload) || !isset($payload['class'])) {
            return false;
        }

        $this->push((string) $row['queue'], [
            'class' => (string) $payload['class'],
            'data' => (array) ($payload['data'] ?? []),
        ]);

        $this->db->table($this->failedTable)->where('id', $id)->delete();

        return true;
    }

    /**
     * 生成 UUID v4。
     *
     * @return string UUID
     */
    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
