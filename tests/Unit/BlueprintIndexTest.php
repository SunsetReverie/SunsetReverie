<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use SunsetReverie\Database\Schema\Blueprint;
use Tests\TestCase;

/**
 * 建表 DSL 的索引定义。
 *
 * 核心是 `unique()` 的两种用法必须都对：
 * - 链式 `->unique()` 修饰最近一列（历史写法）
 * - `unique('col')` 按列名（与 index() 一致）
 *
 * 第二条曾经不存在：`unique()` 签名是 `unique(): self`，不接受参数，
 * 而 PHP 会**静默忽略多余参数**。于是 `timestamps()` 之后写 `unique('sku')`，
 * 唯一索引被加到了 `updated_at` 上——插入第二条数据就冲突，
 * 且报错信息（Duplicate entry）完全指不到真正原因。
 */
final class BlueprintIndexTest extends TestCase
{
    /**
     * 链式 unique 应内联到列定义里。
     *
     * @return void
     */
    public function testChainedUnique(): void
    {
        $blueprint = new Blueprint('t');
        $blueprint->id();
        $blueprint->string('slug', 50)->unique();

        $sql = implode("\n", $blueprint->toSql('mysql'));

        $this->assertStringContains('UNIQUE (`slug`)', $sql, '链式 unique 应内联');
        $this->assertFalse(str_contains($sql, 'created_at'), '未定义 timestamps 时不应出现 created_at');
    }

    /**
     * unique('col') 应生成独立的唯一索引语句。
     *
     * @return void
     */
    public function testUniqueByColumnName(): void
    {
        $blueprint = new Blueprint('products');
        $blueprint->id();
        $blueprint->string('name', 120);
        $blueprint->string('sku', 64);
        $blueprint->timestamps();
        $blueprint->unique('sku');

        $sql = implode("\n", $blueprint->toSql('mysql'));

        $this->assertStringContains('CREATE UNIQUE INDEX `products_sku_unique` ON `products` (`sku`)', $sql);
        $this->assertFalse(
            str_contains($sql, 'updated_at UNIQUE'),
            'updated_at 不应被加上唯一索引（这是 unique() 忽略参数时的错误行为）'
        );
    }

    /**
     * unique([多列]) 应生成复合唯一索引。
     *
     * @return void
     */
    public function testCompositeUnique(): void
    {
        $blueprint = new Blueprint('post_likes');
        $blueprint->id();
        $blueprint->bigInteger('post_id');
        $blueprint->bigInteger('user_id');
        $blueprint->unique(['post_id', 'user_id']);

        $sql = implode("\n", $blueprint->toSql('mysql'));

        $this->assertStringContains('(`post_id`, `user_id`)', $sql);
    }

    /**
     * 列名写错应立刻报错，而不是等数据库抛"未知列"。
     *
     * @return void
     */
    public function testUnknownColumnThrows(): void
    {
        $blueprint = new Blueprint('t');
        $blueprint->id();
        $blueprint->string('name');

        $e = $this->assertThrows(
            InvalidArgumentException::class,
            static fn () => $blueprint->unique('nope')
        );

        $this->assertStringContains('不存在的列', $e->getMessage());
        $this->assertStringContains('nope', $e->getMessage());
    }

    /**
     * 不传参数时行为不变（向后兼容）。
     *
     * @return void
     */
    public function testNoArgumentStillModifiesLastColumn(): void
    {
        $blueprint = new Blueprint('t');
        $blueprint->string('email', 120)->unique();
        $blueprint->timestamps();

        $sql = implode("\n", $blueprint->toSql('mysql'));

        $this->assertStringContains('UNIQUE (`email`)', $sql);
        $this->assertFalse(str_contains($sql, 'updated_at UNIQUE'), 'timestamps 之后调用不带参数的 unique 才会影响它');
    }

    /**
     * 链式 index() 应生成独立的 CREATE INDEX。
     *
     * 曾经 index() 的签名是 `index(array|string $columns)`，不接受 null，
     * 而兄弟方法 unique() 两种用法都支持。使用者会自然假设两者对称：
     *
     *   $table->string('domain', 120)->index();   ← 直接爆
     *   Too few arguments to function Blueprint::index(), 0 passed and at least 1 expected
     *
     * 报错虽然明确，但第一次写迁移必踩一次。
     *
     * @return void
     */
    public function testChainedIndex(): void
    {
        $blueprint = new Blueprint('sites');
        $blueprint->id();
        $blueprint->string('domain', 120)->index();

        $sql = implode("\n", $blueprint->toSql('mysql'));

        $this->assertStringContains(
            'CREATE INDEX `sites_domain_index` ON `sites` (`domain`)',
            $sql,
            '链式 index() 应单独生成 CREATE INDEX（三库通用的写法）'
        );
        $this->assertFalse(
            str_contains($sql, 'created_at'),
            '链式 index() 只影响它前面那一列'
        );
    }

    /**
     * 链式 index() 不能污染列定义。
     *
     * 内部用 __INDEX__ 占位符标记，toSql() 会把它解析掉再生成独立语句；
     * 如果漏了 str_replace，表定义里会出现 `domain VARCHAR(120)__INDEX__` 这种垃圾。
     *
     * @return void
     */
    public function testChainedIndexDoesNotPolluteColumnDefinition(): void
    {
        $blueprint = new Blueprint('t');
        $blueprint->string('code', 20)->index();

        foreach ($blueprint->toSql('mysql') as $sql) {
            $this->assertFalse(
                str_contains($sql, '__INDEX__'),
                '占位符必须被解析掉，不能出现在最终 SQL 里：' . $sql
            );
        }
    }
}
