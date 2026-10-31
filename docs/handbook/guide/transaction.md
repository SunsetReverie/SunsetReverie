# 事务

## 基本用法

```php
$result = DB::transaction(function (): array {
    $orderId = DB::table('orders')->insertGetId([...]);
    DB::table('order_items')->insertMany([...]);

    return ['id' => $orderId];
});
```

三个特性：

1. **回调不需要参数**——闭包里直接用 `DB::` 门面，指向的就是当前这个连接
2. **返回值会传出来**（`$result` 是回调的返回值）
3. **抛异常自动回滚**，并且异常继续往外抛

```php
DB::transaction(function () use ($userId): void {
    DB::table('posts')->insert([...]);

    throw new \RuntimeException('故意失败');   // → 插入被回滚，异常传给调用方
});
```

::: warning 不要吞掉异常
```php
DB::transaction(function (): void {
    try {
        DB::table('t')->insert([...]);
        $this->doSomethingRisky();
    } catch (\Throwable $e) {
        Log::error('失败', ['e' => $e->getMessage()]);    // ❌ 吞了
    }
});
// 事务提交了，但 doSomethingRisky 失败了——数据不一致
```

要回滚就必须 `throw`。
:::

## 嵌套

内层用**保存点**（SAVEPOINT），不是新事务：

```php
DB::transaction(function (): void {          // 第 0 层：BEGIN
    DB::table('a')->insert([...]);

    try {
        DB::transaction(function (): void {  // 第 1 层：SAVEPOINT trans0
            DB::table('b')->insert([...]);
            throw new \RuntimeException('内层失败');
        });
    } catch (\RuntimeException) {
        // 内层回滚 → b 的插入被撤销，但 a 还在
    }

    DB::table('c')->insert([...]);
});
// 最终：a 和 c 提交，b 回滚
```

生成的 SQL 序列：

```sql
BEGIN
INSERT INTO a ...
SAVEPOINT trans0
INSERT INTO b ...
ROLLBACK TO SAVEPOINT trans0     -- 只撤销 b
INSERT INTO c ...
COMMIT
```

::: tip 什么时候用嵌套
**很少需要**。典型场景：一个"尽力而为"的子操作——失败了不影响主流程。

如果子操作失败应该导致整体回滚，直接抛异常就行，不用捕获。
:::

## 事务边界

**事务开在领域服务方法里**，不开在控制器里：

```php
// ✅ 领域服务自己管
final class OrderService
{
    public function create(array $items, int $userId): array
    {
        return DB::transaction(function () use ($items, $userId): array {
            // 扣库存 + 写订单 + 写明细
        });
    }
}

// ❌ 控制器管事务，服务不知道自己被包在事务里
DB::transaction(function () {
    $this->orders->create($items, $userId);   // 内部没有事务
    $this->notify->send($userId);             // 这里失败会回滚订单
});
```

第二种写法的隐患：别的调用方（CLI 命令、队列任务）直接调 `$orders->create()` 时**没有事务保护**。

## 并发

### 超卖：条件更新

```php
$affected = DB::table('products')
    ->where('id', $productId)
    ->where('stock', '>=', $quantity)
    ->decrement('stock', $quantity);

if ($affected === 0) {
    throw new OrderException('库存不足');
}
```

生成的 SQL：

```sql
UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?
```

**判空靠影响行数，不靠"先查再改"**：

```php
// ❌ 查和改之间有窗口
$stock = DB::table('products')->where('id', $id)->value('stock');
if ($stock >= $qty) {
    DB::table('products')->where('id', $id)->update(['stock' => $stock - $qty]);
}
```

两个请求同时读到 `stock = 1`，都判断"够"，都执行更新 → **卖了两件**。

xi-blog 实测：10 并发抢最后 1 件，只有 1 个成功，库存归零。

### 丢失更新：原子自增

```php
->increment('like_count')      // ✅ SET like_count = like_count + 1
```

```php
// ❌ 读-改-写
$count = DB::table('posts')->where('id', $id)->value('like_count');
DB::table('posts')->where('id', $id)->update(['like_count' => $count + 1]);
```

