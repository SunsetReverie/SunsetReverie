# 路由

一个请求从 URL 变成「调用哪个控制器方法」，中间经过三层：

```text
POST /admin/v1/posts/42/publish
     ↓
① 域解析     按前缀 / Host / 请求头 → 认出这是 admin 域
     ↓        框架自动加上：prefix=/admin、as=admin.、域中间件
② 版本分组   group(['prefix' => 'v1']) → 去掉 /v1
     ↓        （这一层是你的路由文件自己加的）
③ 路由匹配   在 admin 域里找 POST /posts/{id}/publish
     ↓
Pipeline（全局中间件 → 域中间件 → 路由中间件）
     ↓
PostController::publish(Request $request, int $id)
```

**关键分工**：域前缀由框架加，**版本前缀由你加**——框架不知道你的版本长什么样。

## 框架只加载一个文件

这是理解整套路由的前提：

```php
// core/Foundation/Application.php
private function registerDomainRoutes(): void
{
    foreach ($this->domains->all() as $domain) {
        $file = $this->basePath('routes/' . $domain->name . '.php');   // ← 只认这个
        ...
    }
}
```

**多域模式下，框架只 require `routes/{域名}.php` 这一个文件。**

它不会扫描目录、不会自动发现子文件。**所有目录结构都是你自己组织的。**

单域模式则只加载 `routes/api.php`。

## 两种模式

```php
// config/api.php
'routing_mode' => env('API_ROUTING_MODE', 'single'),
```

| 模式 | 加载的文件 | 适合 |
| --- | --- | --- |
| `single` | `routes/api.php`（无域时）或 `routes/{domain}.php` | 文件不大时 |
| `directory` | **同上** | 文件需要拆成目录时 |

::: warning `routing_mode` 只改配置，不改变加载行为
这是最容易误解的一点。

`php bin/sr route:mode directory` 做的事**只是把 config 里的字符串从 `single` 改成 `directory`**：

```php
preg_replace("/'routing_mode'\s*=>\s*'[a-z]+'/", "'routing_mode' => 'directory'", $content)
```

**运行时没有任何代码读取 `routing_mode`**（可以自己 `grep -rn routing_mode core/ components/` 验证——只有 ConsoleKernel 的 `route:mode` 命令用）。

所以这个配置项的实际作用：**标记这个项目打算怎么组织路由**，供人和工具看。
真正决定「加载哪些文件」的，永远是 `routes/{domain}.php` 里你写了什么。
:::

## 定义域

```php
// config/api.php
'default' => 'web',              // 未匹配到具体域时用哪个
'allow_header_detection' => false,

'domains' => [
    'admin' => [
        'prefix' => 'admin',                    // 路径前缀
        'versions' => ['v1'],                   // 支持的版本（供 route:list / 文档用）
        'middleware' => ['auth'],               // 域级中间件，所有路由都过
        'guard' => 'jwt',
        'error_segment' => 51,                  // 错误码段位
        'hosts' => ['admin-api.example.com'],   // 可选：按 Host 识别
        'middleware_aliases' => [               // 域级别名，覆盖全局同名
            'auth' => \App\Api\Admin\Middleware\AdminAuth::class,
        ],
    ],

    'web' => [
        'prefix' => 'web',
        'versions' => ['v1'],
    ],
],
```

### 域的识别顺序

```text
1. 请求头 X-Api-Domain         （要求 allow_header_detection = true）
2. Host 匹配某个域的 hosts 列表
3. 路径第一段匹配某个域的 prefix
4. 都匹配不上 → 用 default 指定的域
```

`allow_header_detection` 默认 `false`。只有请求必经可信网关（网关会覆盖这个头）时才打开——否则客户端可以随便改 `X-Api-Domain` 冒充别的域。

### 域前缀与版本前缀的归属

