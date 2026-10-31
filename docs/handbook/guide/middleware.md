# 中间件

中间件在请求进控制器**之前**、响应返回**之后**执行。

```text
请求 → 中间件 A → 中间件 B → 中间件 C → 控制器
                                          ↓
响应 ← 中间件 A ← 中间件 B ← 中间件 C ← 返回
```

这是"洋葱模型"：**注册顺序 = 进入顺序，退出顺序相反**。

## 三层绑定

```php
// 1) 全局：所有请求都过（config/app.php 的 global_middleware）
// 2) 域级：config/api.php 的 domains.{name}.middleware
// 3) 路由级
Route::get('/posts', [PostController::class, 'index'])
    ->middleware(['auth.optional']);

// 4) 分组级
Route::group(['middleware' => ['throttle:60,1']], function ($r): void {
    // ...
});
```

**执行顺序**：

```text
全局 → 域级 → 分组级 → 路由级 → 控制器
```

越外层越早执行。所以全局的 `TraceRequest` 一定在最前面（它要给后续所有日志打上 request_id）。

## 内置中间件

9 个，都有别名可直接用：

| 别名 | 类 | 作用 |
| --- | --- | --- |
| `trace` | `TraceRequest` | 注入 request_id |
| `cors` | `HandleCors` | 预检短路 + CORS 响应头 |
| `security` | `SecurityHeaders` | X-Frame-Options、CSP 等 |
| `domain` | `ResolveDomain` | 解析当前请求属于哪个域 |
| `auth` | `Authenticate` | 要求登录，未登录 401 |
| `auth.optional` | `OptionalAuth` | 有 token 就解析，没有也放行 |
| `throttle` | `ThrottleRequests` | 滑动窗口限流 |
| `log` | `LogRequest` | 访问日志 |
| `body.size` | `EnsureBodySize` | 请求体大小限制（413） |

### 几个值得说的

**`auth.optional` 和 `auth` 的区别**

```php
// auth：未登录直接 401，控制器不执行
Route::post('/posts/{id}/like', ...)->middleware(['auth']);

// auth.optional：能解析就解析，游客也放行
Route::get('/posts', ...)->middleware(['auth.optional']);
// 控制器里：$userId = (int) (Auth::id() ?? 0);  // 0 表示游客
```

**`cors` 会短路预检**

```text
OPTIONS /api/posts
  → cors 中间件直接返回 204，不进入路由和鉴权
```

这是必要的：预检请求不带 token，如果走正常流程会 401。

**`body.size` 只看 Content-Length**

```php
if ($declared > $max) {
    throw new HttpException(413, ...);
}
```

**不读 body**——读完再判断正是要避免的内存问题。

分块传输（无 `Content-Length`）放行，由 SAPI 的 `post_max_size` 兜底。

## 写一个中间件

```bash
php bin/sr make:middleware RequireVerified
```

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use SunsetReverie\Auth\AuthenticatedUser;
use SunsetReverie\Auth\Middleware\Authenticate;
use SunsetReverie\Exception\ErrorCode;
use SunsetReverie\Exception\HttpException;
use SunsetReverie\Http\Middleware\MiddlewareInterface;
use SunsetReverie\Http\Request;
use SunsetReverie\Http\Response;

