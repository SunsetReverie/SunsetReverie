<?php

declare(strict_types=1);

namespace Tests\Unit;

use SunsetReverie\Database\Schema\Blueprint;
use Tests\TestCase;

/**
 * JSON 列的跨库映射。
 *
 * 三种数据库没有统一的 JSON 类型：MySQL 是 JSON、PostgreSQL 是 JSONB、
 * SQLite 根本没有原生类型（用 TEXT + JSON1 扩展函数）。
 * 这类差异必须在建表时抹平，否则迁移在换库时直接失败。
 */
final class BlueprintJsonTest extends TestCase
{
    /**
     * 三种驱动应生成各自正确的类型。
     *
     * @return void
     */
    public function testJsonColumnMapping(): void
    {
        $blueprint = new Blueprint('snapshots');
        $blueprint->id();
        $blueprint->json('payload')->nullable();

        $expected = ['mysql' => 'JSON', 'pgsql' => 'JSONB', 'sqlite' => 'TEXT'];

        foreach ($expected as $driver => $type) {
            $sql = implode("\n", $blueprint->toSql($driver));

            $this->assertStringContains("payload {$type}", $sql, "[{$driver}] 应为 {$type}");
        }
    }

    /**
     * JSON 列默认允许 NULL（MySQL 不允许非 NULL 字面量默认值）。
     *
     * @return void
     */
    public function testJsonColumnNullable(): void
    {
        $blueprint = new Blueprint('t');
        $blueprint->json('data')->nullable();

        $this->assertStringContains('data JSON NULL', implode("\n", $blueprint->toSql('mysql')));
    }

    /**
     * 与其他列混用不应干扰映射。
     *
     * @return void
     */
    public function testJsonMixedWithOtherColumns(): void
    {
        $blueprint = new Blueprint('t');
        $blueprint->id();
        $blueprint->bigInteger('post_id');
        $blueprint->integer('version');
        $blueprint->json('snapshot')->nullable();
        $blueprint->boolean('is_current')->default(true);
        $blueprint->timestamps();

        $sql = implode("\n", $blueprint->toSql('mysql'));

        $this->assertStringContains('version INTEGER NOT NULL', $sql);
        $this->assertStringContains('snapshot JSON NULL', $sql);
        $this->assertStringContains('is_current TINYINT(1) DEFAULT TRUE NOT NULL', $sql);
    }
}
