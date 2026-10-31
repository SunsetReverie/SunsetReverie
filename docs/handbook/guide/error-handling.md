# 错误处理

框架的错误响应统一长这样：

```json
{
  "code": 40001,
  "message": "文章不存在",
  "data": null,
  "request_id": "01a18f94-...",
  "timestamp": 1793380598
}
```

`code` 是**业务码**（不是 HTTP 状态码）。两套码并存，见下文。

## 抛异常，不要拼响应

```php
// ✅ 抛异常
throw new HttpException(404, ErrorCode::NotFound->value, '文章不存在');

// ❌ 手工拼响应
return Response::json(['code' => 40001, 'message' => '文章不存在'], 404);
```

手工拼会漏掉：

- `request_id`（没法关联日志）
- `timestamp`
- debug 模式下的堆栈
- 统一日志记录

## 异常体系

```text
Throwable
└── HttpException                    带 status + errorCode，不会被当 500
    ├── ValidationException          422 + errors 明细
    ├── MethodNotAllowedException    405 + 自动写 Allow 头
    └── 你的业务异常（推荐继承 HttpException）
```

**关键区别**：

| 类型 | 处理 |
| --- | --- |
| `HttpException` 子类 | 用它的 status / code / message，**不记 error 日志** |
| 其他 `Throwable` | 一律 500 + `10001`，**记 error 日志**，生产环境隐藏原始信息 |

业务异常继承 `HttpException` 的意义：**预期内的失败不该污染错误日志**。

## 错误码分段

39 条，按段位划分：

### 1xxxx 系统级

| 码 | 常量 | HTTP | 含义 |
| --- | --- | --- | --- |
| 10001 | `InternalError` | 500 | 服务内部错误 |
| 10002 | `ServiceUnavailable` | 503 | 服务不可用 |
| 10003 | `ConfigError` | 500 | 配置错误 |
| 10004 | `BootstrapFailed` | 500 | 启动失败 |
| 10005 | `Maintenance` | 503 | 维护中 |

### 2xxxx 认证与权限

| 码 | 常量 | HTTP | 含义 |
| --- | --- | --- | --- |
| 20001 | `Unauthenticated` | 401 | 未登录 |
| 20002 | `TokenExpired` | 401 | token 过期 |
| 20003 | `Forbidden` | 403 | 无权限 |
| 20004 | `AccountDisabled` | 403 | 账号被禁用 |
| 20005 | `TooManyRequests` | 429 | 请求过于频繁 |
| 20006 | `SignatureExpired` | 401 | 签名过期 |
| 20007 | `SignatureReplayed` | 401 | 签名重放 |
| 20008 | `SignatureInvalid` | 401 | 签名无效 |
| 20009 | `CredentialsInvalid` | 422 | 账号或密码错误 |
| 20010 | `TokenDomainMismatch` | 401 | token 不属于当前域 |
| 20011 | `SignedUrlExpired` | 403 | 签名链接失效 |

### 3xxxx 参数校验

| 码 | 常量 | HTTP | 含义 |
| --- | --- | --- | --- |
| 30001 | `ValidationFailed` | 422 | 参数校验失败 |
| 30002 | `InvalidJson` | 400 | JSON 格式错误 |
| 30003 | `MissingParameter` | 422 | 缺少必需参数 |
| 30004 | `InvalidParameterType` | 422 | 参数类型错误 |
| 30005 | `UnsupportedMediaType` | 415 | 不支持的媒体类型 |
| 30006 | `NotAcceptable` | 406 | 无法满足 Accept |
| 30007 | `MissingIdempotencyKey` | 400 | 缺少幂等键 |

### 4xxxx 资源

| 码 | 常量 | HTTP | 含义 |
| --- | --- | --- | --- |
| 40001 | `NotFound` | 404 | 资源不存在 |
| 40002 | `RouteNotFound` | 404 | 路由不存在 |
| 40003 | `MethodNotAllowed` | 405 | 方法不允许 |
| 40004 | `AlreadyExists` | 409 | 资源已存在 |
| 40005 | `StateConflict` | 409 | 状态冲突 |
| 40006 | `ResourceGone` | 410 | 资源已删除 |
| 40007 | `PayloadTooLarge` | 413 | 请求体过大 |
| 40008 | `DomainNotFound` | 404 | API 域不存在 |
| 40009 | `IdempotencyConflict` | 409 | 幂等键冲突 |
| 40010 | `VersionConflict` | 409 | 版本冲突 |

### 6xxxx 依赖服务

