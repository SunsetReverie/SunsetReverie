# 多数据库

同一套代码跑在三种数据库上，框架抹平了大部分差异，但**不是全部**。

这一篇说清楚：哪些不用管、哪些必须注意。

## 配置

```ini
# .env
DB_DRIVER=mysql          # sqlite / mysql / pgsql

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=myapp
DB_USERNAME=root
DB_PASSWORD=secret
```

SQLite 只要两行：

```ini
DB_DRIVER=sqlite
DB_DATABASE=storage/database.sqlite
```

## 框架已经抹平的

### 1. 自增主键

```php
$table->id();
```

| 数据库 | 实际生成 |
| --- | --- |
| MySQL | `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY` |
| PostgreSQL | `BIGSERIAL PRIMARY KEY` |
| SQLite | `INTEGER PRIMARY KEY AUTOINCREMENT` |

### 2. 列类型

| Blueprint | MySQL | PostgreSQL | SQLite |
| --- | --- | --- | --- |
| `boolean()` | `TINYINT(1)` | `BOOLEAN` | `INTEGER` |
| `json()` | `JSON` | `JSONB` | `TEXT` |
| `timestamp()` | `DATETIME` | `TIMESTAMP` | `DATETIME` |
| `decimal(10,2)` | `DECIMAL(10,2)` | `DECIMAL(10,2)` | `DECIMAL(10,2)` |

可以用 `Blueprint::toSql($driver)` 核对：

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

实测输出：

```text
[mysql]  CREATE TABLE t (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, data JSON NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
[pgsql]  CREATE TABLE t (id BIGSERIAL PRIMARY KEY, data JSONB NULL)
[sqlite] CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, data TEXT NULL)
```

### 3. `insertGetId()`

| 数据库 | 实现 |
| --- | --- |
| PostgreSQL | `INSERT ... RETURNING id` + 读回标量 |
| MySQL / SQLite | `INSERT` + `PDO::lastInsertId()` |

**你不用关心**——调 `insertGetId()` 都返回 `int`。

::: warning PostgreSQL 必须用 `insertGetId()`
PG 的 `lastInsertId()` 在没指定序列时行为不可靠。框架在 PG 上走 `RETURNING`，
所以**一定要用 `insertGetId()` 而不是 `insert()` + 手动查序列**。
:::

### 4. 布尔值绑定

```php
->where('is_active', true)
```

`Connection::typeOf()` 把 PHP 的 `bool` 绑成 `PDO::PARAM_BOOL`，由 PDO 按驱动转换：

- MySQL：`1` / `0`
- PostgreSQL：`true` / `false`
- SQLite：`1` / `0`

所以三种库上都能这么写。

### 5. 分页

`limit()` / `offset()` 生成的 SQL 三库一致。

## 没抹平的（必须注意）

### 1. PostgreSQL 的事务中止

**最容易踩的一个**。

| 数据库 | 事务内一条语句报错后 |
| --- | --- |
| MySQL | 该语句失败，**事务继续** |
| PostgreSQL | **整个事务进入 aborted 状态**，后续所有语句都报 `25P02` |

实测（同一段代码）：

```text
PostgreSQL
  捕获到：SQLSTATE[23505]: Unique violation
  后续插入失败：SQLSTATE[25P02]: In failed sql transaction

MySQL
  捕获到：SQLSTATE[23000]: Integrity constraint violation
  后续插入成功          ← 与 PG 不同
```

**应对**：需要"尽力而为"的子操作时，包一层 `transaction()`（保存点）：

```php
try {
    DB::transaction(fn ($db) => DB::table('t')->insert(['id' => 1]));
} catch (QueryException) {
    // 保存点已回滚，外层事务仍可继续（PG 上也行）
}
```

