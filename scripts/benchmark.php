#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * 基准测试：输出 markdown 表格，可直接贴进 docs/benchmarks/。
 *
 * 用法：
 *   php scripts/benchmark.php                     # SQLite 内存库
 *   SR_TEST_DB=mysql SR_TEST_DB_NAME=sr_test \
 *     SR_TEST_DB_USER=root SR_TEST_DB_PASS=root \
 *     php scripts/benchmark.php                   # 指定数据库
 *
 * 设计原则：
 *
 * 1. **只比相对关系，不宣称绝对值**。
 *    同一台机器上的 1000 次循环 / 10000 次读写，用来回答
 *    "GD 比 Imagick 快多少"、"insertMany 比循环 insert 快多少"——
 *    这类结论换台机器依然成立。绝对耗时（如 42ms）换机器就没意义了。
 *
 * 2. **每项跑多次取中位数**，不用单次结果：GC、CPU 调频、磁盘缓存
 *    都会让第一次和第二次差出几倍。
 *
 * 3. **预热一次再计时**：首次连库、首次编解码、类加载都算在预热里，
 *    否则测的是"装配时间"而不是"操作时间"。
 */

$basePath = dirname(__DIR__);

require $basePath . '/core/Foundation/autoload.php';

use SunsetReverie\Cache\CacheManager;
use SunsetReverie\Database\Connection;
use SunsetReverie\Database\ConnectionManager;
use SunsetReverie\Foundation\Application;

/**
 * 跑一个基准，返回中位数（毫秒）。
 *
 * @param string $label 名称
 * @param callable $operation 待测操作
 * @param int $rounds 轮数
 * @param int $iterations 每轮内的重复次数
 * @return float 中位耗时（毫秒）
 */
function bench(string $label, callable $operation, int $rounds = 5, int $iterations = 1): float
{
    // 预热：首次连库/编解码/类加载不计入
    $operation();

    $samples = [];

    for ($i = 0; $i < $rounds; $i++) {
        $start = hrtime(true);

        for ($j = 0; $j < $iterations; $j++) {
            $operation();
        }

        $samples[] = (hrtime(true) - $start) / 1e6;
    }

    sort($samples);
    $middle = intdiv(count($samples), 2);

    return count($samples) % 2 === 1
        ? $samples[$middle]
        : ($samples[$middle - 1] + $samples[$middle]) / 2;
}

/**
 * 各数据库的自增主键写法。
 *
 * 基准脚本直接写 DDL（不走 Blueprint），所以必须自己处理：
 * 同一句 `INTEGER PRIMARY KEY` 在 SQLite 是自增、在 MySQL 是自增、
 * 在 PostgreSQL **不自增**（需要 SERIAL / BIGSERIAL / IDENTITY）。
 *
 * @param Connection $db 连接
 * @return string 主键列定义
 */
function primaryKeyColumn(Connection $db): string
{
    return match ($db->driver()) {
        'mysql' => 'id BIGINT AUTO_INCREMENT PRIMARY KEY',
        'pgsql' => 'id BIGSERIAL PRIMARY KEY',
        default => 'id INTEGER PRIMARY KEY AUTOINCREMENT',
    };
}

/**
 * 时间戳列类型（SQLite 的 DATETIME 实质是 TEXT）。
 *
 * @param Connection $db 连接
 * @return string 列类型
 */
function timestampType(Connection $db): string
{
    return $db->driver() === 'pgsql' ? 'TIMESTAMP' : 'DATETIME';
}

/**
 * 输出一行表格。
 *
 * @param array<int, string> $cells 单元格
 * @return void
 */
function row(array $cells): void
{
    echo '| ' . implode(' | ', $cells) . ' |', PHP_EOL;
}

/**
 * 从环境变量解析数据库连接配置。
 *
 * @return array<string, mixed>|null 配置（未设置时为 null）
 */
function databaseConfig(): ?array
{
    $driver = getenv('SR_TEST_DB') ?: 'sqlite';

    if ($driver === 'sqlite') {
        return ['driver' => 'sqlite', 'database' => ':memory:'];
    }

    return [
        'driver' => $driver,
        'host' => (string) (getenv('SR_TEST_DB_HOST') ?: '127.0.0.1'),
        'port' => (int) (getenv('SR_TEST_DB_PORT') ?: ($driver === 'mysql' ? 3306 : 5432)),
        'database' => (string) (getenv('SR_TEST_DB_NAME') ?: 'sr_test'),
        'username' => (string) (getenv('SR_TEST_DB_USER') ?: ($driver === 'mysql' ? 'root' : 'postgres')),
        'password' => (string) (getenv('SR_TEST_DB_PASS') ?: ''),
        'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
    ];
}

