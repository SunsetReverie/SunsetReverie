# 路由

一个请求从 URL 变成「调用哪个控制器方法」，中间经过三层：

```text
POST /admin/v1/posts/42/publish
     ↓
① 域解析     按前缀 / Host / 请求头 → 认出这是 admin 域
     ↓
② 版本剥离   去掉 /v1 → 剩 /posts/42/publish
     ↓
③ 路由匹配   在 admin 域里找 POST /v1/posts/{id}/publish
     ↓
Pipeline（全局中间件 → 域中间件 → 路由中间件）
     ↓
PostController::publish(Request $request, int $id)
```

这一篇讲清楚这三层怎么配、什么时候用哪种、以及几个容易踩的点。

## 两种组织模式

`config/api.php` 里的 `routing_mode` 决定用哪种：

```php
'routing_mode' => env('API_ROUTING_MODE', 'single'),
```

| 模式 | 路由文件 | 适合 |
| --- | --- | --- |
| `single` | `routes/api.php` 一个文件 | 只有一个 API 分组 |
| `directory` | `routes/{domain}.php` 每域一个 | 多端并存（admin / web / app / miniapp） |

切换用**命令**，不要手改：

```bash
php bin/sr route:mode directory --dry-run   # 先看会改什么
php bin/sr route:mode directory             # 实际切换
```

::: tip 为什么用命令切换
`route:mode` 会校验「`domains` 是否已声明」——`directory` 模式必须至少有 2 个域，
否则切过去后没有任何路由文件被加载，所有请求 404。
手改配置绕过这个检查就会踩坑。
:::

## 定义域

多域模式下，每个域在 `config/api.php` 里声明：

```php
'default' => 'web',              // 未匹配到具体域时用哪个

'domains' => [
    'admin' => [
        'prefix' => 'admin',                    // 路径前缀
        'versions' => ['v1'],                   // 支持哪些版本
        'middleware' => ['auth'],               // 域级中间件（所有路由都过）
        'guard' => 'jwt',                       // 鉴权方式
        'error_segment' => 51,                  // 错误码段位（见下文）
        'hosts' => ['admin-api.example.com'],   // 可选：按 Host 识别
        'middleware_aliases' => [               // 可选：域级别名
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

**关于请求头检测**：默认关闭。只有在你确定请求必经可信网关（网关会覆盖这个头）时才打开——否则客户端可以随便改 `X-Api-Domain` 冒充其他域。

### error_segment 是什么

每个域可以分配一段错误码，避免跨域撞码：

```php
'admin' => ['error_segment' => 51],
```

于是 admin 域产生的错误是 `51001`、`51002`……，而 `web` 域是 `1001`、`1002`……

**为什么需要**：前端拿到 `30001` 时要知道是哪个域的问题。段位隔离后，看前两位就够了。

::: warning 段位一旦分配不要改
已经上线的前端可能硬编码了错误码判断逻辑。改段位等于改契约。
:::

## 注册路由

```php
use SunsetReverie\Support\Facades\Route;

return function (Route $router): void {
    Route::get('/posts', [PostController::class, 'index'], 'post.index');
    Route::post('/posts', [PostController::class, 'store'], 'post.store');
    Route::put('/posts/{id}', [PostController::class, 'update'], 'post.update');
    Route::delete('/posts/{id}', [PostController::class, 'destroy'], 'post.destroy');
};
```

五个方法：`get` `post` `put` `patch` `delete`，外加 `any`（匹配所有方法）。

第三个参数是**路由名**。它有两个用处：

- `sr route:list` 里能按名字查
- 生成 URL 时不用硬编码路径

### 重复注册会报错

```text
DuplicateRouteException: 路由重复注册 [GET] /web/v1/posts
```

**这是刻意的**：同名同路径的两条路由，后一条会静默覆盖前一条，排查时极难发现。宁可启动就报错。

## 分组

`group()` 支持四个属性，可以嵌套：

```php
Route::group(['prefix' => '/v1', 'as' => 'v1.'], function (Route $router): void {
    Route::group(['middleware' => ['auth']], function (Route $router): void {
        Route::get('/posts', [PostController::class, 'index'], 'post.index');
        // 最终路径：/v1/posts
        // 最终名字：v1.post.index
        // 中间件：auth
    });
});
```

| 属性 | 作用 | 是否累加 |
| --- | --- | --- |
| `prefix` | URL 前缀 | ✅ 嵌套时逐层累加 |
| `as` | 路由名前缀 | ✅ 累加（记得带结尾的 `.`） |
| `middleware` | 中间件 | ✅ 累加（外层先执行） |
| `domain` | 绑定到某个域 | 只在最外层生效 |

`prefix` 的拼接会自动处理多余的斜杠，`'/v1'` 和 `'v1'` 效果相同。

::: warning `as` 要自己带点
```php
'as' => 'v1'      // → v1post.index  ❌
'as' => 'v1.'     // → v1.post.index ✅
```

框架只做字符串拼接，不会帮你补分隔符。
:::

## 路径参数

```php
Route::get('/posts/{id}', [PostController::class, 'show']);
Route::get('/posts/{slug}/comments/{commentId}', [CommentController::class, 'show']);
```

参数名要和控制器方法的参数名**一致**（或用类型提示匹配，见[控制器](./controller)）。

### 约束

```php
Route::get('/posts/{id}', [PostController::class, 'show'])
    ->whereNumber('id');                    // 只匹配数字

