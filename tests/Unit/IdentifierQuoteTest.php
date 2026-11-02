<?php

declare(strict_types=1);

namespace Tests\Unit;

use SunsetReverie\Database\Connection;
use SunsetReverie\Database\ConnectionManager;
use SunsetReverie\Database\Schema\Blueprint;
use SunsetReverie\Database\Schema\Schema;
use Tests\TestCase;

/**
 * 标识符引用：表名 / 列名 / 索引名按驱动加引号。
 *
 * 为什么必须有这一层：
 *
 *   CREATE TABLE settings (key VARCHAR(64), group VARCHAR(32))
 *   → MySQL: You have an error in your SQL syntax near 'key VARCHAR(64)...'
 *
 * `key` / `group` / `order` / `before` 都是 SQL 保留字，不加引号就是语法错误。
 * 而报错只有数据库的通用提示，批量迁移时只能定位到「哪个文件失败」，
 * 看不出是哪一列冲突。
 *
 * 更隐蔽的是**只修一半**：如果只有 DDL 加引号而 DML 不加，
 * 建表成功、插入才失败——问题出现得更晚，更难定位。
 *
 * 所以这个测试同时覆盖 DDL（Blueprint）与 DML（Builder）两侧。
 */
final class IdentifierQuoteTest extends TestCase
{
    /** @var Connection 内存库 */
    private Connection $db;

    /** @var Schema 表结构入口 */
    private Schema $schema;