$config = databaseConfig();
$db = null;

if ($config !== null) {
    try {
        $manager = new ConnectionManager(['default' => 'bench', 'connections' => ['bench' => $config]]);
        $db = $manager->connection();
        $db->scalar('SELECT 1');
    } catch (Throwable $e) {
        fwrite(STDERR, '数据库不可用，跳过数据库相关基准：' . $e->getMessage() . PHP_EOL);
        $db = null;
    }
}

// ── 环境 ──
echo '## 环境', PHP_EOL, PHP_EOL;
row(['项', '值']);
row(['---', '---']);
row(['PHP', PHP_VERSION]);
row(['驱动', $db === null ? '（未连接）' : (string) $db->driver()]);
row(['GD', extension_loaded('gd') ? '有' : '无']);
row(['Imagick', extension_loaded('imagick') ? (Imagick::getVersion()['versionString'] ?? '有') : '无']);
row(['pcov', extension_loaded('pcov') ? '有' : '无']);
echo PHP_EOL;

// ── 数据库 ──
if ($db !== null) {
    $table = 'bench_items_' . strtolower(bin2hex(random_bytes(3)));

    $db->affect(sprintf(
        'CREATE TABLE %s (%s, name VARCHAR(80) NOT NULL, amount INTEGER NOT NULL, created_at %s NOT NULL)',
        $table,
        primaryKeyColumn($db),
        timestampType($db)
    ));

    $now = date('Y-m-d H:i:s');
    $rows = [];

    for ($i = 0; $i < 1000; $i++) {
        $rows[] = ['name' => 'item-' . $i, 'amount' => $i, 'created_at' => $now];
    }

    echo '## 数据库', PHP_EOL, PHP_EOL;
    row(['操作', '中位耗时', '说明']);
    row(['---', '---', '---']);

    // 循环单条插入 vs 批量插入
    $single = bench('single-insert', static function () use ($db, $table, $now): void {
        for ($i = 0; $i < 100; $i++) {
            $db->table($table)->insert(['name' => 'x' . $i, 'amount' => $i, 'created_at' => $now]);
        }
    }, 3);

    $bulk = bench('bulk-insert', static function () use ($db, $table, $rows): void {
        $db->table($table)->insertMany($rows);
    }, 3);

    row([
        '插入 1000 条（循环 insert）',
        sprintf('%.1f ms', $single * 10),
        '每轮 100 条 × 10',
    ]);
    row([
        '插入 1000 条（insertMany）',
        sprintf('%.1f ms', $bulk),
        sprintf('提速 %.1f×', $single * 10 / max(0.01, $bulk)),
    ]);

    // 查询
    $read = bench('select', static fn (): array => $db->table($table)->limit(1000)->get());
    row(['查询 1000 行', sprintf('%.1f ms', $read), 'get() 全量']);

    $count = bench('count', static fn (): int => $db->table($table)->count(), 5, 10);
    row(['COUNT(*) × 10', sprintf('%.1f ms', $count), '简单聚合']);

    $group = bench('group', static fn (): array => $db->table($table)
        ->selectRaw('amount', 'COUNT(*) AS total')
        ->groupBy('amount')
        ->limit(50)
        ->get(), 5, 5);
    row(['GROUP BY × 5', sprintf('%.1f ms', $group), 'selectRaw + groupBy']);

    $db->affect(sprintf('DROP TABLE %s', $table));
    echo PHP_EOL;
}

// ── 缓存 ──
$cacheConfig = [
    'default' => 'file',
    'prefix' => 'bench:',
    'stores' => [
        'array' => ['driver' => 'array'],
        'file' => ['driver' => 'file', 'path' => sys_get_temp_dir() . '/sr-bench-cache'],
    ],
];

if (extension_loaded('redis')) {
    $cacheConfig['stores']['redis'] = ['driver' => 'redis', 'host' => '127.0.0.1', 'port' => 6379, 'timeout' => 1.0];
}