| 前缀 | 谁加 | 例子 |
| --- | --- | --- |
| 域前缀 | **框架**（读 `domains.{name}.prefix`） | `/admin` |
| 域名前缀 | **框架**（`as` = `{域名}.`） | `admin.` |
| 域中间件 | **框架** | `auth` |
| **版本前缀** | **你的路由文件** | `/v1` |
| 版本名前缀 | **你的路由文件** | `v1.` |
| 路由 URI | 你 | `/posts` |

## 注册路由

```php
use SunsetReverie\Support\Facades\Route;

return function (): void {
    Route::get('/posts', [PostController::class, 'index'], 'post.index');
    Route::post('/posts', [PostController::class, 'store'], 'post.store');
    Route::put('/posts/{id}', [PostController::class, 'update'], 'post.update');
    Route::delete('/posts/{id}', [PostController::class, 'destroy'], 'post.destroy');
};
```

六个方法：

| 方法 | 说明 |
| --- | --- |
| `get` `post` `put` `patch` `delete` | 单方法 |
| `any` | 匹配所有方法 |

**第三个参数是路由名**。它是可选的，但强烈建议写——`route:list` 里能按名查，生成 URL 时不用硬编码路径。

### 路由名怎么拼出来的

```text
框架加的域名前缀  +  分组 as  +  路由名
      admin.      +    v1.    +  post.index   =   admin.v1.post.index
```

实测（临时项目）：

```text
GET  /admin/v1/posts        admin.v1.post.index
GET  /admin/v1/posts/{id}   admin.v1.post.show
GET  /admin/v2/posts        admin.v2.post.index
```

### 重复注册会直接报错

```text
DuplicateRouteException: 路由重复注册 [GET] /web/v1/posts
```

这是刻意的：同名同路径的两条路由，后一条会静默覆盖前一条，排查时极难发现。宁可启动就报错。

## 分组

`group()` 支持四个属性，可以任意嵌套：

```php
Route::group(['prefix' => 'v1', 'as' => 'v1.'], static function (): void {
    Route::group(['middleware' => ['auth']], static function (): void {
        Route::get('/posts', [PostController::class, 'index'], 'post.index');
        // 最终路径：/admin/v1/posts
        // 最终名字：admin.v1.post.index
        // 中间件：域中间件 + auth
    });
});
```

| 属性 | 作用 | 嵌套时 |
| --- | --- | --- |
| `prefix` | URL 前缀 | **累加** |
| `as` | 路由名前缀 | **累加**（记得带结尾的 `.`） |
| `middleware` | 中间件 | **累加**（外层先执行） |
| `domain` | 绑定到某个域对象 | 只在最外层生效（框架内部用） |

`prefix` 拼接会自动处理多余斜杠，`'v1'` 和 `'/v1'` 效果相同。

::: warning `as` 要自己带点
```php
'as' => 'v1'      // → v1post.index   ❌
'as' => 'v1.'     // → v1.post.index  ✅
```

框架只做字符串拼接，不会补分隔符。
:::

## 拆分成目录（重点）

路由文件写长了会很难维护。框架不会自动扫描目录，但**你可以自己在入口文件里 require**。

### 推荐结构

```text
routes/
├── admin.php                ← 框架只加载这个（域入口）
├── web.php
├── admin/
│   ├── v1/
│   │   ├── auth.php
│   │   ├── posts.php
│   │   ├── orders.php
│   │   └── rbac.php
│   └── v2/
│       └── posts.php
└── shared/
    └── health.php           ← 多个域共用的路由
```

### 入口文件

```php
<?php

declare(strict_types=1);

use SunsetReverie\Support\Facades\Route;

/*
 * admin 域入口。
 *
 * 域前缀 /admin 与域名前缀 admin. 由框架自动套用，
 * 这里只写版本分组与文件加载。
 */
return function (): void {
    Route::group(['prefix' => 'v1', 'as' => 'v1.'], static function (): void {
        // __DIR__ 指向 routes/，不需要 base_path()（框架没这个辅助函数）
        require __DIR__ . '/admin/v1/auth.php';
        require __DIR__ . '/admin/v1/posts.php';
        require __DIR__ . '/admin/v1/orders.php';
    });

    Route::group(['prefix' => 'v2', 'as' => 'v2.'], static function (): void {
        require __DIR__ . '/admin/v2/posts.php';
    });
};
```

