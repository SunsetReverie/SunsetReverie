<?php

declare(strict_types=1);

namespace Tests\Integration;

use SunsetReverie\Database\Connection;
use SunsetReverie\Database\ConnectionManager;
use Tests\TestCase;

/**
 * PostgreSQL 方言集成测试。
 *
 * 只在本地有可用 PostgreSQL 时运行（连不上就跳过，不算失败）——
 * 因为 CI 环境未必装 PG，但开发者本地跑一遍能挡住"方言写错"这类问题。
 *
 * 背景：这三处方言问题（DATETIME / BOOLEAN DEFAULT 1 / 异常签名）
 * 都是首次真实连 PG 时才暴露的，SQLite 与 MySQL 测试全绿也发现不了。
 */
final class PostgresDialectTest extends TestCase
{
    /** @var string 本用例专属表名（避免与其他数据冲突） */
    private string $table;

    /** @var Connection|null 连接 */
    private ?Connection $db = null;

    /**
     * 建表并准备连接。
     *
     * @return void
     */
    protected function setUp(): void
    {
        if (!$this->postgresAvailable()) {
            return;
        }

        $this->table = 'sr_test_' . strtolower(bin2hex(random_bytes(4)));

        $manager = new ConnectionManager([
            'default' => 'pgsql',
            'connections' => [
                'pgsql' => [
                    'driver' => 'pgsql',
                    'host' => '127.0.0.1',
                    'port' => 5432,
                    'database' => 'xi_blog_pg',
                    'username' => 'postgres',
                    'password' => '',
                    'timeout' => 5,
                ],
            ],
        ]);

        $this->db = $manager->connection();
    }

    /**
     * 清理表。
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->db?->statement('DROP TABLE IF EXISTS ' . $this->table);
    }

    /**
     * 建表应支持 PG 方言（布尔默认值 / 自增主键 / 唯一约束）。
     *
     * @return void
     */
    public function testCreateTableWithPgDialect(): void
    {
        if ($this->db === null) {
            return;
        }

        $schema = new \SunsetReverie\Database\Schema\Schema($this->db);

        $schema->create($this->table, static function (\SunsetReverie\Database\Schema\Blueprint $table): void {
            $table->id();
            $table->string('name', 50);
            $table->boolean('is_active')->default(true);
            $table->decimal('price', 10, 2)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        // 能插入即说明 DDL 合法（Boolean DEFAULT 1 会在这里报错）
        $id = $this->db->table($this->table)->insertGetId([
            'name' => '夕梦',
            'is_active' => true,
            'price' => 19.99,
            'published_at' => '2026-10-30 12:00:00',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame(1, $id, 'insertGetId 应走 RETURNING 并返回 int');
    }

    /**
     * 类型映射：boolean 读成原生 bool，decimal 读成字符串（不丢精度）。
     *
     * @return void
     */
    public function testTypeMapping(): void
    {
        if ($this->db === null) {
            return;
        }

        $schema = new \SunsetReverie\Database\Schema\Schema($this->db);

        $schema->create($this->table, static function (\SunsetReverie\Database\Schema\Blueprint $table): void {
            $table->id();
            $table->boolean('is_active')->default(true);
            $table->decimal('price', 10, 2)->nullable();
            $table->timestamps();
        });

        $id = $this->db->table($this->table)->insertGetId([
            'is_active' => true,
            'price' => 19.99,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $row = $this->db->table($this->table)->findOrFail($id);

        $this->assertTrue($row['is_active'] === true, 'PG 的 BOOLEAN 应读成 PHP bool');
        $this->assertSame('19.99', $row['price'], 'DECIMAL 应读成字符串，避免浮点误差');

        $this->assertSame(1, $this->db->table($this->table)->where('is_active', true)->count(), '布尔条件查询应可用');
    }

    /**
     * 事务应可回滚。
     *
     * @return void
     */
    public function testTransactionRollback(): void
    {
        if ($this->db === null) {
            return;
        }

        $schema = new \SunsetReverie\Database\Schema\Schema($this->db);

        $schema->create($this->table, static function (\SunsetReverie\Database\Schema\Blueprint $table): void {
            $table->id();
            $table->string('name', 50);
            $table->timestamps();
        });

        try {
            $this->db->transaction(function (Connection $connection): void {
                $connection->table($this->table)->insert([
                    'name' => '应被回滚',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

                throw new \RuntimeException('故意失败');
            });
        } catch (\RuntimeException) {
            // 预期
        }

        $this->assertSame(0, $this->db->table($this->table)->count(), '异常后事务应完全回滚');
    }

    /**
     * PostgreSQL 是否可用。
     *
     * @return bool 可用返回 true
     */
    private function postgresAvailable(): bool
    {
        if (!extension_loaded('pdo_pgsql')) {
            return false;
        }

        try {
            new \PDO('pgsql:host=127.0.0.1;port=5432;dbname=xi_blog_pg', 'postgres', '', [
                \PDO::ATTR_TIMEOUT => 2,
            ]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