echo '## 缓存（10000 次 set + get）', PHP_EOL, PHP_EOL;
row(['驱动', '中位耗时', '每万次操作']);
row(['---', '---', '---']);

foreach (array_keys($cacheConfig['stores']) as $storeName) {
    try {
        $cache = new CacheManager($cacheConfig);
        $store = $cache->store($storeName);
        $store->put('warmup', 1);

        $elapsed = bench('cache-' . $storeName, static function () use ($store): void {
            for ($i = 0; $i < 1000; $i++) {
                $store->put('k' . $i, $i);
                $store->get('k' . $i);
            }
        }, 3);

        row([$storeName, sprintf('%.1f ms', $elapsed), '1000 次 put+get × 3 轮取中位']);
    } catch (Throwable $e) {
        row([$storeName, '跳过', $e->getMessage()]);
    }
}

echo PHP_EOL;

// ── 图片 ──
$drivers = [];

if (extension_loaded('gd')) {
    $drivers[] = 'gd';
}

if (extension_loaded('imagick')) {
    $drivers[] = 'imagick';
}

if ($drivers !== []) {
    // 造一张 1600×1200 的图
    $source = sys_get_temp_dir() . '/sr-bench-source.jpg';
    $image = imagecreatetruecolor(1600, 1200);

    for ($i = 0; $i < 60; $i++) {
        $color = imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255));
        imagefilledellipse($image, random_int(0, 1600), random_int(0, 1200), random_int(100, 600), random_int(100, 600), $color);
    }

    imagejpeg($image, $source, 90);

    echo '## 图片（1600×1200 → 300×300 WebP）', PHP_EOL, PHP_EOL;
    row(['驱动', '中位耗时', '输出体积']);
    row(['---', '---', '---']);

    foreach ($drivers as $driver) {
        $manager = new \SunsetReverie\Image\ImageManager(['driver' => $driver, 'jpeg_quality' => 82]);
        $target = sys_get_temp_dir() . '/sr-bench-' . $driver . '.webp';

        $elapsed = bench('image-' . $driver, static function () use ($manager, $source, $target): void {
            $manager->open($source)->cover(300, 300)->format('webp')->quality(80)->save($target);
        }, 5);

        row([
            $driver,
            sprintf('%.1f ms', $elapsed),
            is_file($target) ? round(filesize($target) / 1024, 1) . ' KB' : '—',
        ]);
    }

    @unlink($source);

    foreach ($drivers as $driver) {
        @unlink(sys_get_temp_dir() . '/sr-bench-' . $driver . '.webp');
    }

    echo PHP_EOL;
}

// ── 队列入队 ──
if ($db !== null) {
    echo '## 队列入队', PHP_EOL, PHP_EOL;
    row(['操作', '中位耗时', '说明']);
    row(['---', '---', '---']);

    $jobsTable = 'bench_jobs_' . strtolower(bin2hex(random_bytes(3)));

    $db->affect(sprintf(
        'CREATE TABLE %s (%s, queue VARCHAR(64), payload TEXT, attempts INTEGER DEFAULT 0, available_at INTEGER, reserved_token VARCHAR(36), reserved_at INTEGER, created_at VARCHAR(32))',
        $jobsTable,
        primaryKeyColumn($db)
    ));

    $payload = json_encode(['class' => 'Foo', 'data' => ['n' => 1]]);
    $now = time();

    $elapsed = bench('queue-push', static function () use ($db, $jobsTable, $payload, $now): void {
        for ($i = 0; $i < 100; $i++) {
            $db->table($jobsTable)->insert([
                'queue' => 'default',
                'payload' => $payload,
                'attempts' => 0,
                'available_at' => $now,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }, 3);

    row(['入队 1000 条', sprintf('%.1f ms', $elapsed * 10), '每轮 100 条 × 10']);
    row(['单条入队', sprintf('%.2f ms', $elapsed / 100), '含序列化与一次 INSERT']);

    $db->affect(sprintf('DROP TABLE %s', $jobsTable));
    echo PHP_EOL;
}

echo '---', PHP_EOL;
echo '说明：以上均为**同机相对比较**。绝对耗时随机器、PHP 版本、磁盘性能变化；', PHP_EOL;
echo '结论应引用「提速 N 倍」这类相对值，而不是毫秒数。', PHP_EOL;
