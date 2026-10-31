# 缓存

## 四个驱动

| 驱动 | 存储位置 | 跨进程 | 跨机器 | 适合 |
| --- | --- | --- | --- | --- |
| `array` | PHP 内存 | ❌ | ❌ | **测试**（每次请求都是空的） |
| `file` | `storage/cache/` | ✅ | ❌ | 单机部署 |
| `apcu` | 共享内存 | ✅ | ❌ | 单机、零外部依赖 |
| `redis` | Redis 服务 | ✅ | ✅ | 多机部署 |

::: warning `array` 驱动只活一个请求
`Cache::put('k', 'v')` 然后 `Cache::get('k')` 在同一请求内有值，
**下一个请求就没了**。

它只用于测试——把缓存依赖从测试里摘掉，避免测试之间互相污染。
:::

## 配置

```php
// config/cache.php
'default' => env('CACHE_DRIVER', 'file'),
'prefix' => env('CACHE_PREFIX', 'sr:'),      // 键前缀
'ttl' => (int) env('CACHE_TTL', 3600),       // 默认存活秒数（0 = 永久）

'stores' => [
    'array' => ['driver' => 'array'],
    'file' => ['driver' => 'file', 'path' => ...],
    'apcu' => ['driver' => 'apcu'],
    'redis' => [
        'driver' => 'redis',
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => (int) env('REDIS_PORT', 6379),
        'password' => env('REDIS_PASSWORD', ''),
        'database' => (int) env('REDIS_DB', 0),
        'timeout' => (float) env('REDIS_TIMEOUT', 1.5),   // 秒
    ],
],
```

### 关于 `timeout`

```php
'timeout' => 1.5
```

Redis 不可达时**最多等 1.5 秒**，不会无界阻塞。

这个值不要设太大——缓存挂了应该是「快速失败、降级到数据库」，而不是把请求拖死。

### 关于 `prefix`

```php
'prefix' => 'sr:'
```

同一台 Redis / APCu 上跑多个项目时，靠前缀隔离。

**Redis 驱动下 `flush()` 只删前缀匹配的键**，不会清空整个库——这一点很重要，见下文。

## 基本操作

```php
use SunsetReverie\Support\Facades\Cache;

Cache::put('user:42', $user, 300);      // 300 秒后过期
Cache::put('config', $config);          // 用默认 TTL
Cache::put('permanent', $data, 0);      // 永不过期

Cache::get('user:42');                  // 命中返回值，未命中返回 null
Cache::get('user:42', []);              // 指定默认值

Cache::has('user:42');                  // bool
Cache::forget('user:42');               // bool
Cache::flush();                         // 清空（谨慎！）
Cache::increment('page:views');         // 原子自增
Cache::increment('page:views', 5);
```

`put()` 返回 `bool`——存储失败（磁盘满、Redis 拒绝）时是 `false`，不是抛异常。

## `remember()`：最常用的模式

```php
$stats = Cache::remember('stats:home', 600, function (): array {
    return DB::table('posts')->where('status', 1)->count();
});
```

逻辑：命中就返回缓存，未命中执行闭包并写入。

### 它用哨兵值处理 null

```php
private const MISS_SENTINEL = "\0__sr_cache_miss__\0";

$cached = $this->get($key, self::MISS_SENTINEL);

if ($cached !== self::MISS_SENTINEL) {
    return $cached;
}
```

**为什么不直接判断 `null`**：因为缓存里可能真的存了 `null`。

```php
// 如果数据库查不到，闭包返回 null，会被缓存
Cache::remember('user:999', 60, fn () => DB::table('users')->find(999));
// → 首次返回 null，并缓存 null

// 下次命中时
Cache::get('user:999');   // null —— 但这是「命中」还是「未命中」？
```

用哨兵值区分开，`null` 也会被正常缓存（这是对的：查不到也是结果，应该缓存住避免每次都打数据库）。

### 但 `remember()` 不防击穿

**并发时多个请求会同时回源。**

```text
缓存过期的瞬间
  ├─ 请求 A：未命中 → 查数据库 → 写入
  ├─ 请求 B：未命中 → 查数据库 → 写入
  └─ 请求 C：未命中 → 查数据库 → 写入
```

