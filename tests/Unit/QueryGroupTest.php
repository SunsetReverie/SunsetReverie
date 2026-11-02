<?php

declare(strict_types=1);

namespace Tests\Unit;

use SunsetReverie\Database\Connection;
use SunsetReverie\Database\ConnectionManager;
use Tests\TestCase;

/**
 * 条件分组与 NULL 判断。
 *
 * 为什么单独测 whereGroup：SQL 里 AND 优先级高于 OR，
 * 不加括号时 `status = 1 AND title LIKE ? OR content LIKE ?`
 * 会变成 `(status = 1 AND title LIKE ?) OR content LIKE ?` ——
 * 未发布内容只要正文匹配就被查出来，是数据泄露级别的缺陷。
 */
final class QueryGroupTest extends TestCase
{
    /** @var Connection 内存库 */
    private Connection $db;

    /**
     * 建表并灌数据。
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
            'CREATE TABLE posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                content TEXT,
                status INTEGER NOT NULL DEFAULT 0,
                paid_at TEXT
            )'
        );

        // 已发布 + 标题命中
        $this->db->table('posts')->insert(['title' => '已发布-关键词', 'content' => '正文', 'status' => 1]);
        // 已发布 + 正文命中
        $this->db->table('posts')->insert(['title' => '已发布-普通', 'content' => '正文里有 关键词', 'status' => 1]);
        // 草稿 + 正文命中（这是要防住的泄露）
        $this->db->table('posts')->insert(['title' => '草稿-普通', 'content' => '正文里有 关键词', 'status' => 0]);
    }

    /**
     * whereGroup 必须产生括号，否则会查出未发布内容。
     *
     * @return void
     */
    public function testWhereGroupPreventsDraftLeak(): void
    {
        $sql = $this->db->table('posts')
            ->where('status', 1)
            ->whereGroup(static fn ($q) => $q->where('title', 'LIKE', '%关键词%')->orWhere('content', 'LIKE', '%关键词%'))
            ->toSql();

        // 标识符按驱动引用（sqlite 用双引号），断言要跟着带引号
        $this->assertStringContains('("title" like ? OR "content" like ?)', $sql, '分组必须带括号');

        $count = $this->db->table('posts')
            ->where('status', 1)
            ->whereGroup(static fn ($q) => $q->where('title', 'LIKE', '%关键词%')->orWhere('content', 'LIKE', '%关键词%'))
            ->count();

        $this->assertSame(2, $count, '只应返回 2 条已发布的（草稿不能被查出）');
    }

    /**
     * 不加分组的写法确实会泄露（对照组）。
     *
     * 这条测试把缺陷本身记录下来：以后谁把 whereGroup 改掉，
     * 会让上面那条红，这条说明为什么不能改。
     *
     * @return void
     */
    public function testWithoutGroupLeaksDraft(): void
    {
        $count = $this->db->table('posts')
            ->where('status', 1)
            ->where('title', 'LIKE', '%关键词%')
            ->orWhere('content', 'LIKE', '%关键词%')
            ->count();

        $this->assertSame(3, $count, '无分组时草稿会被查出（这正是需要 whereGroup 的原因）');
    }

    /**
     * 嵌套两层。
     *
     * @return void
     */
    public function testNestedGroups(): void
    {
        $sql = $this->db->table('posts')
            ->where('status', 1)
            ->whereGroup(static function ($q): void {
                $q->where('id', '>', 0)->whereGroup(
                    static fn ($q2) => $q2->where('title', 'LIKE', '%已发布%')->orWhere('content', 'LIKE', '%无%')
                );
            })
            ->toSql();

        $this->assertStringContains(
            'AND ("id" > ? AND ("title" like ? OR "content" like ?))',
            $sql,
            '嵌套括号应按层生成'
        );
    }

    /**
     * orWhereGroup 用 OR 连接分组。
     *
     * @return void
     */
    public function testOrWhereGroup(): void
    {
        $count = $this->db->table('posts')
            ->where('status', 99)
            ->orWhereGroup(static fn ($q) => $q->where('title', 'LIKE', '%草稿%')->where('status', 0))
            ->count();

        $this->assertSame(1, $count, 'OR 分组应生效');
    }

    /**
     * 空分组不应产生多余括号。
     *
     * @return void
     */
    public function testEmptyGroupIsIgnored(): void
    {
        $sql = $this->db->table('posts')->where('status', 1)->whereGroup(static function (): void {
        })->toSql();

        $this->assertFalse(str_contains($sql, 'AND ()'), '空分组不应生成 AND ()');
    }

    /**
     * whereNotNull。
     *
     * @return void
     */
    public function testWhereNotNull(): void
    {
        $this->db->table('posts')->where('id', 1)->update(['paid_at' => '2026-10-30 10:00:00']);

        $this->assertSame(
            1,
            $this->db->table('posts')->whereNotNull('paid_at')->count(),
            '只有 1 条设置了 paid_at'
        );
        $this->assertSame(2, $this->db->table('posts')->whereNull('paid_at')->count(), '剩下 2 条为 NULL');
    }
}
