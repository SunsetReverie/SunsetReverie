# 路由

## 一分钟看懂

| 项 | 说明 |
| --- | --- |
| **两种模式** | `single`（每域一个文件）/ `directory`（每域一个目录），**必须二选一** |
| **域前缀** | 框架自动加（读 `domains.{name}.prefix`） |
| **版本前缀** | 你的路由文件/目录决定 |
| **配错后果** | **启动即报错**，不会静默少加载 |
| **改 URL** | 用 `prefix` |
| **改路由名** | 用 `as`（不影响 URL） |

## 请求怎么变成路由

```text
POST /admin/v1/posts/42/publish
     ↓
① 域解析    按前缀 / Host / 请求头 → 认出这是 admin 域
     ↓       框架自动加：prefix=/admin、as=admin.、域中间件
② 版本分组  加 /v1（single 用 group，directory 用目录名）
     ↓
③ 路由匹配  在 admin 域里找 POST /posts/{id}/publish
     ↓
Pipeline（全局 → 域 → 路由中间件）
     ↓
PostController::publish(Request $request, int $id)
```

**关键分工**：域前缀框架加，版本前缀你加。框架不知道你的版本叫什么。

---

# 两种模式

## 配置

```php
// config/api.php
'routing_mode' => env('API_ROUTING_MODE', 'single'),   // single | directory
```

::: danger 只能二选一
同一个项目里，一个域**要么**是单文件，**要么**是目录。

混用时**启动直接抛异常**：

```text
routing_mode=single，但存在目录 routes/admin/。
两种组织方式只能二选一：删掉目录，或把 config/api.php 的 routing_mode 改为 directory。
```

因为「哪个文件生效」一旦变得不可预测，排查 404 会绕很远的路。
:::

## single 模式

```text
routes/
├── admin.php        → /admin/...
├── web.php          → /web/...
└── api.php          → 无域配置时用这个
```

文件里返回一个闭包：

```php
<?php

declare(strict_types=1);

use App\Api\Admin\Controller\PostController;
use SunsetReverie\Support\Facades\Route;

return function (): void {
    Route::group(['prefix' => 'v1', 'as' => 'v1.'], static function (): void {
        Route::get('/posts', [PostController::class, 'index'], 'post.index');
        Route::post('/posts', [PostController::class, 'store'], 'post.store');
    });
};
```

**必须 `return` 一个闭包**——框架靠这个判断文件是否有效。

::: tip 文件也可以拆
single 模式一样能拆，只是靠 `require`：

```php
return function (): void {
    Route::group(['prefix' => 'v1', 'as' => 'v1.'], static function (): void {
        require __DIR__ . '/admin/v1/posts.php';    // 拆出去的文件
    });
};
```

被 require 的文件里**不要再写 prefix**，否则路径变成 `/admin/v1/v1/posts`。
:::

## directory 模式

```text
routes/
└── admin/
    ├── health.php        → /admin/health
    ├── legacy.php        → /admin/legacy
    ├── v1/
    │   ├── posts.php     → /admin/v1/posts
    │   └── orders.php    → /admin/v1/orders
    └── v2/
        └── posts.php     → /admin/v2/posts
```

**文件里直接写路由**（不用 `return` 闭包）：

```php
<?php

declare(strict_types=1);

use App\Api\Admin\Controller\PostController;
use SunsetReverie\Support\Facades\Route;

// 目录名已经决定了 /admin 和 /v1，这里不要再写 prefix
Route::get('/posts', [PostController::class, 'index'], 'post.index');
Route::post('/posts', [PostController::class, 'store'], 'post.store');
```

### 三条规则

| 规则 | 说明 |
| --- | --- |
| **文件名不进 URL** | `posts.php` 里的 `/list` 就是 `/admin/list`，不是 `/admin/posts/list` |
| **目录最深两层** | `routes/admin/*.php` 或 `routes/admin/{版本}/*.php`，更深的**不扫描** |
| **版本目录必须在 `versions` 里声明** | 否则启动报错 |

### 文件名不进 URL 的后果

所有 `*.php` 共用 `/admin/` 这个命名空间，所以路由路径要自己保证不冲突：