框架没有加锁。如果你的闭包很重（比如聚合几万行），高并发下会同时打数据库。

**缓解办法**：

| 办法 | 做法 |
| --- | --- |
| 加随机 TTL | `300 + random_int(0, 60)`，避免同时过期 |
| 应用层锁 | 用 `Cache::put('lock:stats', 1, 5)` 抢锁，抢不到就返回旧值 |
| 接受它 | 查询本身轻（有索引、几十毫秒），并发重复几次无所谓 |

文档不提供现成的锁实现——**加锁的复杂度往往超过收益**，先测一下再决定。

## 多 store 切换

```php
Cache::store('redis')->put('k', 'v', 60);
Cache::store('array')->put('k', 'v');       // 测试用
```

不传参数用 `default` 指定的驱动。

配置了但没用的 store 不会被实例化（懒加载）。

## 已知的坑

### `flush()` 会清掉所有缓存

```php
Cache::flush();   // 全部清空
```

**没有「按前缀批量删」的 API**。如果你只想清某一类：

```php
// 只能逐个删
foreach (['stats:home', 'stats:posts', 'stats:users'] as $key) {
    Cache::forget($key);
}
```

或者给这类缓存换一个 store，清的时候只 flush 那个 store。

### 别再问「改了数据为什么页面没变」

缓存没主动失效。要么：

```php
Cache::forget('post:' . $postId);
```

要么接受 TTL 内的延迟。

### `file` 驱动比 `redis` 还慢

实测（1000 次 put）：

| 驱动 | 耗时 | 相对 |
| --- | --- | --- |
| `array` | 0.4–0.6 ms | 1× |
| `redis` | 109–111 ms | 约 200–270× |
| `file` | 172–174 ms | 约 300–430× |

反直觉但合理：`file` 每次 `put` 要 `fopen` + `fwrite` + `rename`（原子写），
而 Redis 在回环上只是一次 TCP 往返——**本机上 TCP 比 fsync 快**。

所以：单机已经用了 `file`，别指望换 `redis` 能提速**单次访问**；
`redis` 的价值是**多机共享**。

### `apcu` 在 CLI 下不跨进程

`php bin/sr` 跑的命令和 FPM 请求**不共享** APCu 内存段。

所以「命令行预热缓存、Web 端读取」这个思路在 `apcu` 上不成立。
`file` / `redis` 可以。

## 自定义驱动

```php
Cache::extend('custom', function (array $config): CacheStoreInterface {
    return new MyStore($config);
});
```

`extend()` 只**注册工厂**（返回 `self` 可链式），驱动实例在第一次
`Cache::store('custom')` 时才创建。同一个名字注册两次会抛
`CacheException: 缓存驱动 [x] 已注册，禁止静默覆盖`。

实现 `CacheStoreInterface` 六个方法：

```php
interface CacheStoreInterface
{
    public function get(string $key, mixed $default = null): mixed;
    public function put(string $key, mixed $value, ?int $ttl = null): bool;
    public function has(string $key): bool;
    public function forget(string $key): bool;
    public function flush(): bool;
    public function increment(string $key, int $step = 1): int;
}
```

`remember()` 由 `RememberTrait` 提供（基于上面几个方法），不用自己实现。

## 常见错误

### `缓存驱动未配置`

```text
CacheException: 缓存驱动 [redis] 未在 config/cache.php 中配置
```

v0.23 起**必须显式配置**，不再有隐式默认。检查 `stores` 里有没有这个 key。

### `Redis 连接失败`

先确认服务在跑：

```bash
redis-cli ping
```

再看 `config/cache.php` 的 host / port / password / database。

### 缓存里存了对象，读出来变成 `__PHP_Incomplete_Class`

序列化时类还没加载。存对象前确保类已定义，或者只存数组/标量。

**建议只缓存数组**——对象序列化后跨版本容易失效。

### `increment` 在 `file` 驱动上不是原子的

`file` 驱动的 `increment` 是「读-改-写」，高并发下会丢计数。

`redis` 用 `INCRBY`，原子。

**计数场景用 `redis`**。

## 下一步

- **队列（组件章节，待补）** —— 把重活挪出请求
- **限流（组件章节，待补）** —— 基于缓存的滑动窗口
- **日志（组件章节，待补）** —— 记录缓存命中率
