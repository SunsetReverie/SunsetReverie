# 领域服务

::: info 先说清楚一件事
`sr new` 生成的骨架里**没有** `app/Domain/` 目录——只有 `app/Api/{域}/Controller`。

这不是遗漏。**领域层是可选的**：三个接口的小项目直接写在控制器里完全够用。

这一篇讲的是**什么时候该拆**，以及拆了之后怎么写才不别扭。
:::

## 三层职责

| 层 | 位置 | 该写 | 不该写 |
| --- | --- | --- | --- |
| 接口层 | `app/Api/{域}/Controller` | 取参数、调服务、返回结果 | SQL、业务规则 |
| 领域层 | `app/Domain/{模块}` | 业务规则、事务、数据一致性 | `Request` / `Response` |
| 组装层 | `app/Provider` | 注册依赖、绑定接口到实现 | 任何业务逻辑 |

## 什么时候该拆

先说不该拆的情况：

```php
// 一个只有 CRUD 的接口，写在控制器里没问题
public function index(Request $request): array
{
    return DB::table('tags')
        ->orderBy('name')
        ->get();
}
```

**该拆的信号**（出现两条以上就该拆）：

| 信号 | 说明 |
| --- | --- |
| 同一个逻辑要在两处用 | API + CLI 命令、或两个域 |
| 涉及多个表且要保证一致 | 下单扣库存 + 写订单 |
| 有非平凡的业务规则 | 金额计算、状态流转、权限判断 |
| 控制器超过 80 行 | 说明它承担了不止"接参数" |

## 领域服务长什么样

```php
<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Domain\Order\Exception\OrderException;
use SunsetReverie\Support\Facades\DB;

final class OrderService
{
    private const STATUS_PENDING = 0;
    private const STATUS_PAID = 1;

    /**
     * 创建订单。
     *
     * @param list<array{product_id: int, quantity: int}> $items 商品项
     * @param int $userId 下单用户
     * @return array<string, mixed> 订单
     * @throws OrderException 库存不足或商品下架时抛出
     */
    public function create(array $items, int $userId): array
    {
        return DB::transaction(function () use ($items, $userId): array {
            $totalCents = 0;

            foreach ($items as $item) {
                /*
                 * 扣库存：条件更新 + 原子自减。
                 *
                 * 生成的 SQL 是 `UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?`。
                 * 影响行数为 0 就说明库存不够——**不需要「先查再判断」**，
                 * 那个查和改之间的时间窗口正是超卖的来源。
                 */
                $affected = DB::table('products')
                    ->where('id', $item['product_id'])
                    ->where('stock', '>=', $item['quantity'])
                    ->decrement('stock', $item['quantity']);

                if ($affected === 0) {
                    throw new OrderException('库存不足');
                }

                $totalCents += $this->priceOf($item['product_id']) * $item['quantity'];
            }

            $orderId = DB::table('orders')->insertGetId([
                'user_id' => $userId,
                'total_amount' => $this->formatCents($totalCents),
                'status' => self::STATUS_PENDING,
            ]);

            return $this->findById($orderId);
        });
    }
}
```

三个特征：

**1. 数据库走门面，不进构造函数**

```php
// 方法体里
DB::table('orders')->insert([...]);

// 而不是
public function __construct(private readonly Connection $db)
```

同理，`Cache::` / `Log::` / `Auth::` 都是门面，**不需要注入**。

真正需要注入的是「有状态、带配置的对象」——比如某个第三方 SDK 客户端。

**绝对不要**把 `Request` 注入进来。

