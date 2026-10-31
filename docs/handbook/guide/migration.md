# 迁移

迁移是**用代码描述表结构**，让"建表"这件事可版本化、可回滚、可在多台机器上复现。

文件放在 `database/migrations/`，命名格式 `YYYY_MM_DD_HHMMSS_描述.php`。

## 创建迁移

```bash
php bin/sr make:migration create_posts_table
```

生成：

```php
<?php

declare(strict_types=1);

use SunsetReverie\Database\Migration\Migration;
use SunsetReverie\Database\Schema\Blueprint;
use SunsetReverie\Database\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('posts', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('posts');
    }
};
```

::: tip 不需要配置数据库
`make:migration` 只生成文件，不连库。新项目还没配 `.env` 时也能用。

（早期版本这里会报「未找到数据库连接配置」，v0.42 修了。）
:::

## 列类型

17 个方法：

| 方法 | 参数 | 说明 |
| --- | --- | --- |
| `id()` | — | 自增主键（BIGINT） |
| `string($name, $length = 255)` | 列名、长度 | VARCHAR |
| `text($name)` | 列名 | TEXT |
| `integer($name)` | 列名 | INT |
| `bigInteger($name)` | 列名 | BIGINT |
| `boolean($name)` | 列名 | 三库映射不同（见下） |
| `json($name)` | 列名 | MySQL `JSON` / PG `JSONB` / SQLite `TEXT` |
| `timestamp($name)` | 列名 | 时间戳 |
| `date($name)` | 列名 | 日期 |
| `decimal($name, $precision = 10, $scale = 2)` | 精度、小数位 | 金额用它 |
| `timestamps()` | — | 一次加 `created_at` + `updated_at` |

### 三库的类型映射

`boolean` 和 `json` 在三种数据库上完全不同，框架自动处理：

| Blueprint | MySQL | PostgreSQL | SQLite |
| --- | --- | --- | --- |
| `boolean()` | `TINYINT(1)` | `BOOLEAN` | `INTEGER` |
| `json()` | `JSON` | `JSONB` | `TEXT` |
| `id()` | `BIGINT AUTO_INCREMENT` | `BIGSERIAL` | `INTEGER AUTOINCREMENT` |

详见[多数据库](./multi-database)。

## 修饰符

```php
$table->string('title', 200);                    // NOT NULL
$table->string('summary', 500)->nullable();      // 允许 NULL
$table->integer('status')->default(0);           // 默认值
$table->decimal('price', 10, 2)->default('0.00');
$table->timestamp('published_at')->nullable();
```

`nullable()` 和 `default()` 都作用于**最近定义的那一列**（链式）。

::: warning MySQL 的 JSON 列不能有非 NULL 默认值
```php
$table->json('snapshot')->default('{}');    // ❌ MySQL 报错
$table->json('snapshot')->nullable();       // ✅
```

所以 `json()` 通常配 `nullable()`。
:::

## 索引

### `unique()` 有两种用法

**链式**（不传参，修饰最近那列）：

```php
$table->string('slug', 200)->unique();
// → UNIQUE KEY posts_slug_unique (slug)
```

**表级**（传列名）：

```php
$table->unique('slug');                    // 单列
$table->unique(['post_id', 'user_id']);    // 复合
$table->unique('slug', 'custom_name');     // 自定义索引名
```

::: warning 一个历史坑（0.36.1 修了）
`unique()` 的早期签名是 `unique(): self`——**不接受参数**。

而 PHP 会静默忽略多余参数，所以：

```php
$table->timestamps();      // created_at, updated_at
$table->unique('sku');     // 参数被忽略！唯一索引加到了 updated_at 上
```

后果：迁移成功，插第一条正常，**插第二条报 `Duplicate entry '2026-10-30 07:59:25' for key 'updated_at'`**。

报错完全指不到真正原因。现在传列名是安全的，写错列名会直接抛：

```text
索引引用了不存在的列 [nope]（表 t3）
```
:::

### `index()` 和 `uniqueIndex()`

```php
$table->index('category_id');              // 普通索引
$table->index(['status', 'published_at']); // 复合索引
$table->uniqueIndex(['post_id', 'version']);  // 同 unique(列名)，但参数必填
```

`uniqueIndex()` 和 `unique(列名)` 效果一样，区别是**前者不接受无参调用**——想用链式就写 `unique()`。

### 索引命名

不指定名字时自动生成：

```text
{表名}_{列1}_{列2}_unique      唯一索引
{表名}_{列1}_{列2}_index       普通索引
```

超过数据库的长度限制时（MySQL 64 字符）会被截断，建议长列名时显式指定。

## 执行

```bash
php bin/sr migrate              # 执行未运行的迁移
php bin/sr migrate:status       # 看哪些跑了、哪些没跑
php bin/sr migrate:rollback     # 回滚最近一批
php bin/sr migrate:rollback --step=2   # 回滚最近 2 批
php bin/sr migrate:fresh --force       # 删全部表重建（开发用，会丢数据）
```

### 回滚必须写 `down()`

```php
public function down(Schema $schema): void
{
    $schema->dropIfExists('posts');
}
```

`dropIfExists()` 而不是 `drop()`——回滚时表不存在不应该报错（可能被手工删了）。

**回滚顺序是反的**：最后执行的迁移最先回滚。

## 一个完整的例子

```php
return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('post_versions', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('post_id');
            $table->integer('version')->default(1);
            // 快照用 JSON：以后加字段不用改表结构
            $table->json('snapshot')->nullable();
            $table->bigInteger('editor_id')->default(0);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            // 同一文章的同版本号唯一——防重复写入
            $table->uniqueIndex(['post_id', 'version']);
            // 按文章查列表用这个
            $table->index('post_id');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('post_versions');
    }
};
```

## 常见错误

### 迁移成功但表结构不对

`unique('col')` 加错位置的经典坑，见上文。用 `db:show` 或直接看数据库确认：

```sql
SHOW INDEX FROM your_table WHERE Non_unique = 0;
```

### `Duplicate entry` 但数据确实不重复

唯一索引加在了意料之外的列上。同上，查 `SHOW INDEX`。

### `migrate:fresh` 报需要 `--force`

```bash
php bin/sr migrate:fresh --force
```

这是保护：它会删掉所有表。

### 改了迁移文件但没生效

迁移**跑过就记在 `migrations` 表里**，改了文件不会重跑。

```bash
php bin/sr migrate:rollback    # 回滚
php bin/sr migrate             # 再跑
```

或者 `migrate:fresh --force`（会丢数据）。

### 三库里有一张表建不出来

大概率是方言问题。用 `Blueprint::toSql($driver)` 打印三种驱动的 SQL 对比：

```php
$b = new Blueprint('t');
$b->id();
$b->json('data')->nullable();

foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
    echo "[$driver]\n";
    foreach ($b->toSql($driver) as $sql) {
        echo "  $sql\n";
    }
}
```

## 下一步

- **[事务](./transaction)** —— 迁移之外的事务用法
- **[多数据库](./multi-database)** —— 三库方言差异全表
- **[数据库](./database)** —— Query Builder
