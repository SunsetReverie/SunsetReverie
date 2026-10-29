<?php

declare(strict_types=1);

namespace Tests\Unit;

use SunsetReverie\Database\Connection;
use SunsetReverie\Database\ConnectionManager;
use SunsetReverie\Database\Migration\Migrator;
use Tests\TestCase;

/**
 * 迁移工具测试（SQLite 内存库）。
 */
final class MigrationTest extends TestCase
{
    /** @var Connection 连接 */
    private Connection $db;

    /** @var string 迁移目录 */
    private string $directory;

    /** @var Migrator 执行器 */
    private Migrator $migrator;

    /**
     * 准备内存库与临时迁移目录。
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
        $this->directory = sys_get_temp_dir() . '/sr-migrate-' . uniqid('', true);
        mkdir($this->directory, 0755, true);
        $this->migrator = new Migrator($this->db, $this->directory);
    }

    /**
     * 清理临时目录。
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*.php') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    /**
     * 迁移应建表并写入记录，且可幂等执行。
     *
     * @return void
     */
    public function testMigrateCreatesTableAndIsIdempotent(): void
    {
        $this->writeMigration('2026_01_01_000000_create_posts_table', 'posts');

        $executed = $this->migrator->migrate();
        $this->assertSame(1, count($executed));

        $this->assertTrue($this->tableExists('posts'));
        $this->assertTrue($this->tableExists('migrations'));

        $this->assertSame([], $this->migrator->migrate(), '重复执行应无操作');
    }

    /**
     * 回滚应删除表并清除记录。
     *
     * @return void
     */
    public function testRollbackDropsTable(): void
    {
        $this->writeMigration('2026_01_01_000000_create_posts_table', 'posts');
        $this->migrator->migrate();

        $rolledBack = $this->migrator->rollback();

        $this->assertSame(1, count($rolledBack));
        $this->assertFalse($this->tableExists('posts'));
        $this->assertSame([], $this->migrator->rollback(), '无记录时回滚应无操作');
    }

    /**
     * status 应区分已执行与待执行。
     *
     * @return void
     */
    public function testStatusReportsPendingAndExecuted(): void
    {
        $this->writeMigration('2026_01_01_000000_create_posts_table', 'posts');
        $this->writeMigration('2026_01_02_000000_create_tags_table', 'tags');

        $this->migrator->migrate();

        $status = $this->migrator->status();

        $this->assertSame(2, count($status['executed']));
        $this->assertSame([], $status['pending']);
    }

    /**
     * 多个批次应可分批回滚。
     *
     * @return void
     */
    public function testRollbackStepsByBatch(): void
    {
        $this->writeMigration('2026_01_01_000000_create_posts_table', 'posts');
        $this->migrator->migrate();

        $this->writeMigration('2026_01_02_000000_create_tags_table', 'tags');
        $this->migrator->migrate();

        $this->migrator->rollback(1);

        $this->assertTrue($this->tableExists('posts'), '第一个批次应保留');
        $this->assertFalse($this->tableExists('tags'), '第二个批次应被回滚');
    }

    /**
     * make 应生成符合约定的文件名。
     *
     * @return void
     */
    public function testMakeGeneratesMigrationFile(): void
    {
        $file = $this->migrator->make('create_users_table');

        $this->assertTrue(is_file($file));
        $this->assertStringContains('create_users_table.php', $file);
        $this->assertStringContains('extends Migration', (string) file_get_contents($file));
    }

    /**
     * 写入一个测试迁移文件。
     *
     * @param string $name 文件名（不含扩展名）
     * @param string $table 表名
     * @return void
     */
    private function writeMigration(string $name, string $table): void
    {
        $code = <<<PHP
<?php

declare(strict_types=1);

use SunsetReverie\\Database\\Migration\\Migration;
use SunsetReverie\\Database\\Schema\\Blueprint;
use SunsetReverie\\Database\\Schema\\Schema;

return new class extends Migration {
    public function up(Schema \$schema): void
    {
        \$schema->create('{$table}', function (Blueprint \$table): void {
            \$table->id();
            \$table->string('title', 100);
            \$table->timestamps();
        });
    }

    public function down(Schema \$schema): void
    {
        \$schema->dropIfExists('{$table}');
    }
};

PHP;

        file_put_contents($this->directory . '/' . $name . '.php', $code);
    }

    /**
     * 判断表是否存在。
     *
     * @param string $table 表名
     * @return bool 存在返回 true
     */
    private function tableExists(string $table): bool
    {
        $row = $this->db->selectOne(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$table]
        );

        return $row !== null;
    }
}