详见[事务](./transaction#跨库注意-postgresql-的事务中止)。

### 2. `GROUP BY` 的严格程度

```php
DB::table('posts')
    ->selectRaw('status', 'COUNT(*) AS total')
    ->groupBy('status');
// → SELECT status, COUNT(*) AS total FROM posts GROUP BY status    ✅ 两库都行
```

但如果你漏了某个非聚合列：

```sql
SELECT id, status, COUNT(*) FROM posts GROUP BY status
```

| 数据库 | 结果 |
| --- | --- |
| MySQL | 容忍（`ONLY_FULL_GROUP_BY` 关闭时），随便取一个 id |
| PostgreSQL | **直接报错** |

```text
column "posts.id" must appear in the GROUP BY clause or be used in an aggregate function
```

**这条差异在 v0.41 真实踩过**：`selectRaw()` 默认自带 `*`，生成 `SELECT *, COUNT(*)`——
MySQL 上一直正常，换 PG 立刻崩。现在 `selectRaw()` 会清掉默认通配符。

### 3. 标识符大小写

| 数据库 | 未加引号的标识符 |
| --- | --- |
| MySQL | 大小写不敏感（取决于 `lower_case_table_names`） |
| PostgreSQL | **一律转小写** |
| SQLite | 不敏感 |

所以 `SELECT * FROM Posts` 在 PG 上实际查的是 `posts` 表。

**建议**：列名、表名全用**小写下划线**，不要用驼峰或大写。

### 4. 默认隔离级别

| 数据库 | 默认 |
| --- | --- |
| MySQL（InnoDB） | REPEATABLE READ |
| PostgreSQL | READ COMMITTED |
| SQLite | SERIALIZABLE |

影响：MySQL 同一事务内两次读同一行结果一样（快照读），PG 会读到已提交的新值。

### 5. `JSON` 内部字段查询

```sql
-- MySQL / PostgreSQL
SELECT * FROM t WHERE data->>'$.name' = 'x';      -- MySQL
SELECT * FROM t WHERE data->>'name' = 'x';        -- PostgreSQL

-- SQLite
SELECT * FROM t WHERE json_extract(data, '$.name') = 'x';
```

**语法三库完全不同**，框架不做转换。这类查询用原生 SQL，并按驱动分支。

### 6. `TRUNCATE` / `ON DUPLICATE KEY` 等专有语法

框架不提供这些的抽象。用原生 SQL 时自己处理方言。

## 怎么验证三库都能跑

框架自带契约测试：

```bash
# SQLite
SR_TEST_DB=sqlite php tests/run.php --filter=DialectContract

# MySQL（需要先建库）
SR_TEST_DB=mysql SR_TEST_DB_NAME=sr_test SR_TEST_DB_USER=root SR_TEST_DB_PASS=root \
  php tests/run.php --filter=DialectContract

# PostgreSQL
SR_TEST_DB=pgsql SR_TEST_DB_NAME=xi_blog_pg SR_TEST_DB_USER=postgres \
  php tests/run.php --filter=DialectContract
```

四个断言覆盖：建表方言、类型映射、事务回滚、批量插入 + 聚合。

**注意**：不设 `SR_TEST_DB` 时整个测试文件跳过并打印原因。**跳过不等于通过**。

## 选型

| 场景 | 推荐 |
| --- | --- |
| 开发 / 测试 | **SQLite**（零配置，内存库最快） |
| 中小型生产 | **MySQL 8**（运维熟悉、生态成熟） |
| 需要强类型 / JSON 查询 / 复杂分析 | **PostgreSQL** |

::: warning 不要只在 SQLite 上开发
SQLite 和另外两个的差距比想象中大：

- 没有网络往返 → 基准测试里 `insertMany` 只快 3.9×（真实库 31-40×）
- 类型系统宽松 → `GROUP BY` 不严格、`DECIMAL` 退化成浮点
- 并发模型不同 → 写锁是库级的

**关键查询至少要在目标数据库上跑一遍。**
:::

## 常见错误

### MySQL 上好的，PG 上报 `must appear in the GROUP BY clause`

`SELECT *` 加聚合。显式列出需要的列。

### PG 上报 `current transaction is aborted`

事务里捕获了查询异常又继续操作。见上文。

### 表名不存在，但建表脚本跑了

PG 把标识符转小写了。检查建表用的是 `Posts` 还是 `posts`。

### `insertGetId()` 返回 0

- 表没有自增主键
- 或者主键不叫 `id`（第二个参数可以指定：`insertGetId($data, 'post_id')`）

### DECIMAL 取回来是字符串，比较失败

```php
DB::table('orders')->where('total_amount', '19.99')->first();   // PG 上可能不匹配
```

PG 的 `DECIMAL` 和字符串比较需要显式转型。稳妥做法是用整数「分」存储和比较。

## 下一步

- **[数据库](./database)** —— Query Builder
- **[迁移](./migration)** —— 建表与索引
- **[事务](./transaction)** —— 嵌套与并发
