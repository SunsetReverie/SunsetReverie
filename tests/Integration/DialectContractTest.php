<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use SunsetReverie\Database\Connection;
use SunsetReverie\Database\ConnectionManager;
use SunsetReverie\Database\Schema\Blueprint;
use SunsetReverie\Database\Schema\Schema;
use Tests\TestCase;

/**
 * 数据库方言契约测试：同一套断言在 SQLite / MySQL / PostgreSQL 上跑。
 *
 * 为什么必须有它：测试套件默认跑 SQLite，而 MySQL / PostgreSQL 的分支
 * （`RETURNING`、`AUTO_INCREMENT`、`TINYINT(1)`、`BOOLEAN DEFAULT`）只有在真实
 * 数据库上才会执行。之前的做法是"我手工在临时项目里验过"——
 * 手工验过一次不等于可持续，下次改方言代码没人能发现回归。
 *
 * 用环境变量选择目标库（CI 的 matrix job 分别注入）：
 *
 *   SR_TEST_DB        sqlite | mysql | pgsql（不设则整个文件跳过）
 *   SR_TEST_DB_HOST   默认 127.0.0.1
 *   SR_TEST_DB_PORT   默认 3306 / 5432
 *   SR_TEST_DB_NAME   库名
 *   SR_TEST_DB_USER   用户名
 *   SR_TEST_DB_PASS   密码
 *
 * 本地跑 PostgreSQL：
 *   SR_TEST_DB=pgsql SR_TEST_DB_NAME=xi_blog_pg SR_TEST_DB_USER=postgres \
 *     php tests/run.php --filter=DialectContract
 */
final class DialectContractTest extends TestCase
{
    /** @var Connection|null 连接（环境不可用时为 null） */
    private ?Connection $db = null;

    /** @var string 本次测试专用的表名 */
    private string $table = '';

    /**
     * 按环境变量建连接。
     *
     * @return void
     */
    protected function setUp(): void
    {
        $config = self::connectionConfig();

        if ($config === null) {
            return;
        }

        $driver = (string) $config['driver'];

        if (!in_array($driver, PDO::getAvailableDrivers(), true)) {
            return;
        }

        $this->table = 'sr_test_' . strtolower(bin2hex(random_bytes(4)));

        $manager = new ConnectionManager([
            'default' => 'target',
            'connections' => ['target' => $config],
        ]);

        try {
            $this->db = $manager->connection();
            $this->db->scalar('SELECT 1');
        } catch (\Throwable) {
            $this->db = null;
        }
    }

