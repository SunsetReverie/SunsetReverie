<?php

declare(strict_types=1);

namespace SunsetReverie\Database\Migration;

use SunsetReverie\Database\Connection;
use SunsetReverie\Database\Migration\Exception\MigrationException;
use SunsetReverie\Database\Schema\Schema;

/**
 * 迁移执行器：扫描目录、计算差异、按批次执行与回滚。
 *
 * 设计取舍：
 * - **不包事务**：MySQL 的 DDL 会隐式提交，事务对建表无效；失败即停，已执行的记录保留；
 * - **按批次回滚**：每次 migrate 是一个批次，回滚默认撤掉最后一个批次的全部迁移；
 * - **文件名即顺序**：迁移文件以时间戳前缀命名，按字典序执行。
 */
final class Migrator
{
    /** @var Repository 记录表 */
    private readonly Repository $repository;

    /**
     * @param Connection $connection 连接
     * @param string $directory 迁移目录（绝对路径）
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $directory,
    ) {
        $this->repository = new Repository($connection);
    }

    /**
     * 执行所有未执行的迁移。
     *
     * @return list<string> 本次执行的迁移文件名
     * @throws MigrationException 迁移文件非法或执行失败时抛出
     */
    public function migrate(): array
    {
        $this->repository->ensureTable();

        $pending = array_values(array_diff($this->files(), $this->repository->executed()));

        if ($pending === []) {
            return [];
        }

        $batch = $this->repository->lastBatch() + 1;
        $executed = [];

        foreach ($pending as $file) {
            $this->run($file, 'up');

            $this->repository->log($file, $batch);
            $executed[] = $file;
        }

        return $executed;
    }

    /**
     * 回滚最近的若干批次。
     *
     * @param int $steps 回滚批次数
     * @return list<string> 被回滚的迁移文件名
     * @throws MigrationException 回滚失败时抛出
     */
    public function rollback(int $steps = 1): array
    {
        $this->repository->ensureTable();

        $batch = $this->repository->lastBatch();

        if ($batch === 0) {
            return [];
        }

        $rolledBack = [];

        for ($i = 0; $i < max(1, $steps) && $batch > 0; $i++, $batch--) {
            foreach ($this->repository->byBatch($batch) as $file) {
                $this->run($file, 'down');
                $this->repository->forget($file);
                $rolledBack[] = $file;
            }
        }

        return $rolledBack;
    }

    /**
     * 获取迁移状态。
     *
     * @return array{executed: array<string, int>, pending: list<string>} 状态
     */
    public function status(): array
    {
        $this->repository->ensureTable();

        $batchMap = $this->repository->batchMap();
        $pending = array_values(array_diff($this->files(), array_keys($batchMap)));

        return ['executed' => $batchMap, 'pending' => $pending];
    }

    /**
     * 生成迁移文件。
     *
     * @param string $name 迁移名（如 create_users_table）
     * @return string 生成的文件路径
     * @throws MigrationException 目录不可写时抛出
     */
    public function make(string $name): string
    {
        return self::createFile($this->directory, $name);
    }

    /**
     * 只生成文件，**不需要数据库连接**。
     *
     * 拆成静态方法是必要的：`sr make:migration` 是新建项目最先要用的命令之一，
     * 而那时 `config/database.php` 往往还没配好。原来的实现要通过
     * `$this->app->db()` 构造整个 Migrator 才能调 make()，
     * 于是没配数据库时生成一个空迁移文件都会失败：
     *
     *   生成失败：未找到数据库连接配置 [mysql]，请在 config/database.php 中定义
     *
     * 生成文件是纯文件操作，不该和连接绑在一起。
     *
     * @param string $directory 迁移目录（绝对路径）
     * @param string $name 迁移名（如 create_users_table）
     * @return string 生成的文件路径
     * @throws MigrationException 目录不可创建或同名文件已存在时抛出
     */
    public static function createFile(string $directory, string $name): string
    {
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new MigrationException('迁移目录不可创建：' . $directory);
        }

        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $name));
        $file = $directory . '/' . date('Y_m_d_His') . '_' . trim($slug, '_') . '.php';

        if (is_file($file)) {
            throw new MigrationException('同名迁移已存在：' . $file);
        }

        file_put_contents($file, self::template($name));

        return $file;
    }

    /**
     * 扫描目录中的迁移文件（按文件名排序）。
     *
     * @return list<string> 文件名列表
     */
    public function files(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }

        $files = glob($this->directory . '/*.php') ?: [];
        $names = array_map(static fn (string $path): string => basename($path), $files);
        sort($names);

        return $names;
    }

    /**
     * 执行单个迁移。
     *
     * @param string $file 文件名
     * @param string $direction up 或 down
     * @return void
     * @throws MigrationException 文件非法或执行失败时抛出
     */
    private function run(string $file, string $direction): void
    {
        $path = $this->directory . '/' . $file;

        if (!is_file($path)) {
            throw new MigrationException(sprintf('迁移文件不存在：%s（已执行记录可能过期，请检查）', $file));
        }

        $migration = require $path;

        if (!$migration instanceof Migration) {
            throw new MigrationException(sprintf('迁移文件必须返回 Migration 实例：%s', $file));
        }

        try {
            $migration->{$direction}(new Schema($this->connection));
        } catch (\Throwable $e) {
            throw new MigrationException(
                sprintf('迁移 %s 执行 %s 失败：%s', $file, $direction, $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * 生成迁移文件模板。
     *
     * @param string $name 迁移名
     * @return string PHP 代码
     */
    private static function template(string $name): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

use SunsetReverie\\Database\\Migration\\Migration;
use SunsetReverie\\Database\\Schema\\Blueprint;
use SunsetReverie\\Database\\Schema\\Schema;

// {$name}
return new class extends Migration {
    /**
     * 执行迁移。
     *
     * @param Schema \$schema 结构入口
     * @return void
     */
    public function up(Schema \$schema): void
    {
        // \$schema->create('users', function (Blueprint \$table): void {
        //     \$table->id();
        //     \$table->string('name', 100);
        //     \$table->timestamps();
        // });
    }

    /**
     * 回滚迁移。
     *
     * @param Schema \$schema 结构入口
     * @return void
     */
    public function down(Schema \$schema): void
    {
        // \$schema->dropIfExists('users');
    }
};

PHP;
    }
}