### 被 require 的文件

```php
<?php

declare(strict_types=1);

use App\Api\Admin\Controller\PostController;
use SunsetReverie\Support\Facades\Route;

/*
 * 注意：外层 group 已经加了 /admin 和 /v1，
 * 这里**不要再写 prefix**，否则路径会变成 /admin/v1/v1/posts。
 *
 * 路由名同理：外层 as 已有 'admin.v1.'，这里只写 'post.index'。
 */

Route::get('/posts', [PostController::class, 'index'], 'post.index');
Route::post('/posts', [PostController::class, 'store'], 'post.store');
Route::get('/posts/{id}', [PostController::class, 'show'], 'post.show')
    ->whereNumber('id');
Route::put('/posts/{id}', [PostController::class, 'update'], 'post.update')
    ->whereNumber('id');
Route::delete('/posts/{id}', [PostController::class, 'destroy'], 'post.destroy')
    ->whereNumber('id');
```

::: danger 重复加 prefix 的后果
实测：外层 `group(['prefix' => 'v1'])` + 文件内又写 `group(['prefix' => 'v1'])`

```text
GET  /admin/v1/v1/posts      ← 多了一层
```

**症状**：请求 `/admin/v1/posts` 返回 404，但 `route:list` 里明明有这条路由。

排查方法：`php bin/sr route:list | grep posts`，看完整 URI 有几段。
:::

### 按资源拆 vs 按版本拆

| 拆法 | 结构 | 适合 |
| --- | --- | --- |
| **按资源**（推荐） | `v1/posts.php` `v1/orders.php` | 大多数项目 |
| 按版本 | `v1.php`（内含多个资源） | 版本间差异大、需要整体替换 |
| 按域内模块 | `v1/admin/posts.php` | 单域内还有大分支 |

**推荐按资源拆**：改动集中在某个资源时，只动一个文件。

### 需要缓存时注意 require 的路径

路由缓存会把**所有路由序列化**存到 `storage/compiled/routes.php`，不再执行 `routes/*.php`。

改了拆分的文件后必须重新缓存：

```bash
php bin/sr route:cache
```

否则线上跑的还是旧的。

## 路径参数

```php
Route::get('/posts/{id}', [PostController::class, 'show']);
Route::get('/posts/{slug}/comments/{commentId}', [CommentController::class, 'show']);
```

参数名要和控制器方法参数名**一致**（见[控制器](./controller)）。

### 约束

```php
Route::get('/posts/{id}', [PostController::class, 'show'])->whereNumber('id');
Route::get('/files/{uuid}', [FileController::class, 'show'])->whereUuid('uuid');
Route::get('/posts/{slug}', [PostController::class, 'show'])->where('slug', '[a-z0-9-]+');
Route::get('/feed/{type}', [FeedController::class, 'index'])->whereIn('type', ['hot', 'new', 'top']);
```

| 方法 | 说明 |
| --- | --- |
| `where($name, $regex)` | 自定义正则 |
| `whereNumber($name)` | 只匹配数字 |
| `whereUuid($name)` | UUID 格式 |
| `whereIn($name, $values)` | 枚举 |

**不加约束的后果**：`/posts/abc` 匹配到 `{id}`，控制器里 `(int) 'abc'` 变成 0，查出空结果——看起来是「数据不存在」，实际是「路由太宽」。

### 参数会被 urldecode

```text
请求 /posts/%E4%B8%AD%E6%96%87
     ↓
控制器拿到 '中文'
```

这是 v0.28.1 的行为变更。代码里做二次 decode 会得到乱码，见[升级指南](./upgrade)。

