# 门面

门面是**静态调用**，内部转发到容器里的实例。

```php
use SunsetReverie\Support\Facades\DB;

DB::table('posts')->where('status', 1)->get();
// 等价于 $app->container()->get(Connection::class)->table('posts')...
```

## 7 个门面

| 门面 | 指向 | 什么时候用 |
| --- | --- | --- |
| `Auth` | `AuthManager` | 取登录用户、签发 token |
| `Cache` | `CacheManager` | 缓存读写 |
| `Config` | `Config` | 读配置 |
| `DB` | `Connection` | 数据库查询 |
| `Log` | `LogManager` | 写日志 |
| `Route` | `Router` | 注册路由 |
| `Validator` | `Validator` | 手动校验 |

::: warning 这四类**没有**门面
`Mail` / `Upload` / `Image` / `Queue`——它们需要配置，用构造函数注入：

```php
public function __construct(private readonly MailManager $mail)
{
}
```
:::

---

# Auth

```php
use SunsetReverie\Support\Facades\Auth;
```

| 方法 | 返回 | 说明 |
| --- | --- | --- |
| `id()` | `?string` | 用户 ID，未登录 `null` |
| `user()` | `?AuthenticatedUser` | 当前用户对象 |
| `check()` | `bool` | 是否已登录 |
| `guard(?string $name = null)` | `mixed` | 取守卫实例 |
| `jwt(?string $name = null)` | `JwtGuard` | JWT 守卫（签发 token 用） |

```php
Auth::id();                                  // "42" 或 null
(int) (Auth::id() ?? 0);                     // 0 表示游客

Auth::check();                               // true / false

Auth::jwt()->issue('42', ['name' => '夕梦']); // 签发
```

`AuthenticatedUser` 的属性与方法：

```php
$user = Auth::user();

$user->id;                    // "42"
$user->claim('name');         // 自定义声明
$user->claim('roles', []);    // 带默认值
$user->claims();              // 全部声明
```

**不含用户资料**——token 里只有 ID 和自定义声明。需要邮箱、昵称等要自己查库。

详见[认证](./auth)。

---

# Cache

```php
use SunsetReverie\Support\Facades\Cache;
```

| 方法 | 返回 | 说明 |
| --- | --- | --- |
| `get(string $key, mixed $default = null)` | `mixed` | 读 |
| `put(string $key, mixed $value, ?int $ttl = null)` | `bool` | 写，`$ttl` 秒（`0` = 永久） |
| `has(string $key)` | `bool` | 存在且未过期 |
| `forget(string $key)` | `bool` | 删 |
| `flush()` | `bool` | 清空 |
| `remember(string $key, ?int $ttl, Closure $callback)` | `mixed` | 未命中则执行回调并缓存 |
| `store(?string $name = null)` | `CacheStoreInterface` | 切换驱动 |

```php
Cache::put('user:42', $user, 300);
Cache::get('user:42');                   // null 表示未命中
Cache::get('user:42', []);               // 指定默认值

Cache::remember('stats', 600, fn () => $this->computeStats());   // 你自己的方法

Cache::store('redis')->put('k', 'v', 60);  // 指定驱动
```

**没列出来的方法**（`increment()`）也能用——门面是 `__callStatic` 转发，
注解没列全但功能都在。

```php
Cache::increment('page:views');    // 能用
```

详见[缓存](./cache)。

---

# Config

```php
use SunsetReverie\Support\Facades\Config;
```

| 方法 | 返回 | 说明 |
| --- | --- | --- |
| `get(string $key, mixed $default = null)` | `mixed` | 点号路径读 |
| `set(string $key, mixed $value)` | `void` | 运行时覆盖 |
| `has(string $key)` | `bool` | 配置项是否存在 |
| `all()` | `array` | 全部配置 |

```php
Config::get('app.debug');                    // true
Config::get('database.default', 'mysql');    // 带默认值
Config::get('api.domains.admin.prefix');     // 多级
```

::: warning `set()` 只在当前请求有效
```php
Config::set('app.debug', false);
```

不会写回文件，也不跨请求。测试时想覆盖配置，用这个很方便。
:::

**全局辅助函数** `config('app.debug')` 等价于 `Config::get('app.debug')`。

---

# DB

```php
use SunsetReverie\Support\Facades\DB;
```

| 方法 | 返回 | 说明 |
| --- | --- | --- |
| `table(string $table)` | `Builder` | 查询构造器 |
| `select(string $sql, array $bindings = [])` | `list<array>` | 原生查询，返回多行 |
| `selectOne(string $sql, array $bindings = [])` | `?array` | 返回单行 |
| `scalar(string $sql, array $bindings = [])` | `mixed` | 返回单个值 |
| `affect(string $sql, array $bindings = [])` | `int` | 执行写操作，返回影响行数 |
| `statement(string $sql, array $bindings = [])` | `int` | `affect()` 的别名 |
| `transaction(Closure $callback)` | `mixed` | 事务，返回回调的返回值 |

```php
DB::table('posts')->where('status', 1)->count();

DB::select('SELECT * FROM posts WHERE id > ?', [10]);
DB::selectOne('SELECT * FROM posts WHERE slug = ?', [$slug]);
DB::scalar('SELECT COUNT(*) FROM posts');

DB::affect('UPDATE posts SET views = 0');

DB::transaction(function (): void {
    DB::table('orders')->insert([...]);
    DB::table('order_items')->insertMany([...]);
});
```

`transaction()` 的闭包**不需要参数**——里面直接用 `DB::` 门面，指向的就是当前连接。

`table()` 返回的 `Builder` 有 42 个方法，见[数据库](./database)。

---

# Log

```php
use SunsetReverie\Support\Facades\Log;
```