```php
// routes/admin/posts.php
Route::get('/list', ...)      // → /admin/list

// routes/admin/orders.php
Route::get('/list', ...)      // → /admin/list  ← 冲突
```

会立刻报错（不是静默覆盖）：

```text
DuplicateRouteException: 路由重复注册：GET /admin/list
```

**建议给路径加资源前缀**：`/posts/list`、`/orders/list`。

### 版本目录自动加前缀

```php
// routes/admin/v1/posts.php
Route::get('/posts', ...)
// → URL:  /admin/v1/posts
// → 名字: admin.v1.posts
```

框架根据**目录名**自动加 `/v1` 和 `v1.`，文件里什么都不用做。

### 子文件禁止设 prefix，但允许 as

```php
// routes/admin/v1/posts.php

// ❌ 报错
Route::group(['prefix' => 'v1'], ...);
// RouteRegistrationException: directory 模式下不允许在路由文件里设置 prefix

// ✅ 允许：给路由名分类，URL 不变
Route::group(['as' => 'legacy.'], static function (): void {
    Route::get('/old-posts', ...);
    // → URL:  /admin/v1/old-posts   （不受 as 影响）
    // → 名字: admin.v1.legacy.old-posts
});
```

**为什么禁 prefix 却允许 as**：混乱来自**路径**，而 `as` 只影响名字（用于限流和日志），不会造成 URL 冲突。

### 加载顺序是字母序

```php
$files = glob($directory . '/*.php') ?: [];
sort($files, SORT_STRING);
```

字母序**可预测**，但有真实风险：

```php
// posts.php（字母序靠前）
Route::get('/posts/{slug}', ...)

// posts-new.php（字母序靠后）
Route::get('/posts/new', ...)      // ← 永远匹配不到，被 {slug} 吃掉
```

**对策**：动态参数一律加约束。

```php
Route::get('/posts/{slug}', ...)->where('slug', '[a-z0-9-]+');
```

---

# 定义域

```php
// config/api.php
'default' => 'web',
'allow_header_detection' => false,
'routing_mode' => 'single',

'domains' => [
    'admin' => [
        'prefix' => 'admin',                    // 可选，默认 = 域名
        'versions' => ['v1', 'v2'],             // directory 模式必填
        'middleware' => ['auth'],               // 域级中间件
        'guard' => 'jwt',
        'error_segment' => 51,                  // 业务错误码段位
        'hosts' => ['admin-api.example.com'],   // 可选：按 Host 识别
        'middleware_aliases' => [               // 域级别名，覆盖全局同名
            'auth' => \App\Api\Admin\Middleware\AdminAuth::class,
        ],
    ],
],
```

## 配置项逐个说

| 键 | 必须 | 默认 | 作用 |
| --- | --- | --- | --- |
| `prefix` | 否 | 域名本身 | URL 前缀，如 `admin` → `/admin/...` |
| `versions` | directory 必填 | `[]` | 声明有哪些版本目录 |
| `middleware` | 否 | `[]` | 域级中间件，所有路由都过 |
| `guard` | 否 | `null` | 鉴权方式标识 |
| `error_segment` | 否 | `null` | 业务码段位（`51` → `51001`） |
| `hosts` | 否 | `[]` | 按 Host 识别域 |
| `middleware_aliases` | 否 | `[]` | 域级中间件别名 |

::: warning 域级 `as` 不可配
路由名前缀**永远是域名**：

```php
'as' => $domain->name . '.',      // 硬编码
```

`prefix` 可以改（比如 `api-admin`），但路由名仍用域名（`admin.`）——
因为域名是合法的 PHP 标识符，而 `prefix` 可能带横杠。
:::

## 域的识别顺序

```text
1. 请求头 X-Api-Domain         （要求 allow_header_detection = true）
2. Host 匹配某个域的 hosts 列表
3. 路径第一段匹配某个域的 prefix
4. 都匹配不上 → 用 default 指定的域
```

`allow_header_detection` 默认 `false`。只有请求必经可信网关时才打开——
否则客户端可以改这个头冒充别的域。