| 码 | 常量 | HTTP | 含义 |
| --- | --- | --- | --- |
| 60001 | `DatabaseError` | 500 | 数据库错误 |
| 60002 | `UploadFailed` | 500 | 上传失败 |
| 60003 | `CacheError` | 500 | 缓存错误 |
| 60004 | `StorageError` | 500 | 存储错误 |
| 60005 | `UpstreamError` | 502 | 上游服务错误 |

::: tip 为什么没有 5xxxx
留给**业务自定义**。多域模式下每个域还会有自己的段位（`error_segment`），
比如 admin 域配了 `51`，它的业务错误就是 `51001`、`51002`……
:::

## 定义业务异常

```php
<?php

declare(strict_types=1);

namespace App\Domain\Order\Exception;

use SunsetReverie\Exception\ErrorCode;
use SunsetReverie\Exception\HttpException;

final class OrderException extends HttpException
{
    public function __construct(
        string $message,
        int $status = 422,
        ErrorCode $code = ErrorCode::ValidationFailed
    ) {
        /*
         * 404 必须配 NotFound 码，否则会出现「HTTP 404 + 业务码 30001」
         * 这种矛盾：前端按状态码走是「跳详情页」，按业务码走是「标红表单字段」。
         *
         * 按状态兜底，避免每个调用点都要记得传对码。
         */
        parent::__construct(
            $status,
            $status === 404 ? ErrorCode::NotFound->value : $code->value,
            $message
        );
    }
}
```

用：

```php
// 查询单条资源不存在 → 404
throw new OrderException(sprintf('订单 %s 不存在', $orderNo), 404);

// 提交的参数有问题 → 422（默认）
throw new OrderException('库存不足');
```

::: warning 这个兜底很有必要
不写兜底的话，每个调用点都要记得传对业务码：

```php
throw new OrderException('订单不存在', 404, ErrorCode::NotFound);   // 忘了第三个参数 → 404 + 30001
```

**HTTP 状态和业务码语义矛盾**，前端两套判断会打架。

同样的坑在 `FileException`、`RbacException` 都踩过——它们后来都加了这个兜底。
:::

## 生产环境脱敏

| 情况 | 开发（`APP_DEBUG=true`） | 生产 |
| --- | --- | --- |
| `HttpException` | message 原样 + `debug` 字段 | message 原样（业务信息本就该给用户看） |
| 其他异常 | message + 堆栈 + 文件行号 | **只返回「服务内部错误」** |
| 日志 | 不记（HTTP 异常） | 全部记 |

生产环境下非 `HttpException` 的响应：

```json
{
  "code": 10001,
  "message": "服务内部错误",
  "data": null,
  "request_id": "01a18f94-..."
}
```

**原始异常信息去哪了**：日志里，用 `request_id` 对：

```bash
grep "01a18f94" storage/logs/app-2026-10-31.log
```

## 405 的特殊处理

`MethodNotAllowedException` 会自动带 `Allow` 头：

```php
if ($e instanceof MethodNotAllowedException) {
    return Response::json($payload, 405)
        ->withHeader('Allow', implode(', ', $e->allowed));
}
```

```text
HTTP/1.1 405 Method Not Allowed
Allow: GET, POST
```

这是 HTTP 规范要求，也让调用方能自己纠正。

## 常见错误

### 500 但看不到原因

生产环境不返回堆栈。两个办法：

```bash
# 1. 看日志（用响应里的 request_id 对）
grep "<request_id>" storage/logs/app-$(date +%Y-%m-%d).log

# 2. 临时开 debug（仅本地）
# .env 里 APP_DEBUG=true
```

### 业务异常被当成 500

说明没继承 `HttpException`：

```php
class OrderException extends \Exception        // ❌ → 500 + 10001
class OrderException extends HttpException     // ✅ → 用自己的 status
```

### 错误码和 HTTP 状态语义矛盾

```text
HTTP 404 + 业务码 30001（参数校验失败）
```

前端两套逻辑打架。检查业务异常的构造是否传对了码（或加 404 兜底）。

### 校验失败的 details 丢失

`ValidationException` 的构造要传 `errors`：

```php
throw new ValidationException([
    'title' => ['标题至少 2 个字符'],
]);
```

响应里才会有：

```json
{
  "code": 30001,
  "message": "参数校验失败",
  "errors": { "title": ["标题至少 2 个字符"] }
}
```

## 下一步

- **[控制器](./controller)** —— 参数注入失败时抛什么
- **[中间件](./middleware)** —— 在控制器之前拦截并抛异常

::: info 文档还在补充
组件与参考章节（数据库、缓存、认证、校验、上传、图片、邮件、队列、日志、限流）尚未写完，写好后会在左侧导航出现。
:::