| 方法 | 说明 |
| --- | --- |
| `debug()` `info()` `notice()` `warning()` | 各重要级 |
| `error()` `critical()` `alert()` `emergency()` | 各重要级 |
| `channel(?string $name = null)` | 切换通道，返回 `Logger` |
| `pushContext(array $context)` | 推入作用域上下文 |
| `popContext()` | 弹出 |

```php
Log::info('用户登录', ['user_id' => 42]);
Log::error('任务失败', ['error' => $e->getMessage()]);

Log::channel('stderr')->error('容器环境走 stderr');

Log::pushContext(['request_id' => $id]);
Log::info('开始处理');      // 自动带 request_id
Log::popContext();
```

注解里只列了 4 个级别，但**8 个都能用**（`notice` / `critical` / `alert` / `emergency`
靠 `__callStatic` 转发到 `Logger`）。

详见[日志](./log)。

---

# Route

```php
use SunsetReverie\Support\Facades\Route;
```

| 方法 | 返回 | 说明 |
| --- | --- | --- |
| `get() post() put() patch() delete()` | `Route` | 单方法路由 |
| `any()` | `Route` | 匹配所有方法 |
| `group(array $attributes, Closure $callback)` | `void` | 路由分组 |
| `routes()` | `list` | 全部路由（调试用） |

```php
Route::get('/posts', [PostController::class, 'index'], 'post.index');
Route::post('/posts', [PostController::class, 'store'], 'post.store');

Route::group(['prefix' => 'v1', 'as' => 'v1.'], static function (): void {
    Route::get('/posts', ...);
});
```

`group()` 支持的属性：

| 属性 | 作用 |
| --- | --- |
| `prefix` | URL 前缀（累加） |
| `as` | 路由名前缀（累加） |
| `middleware` | 中间件（累加） |

详见[路由](./routing)。

---

# Validator

```php
use SunsetReverie\Support\Facades\Validator;
```

| 方法 | 返回 | 说明 |
| --- | --- | --- |
| `validate(array $data, array $rules)` | `array` | 校验通过返回 `$data`，失败抛 `ValidationException` |
| `errors(array $data, array $rules)` | `array` | 只返回错误，不抛异常 |

```php
// 方式 1：抛异常（控制器里常用）
Validator::validate($data, [
    'email' => 'required|email',
    'age' => 'integer|between:1,120',
]);

// 方式 2：拿错误数组自己处理
$errors = Validator::errors($data, ['email' => 'required|email']);

if ($errors !== []) {
    // ['email' => ['邮箱不能为空']]
}
```

15 个规则见[校验](./validation)。

---

# 门面怎么工作

```php
public static function __callStatic(string $method, array $arguments): mixed
{
    $instance = static::resolve();

    if (!method_exists($instance, $method) && !method_exists($instance, '__call')) {
        throw new BadMethodCallException(sprintf(
            '%s 不支持 %s()；可用方法见 %s',
            static::class, $method, $instance::class
        ));
    }

    return $instance->{$method}(...$arguments);
}
```

每个门面只定义一个 `accessor()`（指向哪个类），其余靠 `__callStatic` 转发。

### 为什么检查 `method_exists` 而不是 `is_callable`

代码里有一句注释说明了：

> 不能用 `is_callable`——它对 `__call` 魔术方法返回 **false**。
> `CacheManager` / `LogManager` 正是靠 `__call` 转发到默认驱动/通道。

如果用 `is_callable` 判断，`Cache::increment()` 会被误判为「方法不存在」。

### 方法名写错时的报错

```php
Cache::putt('k', 'v');
// BadMethodCallException: SunsetReverie\Support\Facades\Cache 不支持 putt()；
//                         可用方法见 SunsetReverie\Cache\CacheManager
```

**指出是哪个类、哪个方法、该去看哪个实现**——比「Call to undefined method」清楚。

## 未引导时会明确报错

```php
DB::table('posts');
```

```text
RuntimeException: [SunsetReverie\Support\Facades\DB] 在应用引导完成前被调用：
请确认已执行 Application::boot()，或在配置文件中改用注入方式（配置文件在容器就绪前加载）
```

不是空指针，也不是「类找不到」——**报的是「还没启动」，并说明了原因**。

这也是为什么路由文件在容器绑定**之后**才加载（见 CHANGELOG v0.19）。

::: tip 配置文件里不能用门面
`config/*.php` 在容器就绪之前加载，所以里面不能写 `DB::` / `Cache::`。

需要动态配置时，在 `ServiceProvider` 里用 `Config::set()`。
:::

## 注解不全但能用

`@method` 注解主要给 IDE 补全用。有几个门面的注解没列全：

| 门面 | 注解缺的 |
| --- | --- |
| `Auth` | `id()` `user()` `check()` |
| `Cache` | `increment()` |
| `Log` | `notice()` `critical()` `alert()` `emergency()` |

**功能都在**（`__callStatic` 转发），只是 IDE 不会提示。

需要补全的话，在对应门面的类注释里加 `@method` 行即可——不影响运行。

---

# 什么时候不该用门面

| 场景 | 用什么 |
| --- | --- |
| 单元测试要替换依赖 | 构造函数注入（能传 mock） |
| 要操作非默认连接/驱动 | `DB::table()` 换成注入的 `Connection` |
| 组件没有门面（Mail/Upload/Image/Queue） | 构造函数注入 |

门面适合**业务代码**（写得短、读得快），注入适合**需要控制实例的场景**。

两者可以混用，同一份代码里不必统一——但一个类里最好只用一种，否则读者要确认「这个 `$this->db` 和 `DB::` 是不是同一个」。

---

# 下一步

- **[配置](./config)** —— 54 个配置键
- **[数据库](./database)** —— `Builder` 的 42 个方法
- **[请求与响应](./request-response)** —— `Request` / `Response` 的完整 API