---

# 启动校验

directory 模式和 single 模式各有校验，**全部在启动时执行**，报错信息带修正建议。

| # | 场景 | 报错 |
| --- | --- | --- |
| 1 | `single` 但存在目录 | `routing_mode=single，但存在目录 routes/admin/。……` |
| 2 | `directory` 但存在单文件 | `routing_mode=directory，但存在单文件 routes/admin.php。……` |
| 3 | `directory` 但缺目录 | `routing_mode=directory，但缺少目录 routes/admin/。……` |
| 4 | 版本目录未声明 | `routes/admin/v3/ 不是已声明的版本。请在 …… versions 里加上 [v3]` |
| 5 | 子文件里设 prefix | `directory 模式下不允许在路由文件里设置 prefix（当前为 [v1]）` |
| 6 | `routing_mode` 值非法 | `api.routing_mode 只能是 single 或 directory，当前为 [nested]` |

## 实测样例

```text
$ php bin/sr route:list

PHP Fatal error: Uncaught RouteRegistrationException:
  routes/admin/v3/ 不是已声明的版本。
  请在 config/api.php 的 domains.admin.versions 里加上 [v3]，或删掉这个目录。
  当前已声明：v1, v2
```

**报错信息里写清了「改哪里、怎么改」**——不需要再翻文档。

## 从 single 迁移到 directory

```bash
# 1. 改配置
#    config/api.php: 'routing_mode' => 'directory'

# 2. 把目录建出来
mkdir -p routes/admin/v1

# 3. 拆文件：routes/admin.php → routes/admin/v1/*.php
#    注意子文件里删掉 group(['prefix' => 'v1'])

# 4. 删掉原单文件（否则校验会报「存在单文件」）
rm routes/admin.php

# 5. 验证
php bin/sr route:list
```

第 4 步容易漏——**框架不会替你删**，因为删除用户文件是不可逆操作。

---

# 路由名（as）

## 名字是怎么拼的

```text
admin.      +    v1.    +   post.index   =   admin.v1.post.index
(域级自动)        (as)       (第三参数)
```

## 实测对比

```text
GET  /admin/v1/posts    admin.post.index        ← 只设 prefix
GET  /admin/v2/posts    admin.v2.post.index     ← prefix 和 as 都设
```

**URL 完全对称，差别只在名字。**

## as 与 prefix 的区别

| | `prefix` | `as` |
| --- | --- | --- |
| 影响什么 | **URL 路径** | **路由名** |
| 谁用 | 客户端 | 框架内部（限流、日志） |
| 例子 | `/admin/v1/posts` | `admin.v1.post.index` |
| 只设它会怎样 | URL 多一段，名字不变 | 名字多一段，**URL 不变** |
| 必须成对？ | 不必须。成对写 `'v1'` ↔ `'v1.'` 更清楚 | |

## 路由名有什么用

不是摆设，两个地方真实使用。

### 1. 按名字配置限流

```php
// config/ratelimit.php
'routes' => [
    'admin.v1.auth.login' => ['max_attempts' => 5, 'decay_seconds' => 60],   // 精确
    'admin.v1.*' => ['max_attempts' => 120],                                // 通配
],
```

不设 `as` 的话名字是 `admin.post.index`，`admin.v1.*` **一条都匹配不到**。

### 2. 访问日志

```json
{"route": "admin.v1.post.index", "status": 200, "ms": 12}
```

出问题时能一眼看出是哪个接口。

## 不传名字会怎样

**没有名字**（`null`），`route:list` 显示 `-`。

::: warning 这是一次修复
旧实现会返回组前缀（`admin`、`admin.v1`），导致同一分组里所有未命名路由**完全重名**。

而路由名是限流配置的 key——重名会命中错误规则。现在没名字就是没名字。
:::

**建议总是写名字**，尤其是有按路由名限流的需求时。

---

# 路径参数

```php
Route::get('/posts/{id}', [PostController::class, 'show']);
Route::get('/posts/{slug}/comments/{commentId}', [CommentController::class, 'show']);
```

参数名要和控制器方法参数名**一致**（见[控制器](./controller)）。