    /**
     * 清理。
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->db !== null && $this->table !== '') {
            $this->db->affect('DROP TABLE IF EXISTS ' . $this->table);
        }
    }

    /**
     * 建表：三类列（自增 / 布尔默认值 / DECIMAL）在各库都要合法。
     *
     * @return void
     */
    public function testCreateTable(): void
    {
        if ($this->skipIfUnavailable()) {
            return;
        }

        /** @var Connection $db */
        $db = $this->db;

        (new Schema($db))->create($this->table, static function (Blueprint $table): void {
            $table->id();
            $table->string('name', 50);
            $table->boolean('is_active')->default(true);
            $table->decimal('price', 10, 2)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique('name');
        });

        // 能插入即说明 DDL 合法（`BOOLEAN DEFAULT 1` 在 PG 会直接报错）
        $id = $db->table($this->table)->insertGetId([
            'name' => '夕梦',
            'is_active' => true,
            'price' => 19.99,
            'published_at' => '2026-10-30 12:00:00',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame(1, $id, 'insertGetId 必须返回 int（PG 走 RETURNING，MySQL 走 lastInsertId）');
    }

    /**
     * 类型映射：boolean 读成 bool，decimal 读成字符串（不丢精度）。
     *
     * @return void
     */
    public function testTypeMapping(): void
    {
        if ($this->skipIfUnavailable()) {
            return;
        }

        /** @var Connection $db */
        $db = $this->db;

        (new Schema($db))->create($this->table, static function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_active')->default(true);
            $table->decimal('price', 10, 2)->nullable();
            $table->timestamps();
        });

        $db->table($this->table)->insert([
            'is_active' => true,
            'price' => 19.99,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $row = $db->table($this->table)->first();
        $this->assertTrue($row !== null);

        // decimal 必须是字符串：浮点会丢精度（19.99 存成 19.989999...）
        $this->assertSame('19.99', (string) $row['price'], 'DECIMAL 应以字符串读出');

        // 布尔条件查询在三库语法不同，这里验证可用性
        $this->assertSame(1, $db->table($this->table)->where('is_active', true)->count());
    }

    /**
     * 事务回滚。
     *
     * @return void
     */
    public function testTransactionRollback(): void
    {
        if ($this->skipIfUnavailable()) {
            return;
        }

        /** @var Connection $db */
        $db = $this->db;

        (new Schema($db))->create($this->table, static function (Blueprint $table): void {
            $table->id();
            $table->string('name', 50);
            $table->timestamps();
        });

        $table = $this->table;

        try {
            $db->transaction(static function (Connection $c) use ($table): void {
                $c->table($table)->insert([
                    'name' => 'should-rollback',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

                throw new \RuntimeException('故意失败');
            });
        } catch (\Throwable) {
            // 预期：插入被回滚
        }

        $db->transaction(static function (Connection $c) use ($table): void {
            $c->table($table)->insert([
                'name' => 'committed',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        });

        $this->assertSame(1, $db->table($this->table)->count(), '提交后应有 1 行');
    }

    /**
     * 批量插入 + 聚合（覆盖 insertMany 与 SUM 的跨库行为）。
     *
     * @return void
     */
    public function testBulkInsertAndAggregate(): void
    {
        if ($this->skipIfUnavailable()) {
            return;
        }

        /** @var Connection $db */
        $db = $this->db;

        (new Schema($db))->create($this->table, static function (Blueprint $table): void {
            $table->id();
            $table->integer('amount')->default(0);
            $table->timestamps();
        });

        $now = date('Y-m-d H:i:s');
        $rows = [];

        foreach ([100, 200, 300] as $amount) {
            $rows[] = ['amount' => $amount, 'created_at' => $now, 'updated_at' => $now];
        }

        $this->assertSame(3, $db->table($this->table)->insertMany($rows), '批量插入应写入 3 行');

        // PDO 对 SUM 一律返回字符串，Builder::sum() 必须转成数值
        $this->assertSame(600, $db->table($this->table)->sum('amount'));

        $grouped = $db->table($this->table)
            ->selectRaw('amount', 'COUNT(*) AS total')
            ->groupBy('amount')
            ->orderBy('amount', 'asc')
            ->get();

        $this->assertSame(3, count($grouped), 'GROUP BY 应分出 3 组');
    }

    /**
     * 环境不可用时跳过并说明原因。
     *
     * 不静默 return：CI 里如果莫名全跳过，会让人以为"测过了"。
     *
     * @return bool 是否跳过
     */
    private function skipIfUnavailable(): bool
    {
        if ($this->db !== null) {
            return false;
        }

        $target = getenv('SR_TEST_DB');

        if ($target === false || $target === '') {
            fwrite(
                STDERR,
                "  [skip] DialectContractTest：未设置 SR_TEST_DB（CI 里应注入 mysql / pgsql）\n"
            );
        } else {
            fwrite(
                STDERR,
                sprintf("  [skip] DialectContractTest：SR_TEST_DB=%s 但连接失败\n", $target)
            );
        }

        return true;
    }

    /**
     * 从环境变量组装连接配置。
     *
     * @return array<string, mixed>|null 配置（未设置时为 null）
     */
    private static function connectionConfig(): ?array
    {
        $driver = getenv('SR_TEST_DB');

        if ($driver === false || $driver === '') {
            return null;
        }

        if ($driver === 'sqlite') {
            return ['driver' => 'sqlite', 'database' => ':memory:'];
        }

        $defaultPort = $driver === 'mysql' ? '3306' : '5432';

        return [
            'driver' => $driver,
            'host' => (string) (getenv('SR_TEST_DB_HOST') ?: '127.0.0.1'),
            'port' => (int) (getenv('SR_TEST_DB_PORT') ?: $defaultPort),
            'database' => (string) (getenv('SR_TEST_DB_NAME') ?: 'sr_test'),
            'username' => (string) (getenv('SR_TEST_DB_USER') ?: ($driver === 'mysql' ? 'root' : 'postgres')),
            'password' => (string) (getenv('SR_TEST_DB_PASS') ?: ''),
            'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
            'timeout' => 3,
        ];
    }
}