## 中间件

四种绑定位置，执行顺序**从外到内**：

```text
全局（config/app.php 的 global_middleware）
  → 域级（config/api.php 的 domains.{name}.middleware）
    → 分组级
      → 路由级
```

```php
// 路由级
Route::get('/posts', [PostController::class, 'index'])->middleware(['auth.optional']);

// 分组级 + 带参数
Route::group(['middleware' => ['throttle:60,1']], static function (): void { ... });
```

内置别名（9 个，可直接用）：

| 别名 | 作用 |
| --- | --- |
| `auth` | 要求登录，未登录 401 |
| `auth.optional` | 有 token 就解析，没有也放行 |
| `throttle` | 限流（`throttle:次数,分钟`） |
| `cors` | CORS 预检与响应头 |
| `log` | 访问日志 |
| `domain` | 域解析（框架内部用） |
| `trace` | 请求 ID 注入 |
| `security` | 安全响应头 |
| `body.size` | 请求体大小限制（超限 413） |

自定义别名在 `config/api.php` 的 `middleware_aliases` 里注册，详见[中间件](./middleware)。

## 调试

```bash
php bin/sr route:list                  # 全部路由
php bin/sr route:list --domain=admin   # 只看某个域
php bin/sr route:list --json           # JSON（脚本处理用）
```

输出：

```text
方法    URI                        名称                  中间件
GET     /web/v1/posts              admin.v1.post.index   auth.optional
POST    /web/v1/posts/{id}/like    admin.v2.post.store   auth
```

**排查 404 的第一步永远是看这里**——确认完整 URI 和你以为的是否一致。

## 路由缓存

```bash
php bin/sr route:cache      # 生成 storage/compiled/routes.php
php bin/sr route:clear      # 清除
```

::: warning 改了路由必须重新 cache
缓存生效时框架**不再执行 `routes/*.php`**。

改了但没重新 cache，线上跑的还是旧路由——症状是「新加的路由 404，本地却好的」。

开发环境（`APP_ENV=local`）会自动忽略缓存。
:::

## 常见错误

### 路由 404，但 `route:list` 里有

**先看完整 URI 有几段**。多域模式下路径是三层拼接：

```text
域 prefix  +  分组 prefix  +  路由 uri
   /admin   +      /v1      +   /posts     =  /admin/v1/posts
```

拆文件时最常见的是**重复加 prefix**（见上文）。

### 405 而不是 404

```text
HTTP/1.1 405 Method Not Allowed
Allow: GET, POST
```

路径匹配上了但方法不对。405 响应会带 `Allow` 头列出支持的方法（这个头是框架自动写的）。

### 路由冲突：谁先注册谁生效

```php
Route::get('/posts/{slug}', ...);     // 先注册，'new' 会被它吃掉
Route::get('/posts/new', ...);        // 永远匹配不到
```

静态路由要在动态路由**之前**注册，或者给动态参数加约束：

```php
Route::get('/posts/{slug}', ...)->where('slug', '(?!new$)[a-z0-9-]+');
```

### 拆分的文件改动不生效

两个缓存层：

```bash
php bin/sr route:clear      # 路由缓存
killall -USR2 php-fpm       # opcache
```

用 `php bin/sr serve` 时不需要——每次请求都重新加载。

### `require` 路径写错

```php
require __DIR__ . '/admin/v1/posts.php';    // ✅ __DIR__ 是 routes/ 的绝对路径
require 'routes/admin/v1/posts.php';        // ❌ 相对当前工作目录，从别处调用就找不到
```

框架**没有 `base_path()` 辅助函数**（只有 `config()` `env()` `logger()`），用 `__DIR__`。

## 下一步

- **[控制器](./controller)** —— 参数怎么注入、返回值怎么变成 JSON
- **[中间件](./middleware)** —— 写自定义中间件、参数化、执行顺序
- **[请求与响应](./request-response)** —— `Request` / `Response` 的完整 API