## 约束

```php
->whereNumber('id')                      // 只匹配数字
->whereUuid('uuid')                      // UUID 格式
->where('slug', '[a-z0-9-]+')            // 自定义正则
->whereIn('type', ['hot', 'new', 'top']) // 枚举
```

::: danger 不加约束的后果
```php
Route::get('/posts/{slug}', ...);     // 先注册
Route::get('/posts/new', ...);        // 永远匹配不到
```

`/posts/new` 会被 `{slug}` 吃掉。加上约束：

```php
Route::get('/posts/{slug}', ...)->where('slug', '(?!new$)[a-z0-9-]+');
```
:::

## 参数会被 urldecode

```text
请求 /posts/%E4%B8%AD%E6%96%87
     ↓
控制器拿到 '中文'
```

v0.28.1 起的行为。代码里做二次 decode 会得到乱码，见[升级指南](./upgrade)。

---

# 中间件

执行顺序**从外到内**：

```text
全局（config/app.php）
  → 域级（domains.{name}.middleware）
    → 分组级
      → 路由级
```

```php
// 路由级
Route::get('/posts', ...)->middleware(['auth.optional']);

// 分组级 + 参数
Route::group(['middleware' => ['throttle:60,1']], static function (): void { ... });
```

内置别名（9 个）：

| 别名 | 作用 |
| --- | --- |
| `auth` / `auth.optional` | 要求登录 / 有就解析 |
| `throttle` | 限流（`throttle:次数,分钟`） |
| `cors` | CORS 预检与响应头 |
| `log` | 访问日志 |
| `domain` | 域解析（框架内部用） |
| `trace` | 请求 ID 注入 |
| `security` | 安全响应头 |
| `body.size` | 请求体大小限制（413） |

---

# 调试

```bash
php bin/sr route:list                  # 全部
php bin/sr route:list --domain=admin   # 按域过滤
php bin/sr route:list --json           # JSON
```

输出：

```text
方法    URI                  名称                 中间件
GET     /admin/ping          admin.ping           -
GET     /admin/v1/posts      admin.v1.posts       auth
GET     /admin/v2/posts      admin.v2.posts       auth
```

**排查 404 的第一步永远是看这里**——确认完整 URI 和你以为的是否一致。

---

# 路由缓存

```bash
php bin/sr route:cache      # 生成 storage/compiled/routes.php
php bin/sr route:clear      # 清除
```

::: warning 缓存生效时不再执行路由文件
改了 `routes/*` 必须重新 cache，否则线上跑的还是旧的。

症状：新加的路由 404，本地却好的。

开发环境（`APP_ENV=local`）自动忽略缓存。
:::

---

# 常见错误

## 404 但 `route:list` 里有

先数 URI 有几段：

```text
域 prefix + 版本 + 路由 URI
  /admin  +  /v1 + /posts  =  /admin/v1/posts
```

常见原因：

| 原因 | 表现 |
| --- | --- |
| single 下重复写 prefix | `/admin/v1/v1/posts` |
| directory 下子文件又加 prefix | 启动直接报错（不会静默） |
| 目录名不在 `versions` 里 | 启动报错 |

## 405 而不是 404

```text
HTTP/1.1 405 Method Not Allowed
Allow: GET, POST
```

路径匹配上了但方法不对。`Allow` 头是框架自动写的。

## 路由重复注册

```text
DuplicateRouteException: 路由重复注册：GET /admin/list
```

directory 模式下最容易出现——所有 `*.php` 共享 `/admin/` 命名空间。
给路径加资源前缀（`/posts/list`）能避免。

## 改了文件不生效

两层缓存：

```bash
php bin/sr route:clear      # 路由缓存
killall -USR2 php-fpm       # opcache
```

用 `php bin/sr serve` 时不需要。

## 在业务项目里 `lint:architecture` 报找不到文件

已修（v0.42）——架构检查现在用框架根而不是项目根。

---

# 下一步

- **[控制器](./controller)** —— 参数注入规则、返回值
- **[中间件](./middleware)** —— 自定义中间件
- **[请求与响应](./request-response)** —— 完整 API