::: tip 注入 `Connection` 也能用
`__construct(private readonly Connection $db)` 依然有效（容器绑定了它），
适合「测试时要换成内存库」这类场景。两种写法的取舍见[数据库](./database#两种写法都能用)。
:::

**2. 参数是原始类型，不是 HTTP 概念**

```php
public function create(array $items, int $userId)     // ✅
public function create(Request $request)              // ❌ 和 HTTP 绑死了
```

这样 CLI 命令、队列任务都能调它。

**3. 抛业务异常，不返回 HTTP 状态码**

```php
throw new OrderException('库存不足');      // ✅ 由上层决定用什么状态码
return Response::json([...], 422);        // ❌ 领域层不该知道 HTTP
```

## 事务边界

**事务开在领域服务方法里，不开在控制器里**。

```php
// ✅ 正确：业务单元自己管事务
public function create(array $items, int $userId): array
{
    return DB::transaction(function () use ($items, $userId): array {
        // 扣库存 + 写订单 + 写明细，要么全成要么全败
    });
}

// ❌ 错误：控制器管事务，服务不知道自己被包在事务里
DB::transaction(function () {
    $this->orders->create($items, $userId);      // 内部没有事务
    $this->notifications->send($userId);         // 这里失败会回滚订单
});
```

### 嵌套事务

`transaction()` 支持嵌套，内层用保存点（savepoint）：

```php
DB::transaction(function (): void {        // 外层
    $this->orders->create(...);                   // 内层有 transaction()，用保存点

    if (random_int(1, 2) === 1) {
        throw new \RuntimeException('故意失败');
    }
});
// 外层回滚时，内层插入的也会被撤销
```

**注意**：内层回滚只撤销自己那部分，外层的其他操作保留。

## 跨模块调用

领域服务之间可以直接依赖：

```php
final class OrderService
{
    public function __construct(
        private readonly PostService $posts,      // 别的领域服务（可自动解析）
    ) {
    }
}
```

但要**注意循环依赖**：

```text
OrderService 依赖 PostService
PostService 依赖 OrderService      ← 容器解析时报循环依赖
```

框架会抛：

```text
CircularDependencyException: 检测到循环依赖 [A → B → A]
```

### 拆循环的三种办法

| 办法 | 做法 |
| --- | --- |
| **抽公共部分** | 两者共同依赖的部分拆成第三个服务 |
| **事件解耦** | A 发事件，B 监听（需要事件系统，当前没有） |
| **依赖倒置** | 定义接口，一方依赖接口而非实现 |

最简单的是第一种。

## 注册

**大多数领域服务不需要注册**——只要构造函数参数都能自动解析，容器就能自己造出来。

用 `DB::` 门面之后，构造函数通常是空的：

```php
final class OrderService
{
    // 没有构造函数，或只收别的领域服务
    public function create(array $items, int $userId): array
    {
        return DB::transaction(function () use ($items, $userId): array {
            // ...
        });
    }
}
```

### 什么时候才要写 Provider

```php
// app/Provider/AppServiceProvider.php
$this->container->singleton(OrderService::class, static fn (Container $c): OrderService
    => new OrderService(
        $c->get(OrderRepository::class),
        $c->get(Config::class)->get('order.timeout'),   // ← 标量，自动解析不了
    ));
```

| 情况 | 要注册 |
| --- | --- |
| 构造函数参数全是可自动解析的类 | ❌ |
| 有标量参数（`int $timeout`、`string $apiKey`） | ✅ |
| 要把接口绑到实现 | ✅ |
| 要控制生命周期 | ✅ |

不注册且解析不了的后果：

```text
Target [App\Domain\Order\OrderService] is not instantiable
```

### 用 singleton 还是 bind

```php
$this->container->singleton(A::class, ...);   // 整个请求共用一个实例
$this->container->bind(A::class, ...);        // 每次解析新建
```

**领域服务用 `singleton`**：它们无状态（状态都在数据库），复用没副作用。

## 一个真实的坑

金额用 `float` 会因为 IEEE 754 精度问题丢分：

```php
0.1 + 0.2 === 0.30000000000000004
```

所以 xi-blog 的 `OrderService` 里金额**全部用整数「分」**计算，只在入库时转成 `DECIMAL` 字符串：

```php
private function toCents(string $amount): int
{
    // "19.99" → 1999
}

private function formatCents(int $cents): string
{
    // 1999 → "19.99"
}
```

这条不在框架层做（框架只是查询构造器），但**写在领域服务里是必须的**。

## 常见错误

### `Target [...Service] is not instantiable`

没在 Provider 注册，或者构造函数有标量参数。

### 循环依赖

```
CircularDependencyException
```

看两个服务是不是互相依赖了。拆公共部分出来。

### 事务没回滚

- 事务开在控制器里，而服务内部又开了事务（成了两次独立事务）
- 或者异常被吞了（`catch` 后没有 `throw`）

### 控制器里出现 `select * from`

说明业务逻辑泄漏到了接口层。挪到领域服务。

## 下一步

- **[错误处理](./error-handling)** —— 异常体系、39 条错误码