两个并发各读到 10，都写 11 → 实际应该 12。

### 乐观锁：版本号

```php
$affected = DB::table('posts')
    ->where('id', $id)
    ->where('version', $expectedVersion)
    ->update([
        'title' => $newTitle,
        'version' => $expectedVersion + 1,
    ]);

if ($affected === 0) {
    throw new HttpException(409, ErrorCode::VersionConflict->value, '内容已被他人修改');
}
```

适用于"编辑冲突"场景——比悲观锁轻，失败时让用户重新加载。

## 跨库注意：PostgreSQL 的事务中止

**这是一个真实的行为差异**：

| 数据库 | 事务内一条语句报错后 |
| --- | --- |
| MySQL | 该语句失败，**事务继续**，后续语句照常执行 |
| PostgreSQL | **整个事务进入 aborted 状态**，后续所有语句都失败，只能 ROLLBACK |

```php
DB::transaction(function (): void {
    DB::table('t')->insert(['id' => 1]);

    try {
        DB::table('t')->insert(['id' => 1]);   // 主键冲突
    } catch (QueryException $e) {
        // 两个库都能捕获到
    }

    DB::table('t')->insert(['id' => 2]);       // ← PG 上失败，MySQL 上成功
});
```

**实测输出**（同一段代码，两个库）：

```text
PostgreSQL
  捕获到：SQLSTATE[23505]: Unique violation
  后续插入失败：SQLSTATE[25P02]: In failed sql transaction

MySQL
  捕获到：SQLSTATE[23000]: Integrity constraint violation
  后续插入成功          ← 与 PG 不同
```

**应对**：

1. **不要在事务里捕获查询异常后继续操作**（两个库上都不是好习惯）
2. 需要"尽力而为"的子操作时，包一层 `transaction()`（保存点）——这样 PG 只回滚到保存点：

```php
try {
    DB::transaction(fn ($db) => DB::table('t')->insert(['id' => 1]));
} catch (QueryException) {
    // 保存点已回滚，外层事务仍可继续（PG 上也是）
}

DB::table('t')->insert(['id' => 2]);   // ✅ 两个库都能执行
```

## 隔离级别

框架用数据库默认值：

| 数据库 | 默认 |
| --- | --- |
| MySQL（InnoDB） | REPEATABLE READ |
| PostgreSQL | READ COMMITTED |
| SQLite | SERIALIZABLE |

::: warning 两者的默认不同
MySQL 的 REPEATABLE READ 意味着**同一事务内两次读同一行结果一样**（快照读）。
PostgreSQL 的 READ COMMITTED 则会读到其他事务已提交的新值。

如果业务依赖"事务内重复读一致"，两库行为不同——这种场景很少，但要知道。
:::

框架不提供设置隔离级别的 API。需要时直接执行 SQL：

```php
DB::affect('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
```

## 调试

```php
DB::transactionLevel();     // 当前嵌套层数，0 表示不在事务里
```

排查"为什么没回滚"时，先确认是不是真的在事务里执行。

## 常见错误

### 事务里没有回滚

三个原因：

1. 异常被 `catch` 后没有 `throw`
2. 事务开在控制器，而服务内部又开了独立事务
3. 用了 `try/catch` 包住但只打日志

### `There is no active transaction`

回滚次数比开始次数多。检查是否手动调了 `rollBack()` 又让框架回滚。

### PostgreSQL 报 `current transaction is aborted`

见上文「跨库注意」。在事务里捕获了查询异常又继续操作。

### 长事务拖慢数据库

事务里做了耗时操作（HTTP 请求、发邮件、图片处理）。这些应该在**事务外**做：

```php
$order = DB::transaction(fn ($db) => $this->createOrder($items, $userId));

// 事务已提交，再做慢操作
$this->mail->send(...);
$this->queue->push(new NotifyJob($order['id']));
```

## 下一步

- **[多数据库](./multi-database)** —— 三库方言差异全表
- **[领域服务](./domain-service)** —— 事务边界该画在哪
- **[队列](./queue)** —— 把慢操作移出请求
