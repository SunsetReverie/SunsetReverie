<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use SunsetReverie\Database\Connection;
use SunsetReverie\Database\ConnectionManager;
use Tests\TestCase;

/**
 * 数据库连接与查询构造器测试（SQLite 内存库，无需外部服务）。
 */
final class DatabaseTest extends TestCase
{
    /** @var Connection 待测连接 */
    private Connection $db;

    /**
     * 建立内存库并建表。
     *
     * @return void
     */
    protected function setUp(): void
    {
        $manager = new ConnectionManager([
            'default' => 'sqlite',
            'connections' => [
                'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        ]);

        $this->db = $manager->connection();

        $this->db->affect(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT,
                status TEXT NOT NULL DEFAULT "active",
                deleted_at TEXT
            )'
        );

        $this->db->table('users')->insert(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
        $this->db->table('users')->insert(['name' => 'Bob', 'email' => 'bob@example.com', 'status' => 'banned']);
        $this->db->table('users')->insert(['name' => 'Carol', 'email' => null, 'status' => 'active']);
    }

    /**
     * 应能读取全部行。
     *
     * @return void
     */
    public function testSelectsAllRows(): void
    {
        $rows = $this->db->table('users')->orderBy('id')->get();

        $this->assertSame(3, count($rows));
        $this->assertSame('Alice', $rows[0]['name']);
        $this->assertSame('Carol', $rows[2]['name']);
    }

    /**
     * 应能按条件筛选。
     *
     * @return void
     */
    public function testFiltersWithWhere(): void
    {
        $active = $this->db->table('users')->where('status', 'active')->orderBy('id')->get();

        $this->assertSame(2, count($active));
        $this->assertSame('Alice', $active[0]['name']);
    }

    /**
     * 应支持 whereIn 与 whereNull。
     *
     * @return void
     */
    public function testSupportsInAndNullConditions(): void
    {
        $banned = $this->db->table('users')->whereIn('status', ['banned'])->get();
        $this->assertSame(1, count($banned));
        $this->assertSame('Bob', $banned[0]['name']);

        $withoutEmail = $this->db->table('users')->whereNull('email')->get();
        $this->assertSame(1, count($withoutEmail));
        $this->assertSame('Carol', $withoutEmail[0]['name']);
    }

    /**
     * first / value / count / exists 应工作。
     *
     * @return void
     */
    public function testFirstValueCountExists(): void
    {
        $first = $this->db->table('users')->orderBy('id', 'desc')->first();

        $this->assertSame('Carol', $first['name']);
        $this->assertSame('Carol', $this->db->table('users')->orderBy('id', 'desc')->value('name'));
        $this->assertSame(3, $this->db->table('users')->count());
        $this->assertTrue($this->db->table('users')->where('name', 'Alice')->exists());
        $this->assertFalse($this->db->table('users')->where('name', 'Nobody')->exists());
    }

    /**
     * limit / offset 应生效。
     *
     * @return void
     */
    public function testLimitAndOffset(): void
    {
        $rows = $this->db->table('users')->orderBy('id')->limit(1)->offset(1)->get();

        $this->assertSame(1, count($rows));
        $this->assertSame('Bob', $rows[0]['name']);
    }

    /**
     * insertGetId 应返回自增主键。
     *
     * @return void
     */
    public function testInsertReturnsId(): void
    {
        $id = $this->db->table('users')->insertGetId(['name' => 'Dave', 'email' => 'dave@example.com']);

        $this->assertSame('4', (string) $id);
        $this->assertSame(4, $this->db->table('users')->count());
    }

    /**
     * update 应只影响匹配行。
     *
     * @return void
     */
    public function testUpdatesMatchingRows(): void
    {
        $affected = $this->db->table('users')->where('name', 'Bob')->update(['status' => 'active']);

        $this->assertSame(1, $affected);
        $this->assertSame('active', $this->db->table('users')->where('name', 'Bob')->value('status'));
    }

    /**
     * delete 应只影响匹配行。
     *
     * @return void
     */
    public function testDeletesMatchingRows(): void
    {
        $affected = $this->db->table('users')->where('status', 'banned')->delete();

        $this->assertSame(1, $affected);
        $this->assertSame(2, $this->db->table('users')->count());
    }

    /**
     * 无 where 的更新与删除必须被拒绝。
     *
     * @return void
     */
    public function testRejectsUpdateOrDeleteWithoutWhere(): void
    {
        $this->assertThrows(
            InvalidArgumentException::class,
            fn () => $this->db->table('users')->update(['status' => 'x'])
        );

        $this->assertThrows(
            InvalidArgumentException::class,
            fn () => $this->db->table('users')->delete()
        );
    }

    /**
     * 非法标识符必须被拒绝（防注入）。
     *
     * @return void
     */
    public function testRejectsIllegalIdentifier(): void
    {
        $this->assertThrows(
            InvalidArgumentException::class,
            fn () => $this->db->table('users; DROP TABLE users')
        );

        $this->assertThrows(
            InvalidArgumentException::class,
            fn () => $this->db->table('users')->where('name OR 1=1', 'x')
        );
    }

    /**
     * 值中的特殊字符应被安全绑定。
     *
     * @return void
     */
    public function testBindsValuesSafely(): void
    {
        $this->db->table('users')->insert(['name' => "O'Reilly", 'email' => "x' OR '1'='1"]);

        $matched = $this->db->table('users')->where('name', "O'Reilly")->get();

        $this->assertSame(1, count($matched));
        $this->assertSame("x' OR '1'='1", $matched[0]['email']);
    }

    /**
     * 事务异常时应回滚。
     *
     * @return void
     */
    public function testTransactionRollsBackOnException(): void
    {
        try {
            $this->db->transaction(function (Connection $db): void {
                $db->table('users')->insert(['name' => 'Temp']);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // 预期异常
        }

        $this->assertSame(3, $this->db->table('users')->count());
    }

    /**
     * 事务正常时应提交。
     *
     * @return void
     */
    public function testTransactionCommits(): void
    {
        $this->db->transaction(function (Connection $db): void {
            $db->table('users')->insert(['name' => 'Temp']);
        });

        $this->assertSame(4, $this->db->table('users')->count());
    }

    /**
     * 嵌套事务应使用保存点。
     *
     * @return void
     */
    public function testNestedTransactionUsesSavepoint(): void
    {
        $this->db->transaction(function (Connection $db): void {
            $db->table('users')->insert(['name' => 'Outer']);

            try {
                $db->transaction(function (Connection $inner): void {
                    $inner->table('users')->insert(['name' => 'Inner']);
                    throw new \RuntimeException('rollback inner');
                });
            } catch (\RuntimeException) {
                // 内层回滚，外层继续
            }
        });

        $this->assertSame(4, $this->db->table('users')->count());
        $this->assertFalse($this->db->table('users')->where('name', 'Inner')->exists());
        $this->assertTrue($this->db->table('users')->where('name', 'Outer')->exists());
    }

    /**
     * 未注册的驱动应给出可操作错误。
     *
     * @return void
     */
    public function testUnknownConnectionThrows(): void
    {
        $manager = new ConnectionManager(['default' => 'mysql', 'connections' => []]);

        $this->assertThrows(
            \SunsetReverie\Database\Exception\ConnectionException::class,
            static fn () => $manager->connection()
        );
    }
}