final class RequireVerified implements MiddlewareInterface
{
    /**
     * @param Request $request 当前请求
     * @param Closure(Request): Response $next 下一个处理器
     * @return Response 响应
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->attribute(Authenticate::ATTRIBUTE);

        if (!$user instanceof AuthenticatedUser) {
            throw new HttpException(401, ErrorCode::Unauthenticated->value, '请先登录');
        }

        if (!$user->emailVerified) {
            throw new HttpException(403, ErrorCode::Forbidden->value, '请先验证邮箱');
        }

        return $next($request);
    }
}
```

注册别名：

```php
// config/api.php
'middleware_aliases' => [
    'verified' => \App\Http\Middleware\RequireVerified::class,
],
```

用：

```php
Route::post('/posts', ...)->middleware(['auth', 'verified']);
```

### 三个约定

**1. 必须 return `$next($request)`**

```php
public function handle(Request $request, Closure $next): Response
{
    // 前置逻辑

    $response = $next($request);      // ← 不调用它，请求永远到不了控制器

    // 后置逻辑（可以改响应）

    return $response;
}
```

**忘了 return 会导致控制器不执行**，但因为是 200 空响应，很难发现。

**2. 优先用 `$request->withAttribute()` 传数据**

```php
$request = $request->withAttribute('user', $user);

return $next($request);
```

不要改全局状态或容器单例——**Request 是不可变的**，`withAttribute()` 返回 clone 的新实例。

**3. 抛异常而不是返回错误响应**

```php
// ✅ 异常会被 ErrorHandler 转成标准错误响应
throw new HttpException(403, ErrorCode::Forbidden->value, '无权访问');

// ❌ 手工拼响应会漏掉 request_id、timestamp
return Response::json(['code' => 20003, 'message' => '无权访问'], 403);
```

## 参数化中间件

实现 `HasParameters` 接口：

```php
final class ThrottleRequests implements MiddlewareInterface, HasParameters
{
    private int $maxAttempts = 60;
    private int $decayMinutes = 1;

    public function withParameters(array $parameters): static
    {
        $clone = clone $this;
        $clone->maxAttempts = (int) ($parameters[0] ?? 60);
        $clone->decayMinutes = (int) ($parameters[1] ?? 1);

        return $clone;
    }

    public function handle(Request $request, Closure $next): Response
    {
        // 用 $this->maxAttempts 等
    }
}
```

路由里写：

```php
->middleware(['throttle:300,1'])     // 300 次 / 1 分钟
```

### 必须返回新实例

```php
public function withParameters(array $parameters): static
{
    $clone = clone $this;          // ← 不能改 $this
    $clone->maxAttempts = ...;

    return $clone;
}
```

**原因**：同一个中间件类可能在多个路由上用不同参数：

```php
Route::get('/posts', ...)->middleware(['throttle:60,1']);
Route::get('/search', ...)->middleware(['throttle:10,1']);
```

如果 `withParameters()` 改的是 `$this`（容器里的单例），后一个会覆盖前一个——
**所有路由都变成最后注册的那组参数**。

## 一个容易困惑的设计

中间件里用 `$request->withAttribute('user', $user)` 写入了用户，为什么控制器里 `Auth::id()` 能拿到？

看 `Pipeline::wrap()`：

```php
$nextWithSync = function (Request $request) use ($next): Response {
    $this->container->instance(Request::class, $request);   // ← 每次往下传时同步
    return $next($request);
};
```

**每进一层中间件，都把当前的 Request 实例同步回容器**。

原因写在注释里：

> Request 是不可变的，`withAttribute()` 返回 clone 的新实例，而容器里存的是进管道前的那个。
> 不同步的话，认证中间件写入的 `auth.user` 永远不会出现在容器里，
> `Auth::user() / Auth::id()` 会一直返回 null。

这个 bug 在 v0.31 修过一次——当时 `Auth::id()` 恒为 null，排查了很久。

## 常见错误

### 控制器不执行，返回 200 空响应

中间件忘了 `return $next($request)`。

### 中间件顺序不对

检查三层绑定：全局的在最外层。如果你在路由上写了什么，它总是比全局的晚执行。

### 参数没生效

```php
->middleware(['throttle:300,1'])     // ✅ 冒号后逗号分隔
->middleware(['throttle(300,1)'])    // ❌ 不支持括号
```

### 跨域预检 401

`cors` 中间件必须在 `auth` **之前**执行。全局中间件列表里检查顺序：

```php
'global_middleware' => [
    TraceRequest::class,
    HandleCors::class,       // ← 要在 auth 之前
    SecurityHeaders::class,
    // ...
],
```

### 中间件里改了 request 但控制器拿到的是旧的

必须把新的传下去：

```php
$request = $request->withAttribute('user', $user);
return $next($request);        // ← 传新的，不是原来的
```

## 下一步

- **[领域服务](./domain-service)** —— 分层职责、事务边界
- **[错误处理](./error-handling)** —— 异常体系、39 条错误码

::: info 文档还在补充
组件与参考章节（数据库、缓存、认证、校验、上传、图片、邮件、队列、日志、限流）尚未写完，写好后会在左侧导航出现。
:::