    protected function setUp(): void
    {
        $manager = new ConnectionManager([
            'default' => 'sqlite',
            'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]);

        $this->db = $manager->connection();
        $this->schema = new Schema($this->db);
    }

    /**
     * 建一张列名全是 SQL 保留字的表。
     *
     * @return void
     */
    private function createReservedWordTable(): void
    {
        $this->schema->create('settings', static function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('group', 32)->index();
            $table->integer('order')->default(0);
            $table->timestamp('before')->nullable();
            $table->timestamps();
        });
    }

    /**
     * 三种驱动各自用正确的引号符号。
     *
     * MySQL → 反引号；PostgreSQL / SQLite → 双引号（SQL 标准）。
     *
     * @return void
     */
    public function testEachDriverUsesItsQuoteCharacter(): void
    {
        $blueprint = new Blueprint('settings');
        $blueprint->id();
        $blueprint->string('key', 64);

        $mysql = implode("\n", $blueprint->toSql('mysql'));
        $pgsql = implode("\n", $blueprint->toSql('pgsql'));
        $sqlite = implode("\n", $blueprint->toSql('sqlite'));

        // MySQL：反引号
        $this->assertStringContains('CREATE TABLE `settings`', $mysql);
        $this->assertStringContains('`key` VARCHAR(64)', $mysql);

        // PG / SQLite：双引号
        $this->assertStringContains('CREATE TABLE "settings"', $pgsql);
        $this->assertStringContains('"key" VARCHAR(64)', $pgsql);
        $this->assertStringContains('CREATE TABLE "settings"', $sqlite);
        $this->assertStringContains('"key" VARCHAR(64)', $sqlite);

        // 不该出现「未加引号」的裸列名
        $this->assertFalse(
            str_contains($mysql, ' key VARCHAR'),
            'MySQL 的列名必须带反引号：' . $mysql
        );
    }

    /**
     * 索引名和索引列名也要加引号。
     *
     * @return void
     */
    public function testIndexIdentifiersAreQuoted(): void
    {
        $blueprint = new Blueprint('settings');
        $blueprint->string('group', 32);
        $blueprint->index('group');

        $sql = implode("\n", $blueprint->toSql('mysql'));

        $this->assertStringContains(
            'CREATE INDEX `settings_group_index` ON `settings` (`group`)',
            $sql
        );
    }

    /**
     * 保留字列名能真实建表（DDL 侧）。
     *
     * @return void
     */
    public function testDdlAcceptsReservedWordColumns(): void
    {
        $this->createReservedWordTable();

        $tables = $this->db->select(
            "SELECT name FROM sqlite_master WHERE type = ? AND name = ?",
            ['table', 'settings']
        );

        $this->assertSame(1, count($tables), 'settings 表应建成功');
    }

    /**
     * 保留字列名能正常增删改查（DML 侧）。
     *
     * 这条是关键：只修 DDL 不修 DML 的话，建表会成功，这里的 insert 才失败——
     * 问题出现得更晚，且报错是 `near "group": syntax error`，很容易被误判成 SQL 写错了。
     *
     * @return void
     */
    public function testDmlWorksWithReservedWordColumns(): void
    {
        $this->createReservedWordTable();

        $now = date('Y-m-d H:i:s');

        // INSERT
        $id = $this->db->table('settings')->insertGetId([
            'key' => 'site_name',
            'group' => 'basic',
            'order' => 1,
            'before' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->assertTrue($id > 0, 'INSERT 应返回自增 ID');

        // SELECT + WHERE
        $row = $this->db->table('settings')->where('key', 'site_name')->first();

        // fail() 的返回类型是 never，所以这里不需要再 return
        if ($row === null) {
            $this->fail('WHERE key = ? 应能查到，实际返回 null');
        }

        $this->assertSame('basic', $row['group']);

        // UPDATE
        $affected = $this->db->table('settings')->where('key', 'site_name')->update(['order' => 99]);

        $this->assertSame(1, $affected);

        // ORDER BY
        $rows = $this->db->table('settings')->orderBy('order', 'desc')->get();

        $this->assertSame(1, count($rows));

        // whereIn / whereBetween / whereNull
        $this->assertSame(1, count($this->db->table('settings')->whereIn('key', ['site_name'])->get()));
        $this->assertSame(1, count($this->db->table('settings')->whereBetween('order', [1, 100])->get()));

        // 插入时 before 就是 null，所以 whereNull 命中 1 条
        $this->assertSame(1, $this->db->table('settings')->whereNull('before')->count());

        // increment（原子自增）
        $this->assertSame(1, $this->db->table('settings')->where('key', 'site_name')->increment('order', 10));
        $this->assertSame('109', (string) $this->db->table('settings')->where('key', 'site_name')->value('order'));

        // DELETE
        $this->assertSame(1, $this->db->table('settings')->where('key', 'site_name')->delete());
    }

    /**
     * 唯一索引在保留字列上照样生效。
     *
     * @return void
     */
    public function testUniqueConstraintWorksOnReservedWordColumn(): void
    {
        $this->createReservedWordTable();

        $now = date('Y-m-d H:i:s');
        $row = [
            'key' => 'site_name',
            'group' => 'basic',
            'order' => 1,
            'before' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->db->table('settings')->insert($row);

        // 第二次插入同一个 key 应被唯一索引拦住
        $this->assertThrows(
            \Throwable::class,
            fn () => $this->db->table('settings')->insert($row)
        );
    }

    /**
     * 生成的 SQL 里不该出现任何裸标识符。
     *
     * 用 DML 的调试方法 toSql() 直接看结果，比逐条断言语义错误更容易定位。
     *
     * @return void
     */
    public function testGeneratedDmlSqlHasNoBareIdentifiers(): void
    {
        $this->createReservedWordTable();

        $sql = $this->db->table('settings')
            ->where('key', 'a')
            ->orderBy('order', 'desc')
            ->toSql();

        $this->assertStringContains('WHERE "key" = ?', $sql);
        $this->assertStringContains('ORDER BY "order" DESC', $sql);
        $this->assertStringContains('FROM "settings"', $sql);
    }

    /**
     * 标识符内部含引号时按 SQL 标准双写转义，不能拼出破坏语法的 SQL。
     *
     * 正常业务不会用这种列名，但 `toSql()` 是公开方法，
     * 拼接处必须保证「输入什么都不会破坏语句结构」。
     *
     * @return void
     */
    public function testQuoteIsEscapedByDoubling(): void
    {
        $blueprint = new Blueprint('we"ird');
        $blueprint->string('co"l', 10);

        $sql = implode("\n", $blueprint->toSql('pgsql'));

        $this->assertStringContains('CREATE TABLE "we""ird"', $sql);
        $this->assertStringContains('"co""l" VARCHAR(10)', $sql);
    }

    /**
     * table.column 形式的标识符要分别引用，点号保留。
     *
     * @return void
     */
    public function testQualifiedIdentifierQuotesEachSegment(): void
    {
        $sql = $this->db->table('posts')
            ->where('posts.status', 1)
            ->toSql();

        // sqlite 用双引号
        $this->assertStringContains('"posts"."status" = ?', $sql);
    }
}