Route::get('/files/{uuid}', [FileController::class, 'show'])
    ->whereUuid('uuid');                    // 只匹配 UUID

Route::get('/posts/{slug}', [PostController::class, 'show'])
    ->where('slug', '[a-z0-9-]+');          // 自定义正则

Route::get('/feed/{type}', [FeedController::class, 'index'])
    ->whereIn('type', ['hot', 'new', 'top']);   // 枚举
```

**不加约束的后果**：`/posts/abc` 会匹配到 `{id}`，然后控制器里 `(int) 'abc'` 变成 0，查出一个空结果——**看起来是「数据不存在」，实际是「路由太宽」**。

加了 `whereNumber('id')` 后，`/posts/abc` 直接 404，问题立刻暴露。

### 参数会被 urldecode

```text
请求 /posts/%E4%B8%AD%E6%96%87
     ↓
控制器拿到 '中文'（不是 '%E4%B8%AD%E6%96%87'）
```

这是 v0.28.1 的行为变更。如果你的代码里做了二次 decode，会得到乱码——见[升级指南](./upgrade#_3-路由参数自动-urldecode-0-28-1)。

## 中间件

三种绑定位置，执行顺序是**从外到内**：

```php
// 1) 全局（config/app.php 的 global_middleware）
// 2) 域级（config/api.php 的 domains.{name}.middleware）
// 3) 路由级
Route::get('/posts', [PostController::class, 'index'])
    ->middleware(['auth.optional']);

// 4) 分组级
Route::group(['middleware' => ['throttle:60,1']], function ($r): void { ... });
```

带参数的写法：

```php
->middleware(['throttle:300,1'])     // 300 次 / 1 分钟
->middleware(['auth', 'permission:posts.create'])
```

内置别名（可直接用）：

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

自定义别名在 `config/api.php` 的 `middleware_aliases` 里注册。

详见[中间件](./middleware)。

## 调试

```bash
# 全部路由
php bin/sr route:list

# 只看某个域
php bin/sr route:list --domain=admin

# JSON 格式（脚本处理用）
php bin/sr route:list --json
```

输出：

```text
方法    URI                        名称                  中间件
GET     /web/v1/posts              post.index            auth.optional
POST    /web/v1/posts/{id}/like    post.like             auth
```

## 路由缓存

生产环境可以缓存路由表，跳过每次请求的解析：

```bash
php bin/sr route:cache      # 生成缓存
php bin/sr route:clear      # 清除
```

::: warning 改了路由必须重新 cache
缓存文件在 `storage/compiled/routes.php`。

改了 `routes/*.php` 但没重新 `route:cache`，**线上跑的还是旧路由**——
症状是「新加的路由 404，但本地好的」。

开发环境不要开缓存（框架在 `APP_ENV=local` 时会自动忽略缓存）。
:::

## 常见错误

### 路由 404，但 `route:list` 里有

**先看完整路径**。多域模式下路径是三层拼接的：

```text
域 prefix  +  分组 prefix  +  路由 uri
   /admin   +      /v1      +   /posts     =  /admin/v1/posts
```

`Route::group(['prefix' => '/v1'])` + `Route::get('/posts')` 得到的不是 `/posts`，是 `/admin/v1/posts`。

### 405 而不是 404

```text
405 Method Not Allowed
```

说明**路径匹配上了但方法不对**。比如 `POST /admin/v1/posts/12` 匹配到了 `PUT /posts/{id}`。

405 响应会带 `Allow` 头，列出该路径支持的方法。

### 路由冲突：谁先注册谁生效

```php
Route::get('/posts/{slug}', ...);     // 先注册，'new' 会被它吃掉
Route::get('/posts/new', ...);        // 永远匹配不到
```

静态路由要在动态路由**之前**注册。或者给动态参数加约束：

```php
Route::get('/posts/{slug}', ...)->where('slug', '(?!new$)[a-z0-9-]+');
```

### 改完代码不生效

路由表有缓存，PHP-FPM 有 opcache：

```bash
php bin/sr route:clear
killall -USR2 php-fpm
```

用 `php bin/sr serve`（内置服务器）时不需要——每次请求都重新加载。

## 下一步

- **[控制器](./controller)** —— 参数怎么注入、返回值怎么变成 JSON
- **[中间件](./middleware)** —— 写自定义中间件、参数化、执行顺序
- **[请求与响应](./request-response)** —— `Request` / `Response` 的完整 API
