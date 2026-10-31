# 错误码

## 响应格式

```json
{
  "code": 40001,
  "message": "文章不存在",
  "data": null,
  "request_id": "01a18f94-...",
  "timestamp": 1793380598
}
```

| 字段 | 说明 |
| --- | --- |
| `code` | **业务码**（本文档的主角），`0` 表示成功 |
| `message` | 人类可读的说明。成功时是 `success`，失败时是具体原因 |
| `data` | 业务数据，失败时通常是 `null` |
| `request_id` | 与日志关联，排查时用它 grep |
| `timestamp` | Unix 时间戳 |

## 两套码并存

```text
HTTP 404  +  业务码 40001（NotFound）      → 前端跳「不存在」页
HTTP 422  +  业务码 30001（ValidationFailed）→ 前端标红表单字段
```

HTTP 状态码告诉**传输层**发生了什么，业务码告诉**业务层**该做什么。

::: tip 前端判断只看 `code`
```js
if (res.code === 0) {
  // 成功
} else if (res.code === 30001) {
  // 校验失败，把 res.errors 标到表单
} else {
  // 其他：弹 res.message
}
```

`message` 是给人看的，**会变**（比如改成 `success` 那次）。`code` 才是契约。
:::

## 分段规则

| 段位 | 含义 | 谁用 |
| --- | --- | --- |
| `0` | 成功 | — |
| `1xxxx` | 系统级 | 框架 |
| `2xxxx` | 认证与权限 | 框架 + 业务 |
| `3xxxx` | 参数校验 | 框架 + 业务 |
| `4xxxx` | 资源 | 框架 + 业务 |
| `5xxxx` | **业务自定义** | 业务（框架不占用） |
| `6xxxx` | 依赖服务 | 框架 |

---

# 完整清单（39 条）

## 0 · 成功

| 码 | 常量 | HTTP | symbol |
| --- | --- | --- | --- |
| 0 | `Ok` | 200 | `SR.OK` |

## 1xxxx · 系统级（5 条）

| 码 | 常量 | HTTP | symbol | 什么时候出现 |
| --- | --- | --- | --- | --- |
| 10001 | `InternalError` | 500 | `SR.SERVER.INTERNAL_ERROR` | **抛出未捕获的异常**时由 ErrorHandler 兜底 |
| 10002 | `ServiceUnavailable` | 503 | `SR.SERVER.UNAVAILABLE` | 主动下线（业务判断） |
| 10003 | `ConfigError` | 500 | `SR.CONFIG.ERROR` | 配置缺失或非法 |
| 10004 | `BootstrapFailed` | 500 | `SR.BOOTSTRAP.FAILED` | 启动阶段失败（路由校验、依赖循环） |
| 10005 | `Maintenance` | 503 | `SR.SERVER.MAINTENANCE` | 维护模式（业务判断） |

::: warning 10001 会记录 error 日志
其他码都是「预期内的失败」，不记 error 日志。只有 `10001`（非 HttpException）
会被记下来——因为那意味着**有 bug**。

生产环境它的 `message` 固定是「服务内部错误」，真实信息在日志里。
:::

## 2xxxx · 认证与权限（11 条）

| 码 | 常量 | HTTP | symbol | 谁抛 |
| --- | --- | --- | --- | --- |
| 20001 | `Unauthenticated` | 401 | `SR.AUTH.UNAUTHENTICATED` | `auth` 中间件（无 token） |
| 20002 | `TokenExpired` | 401 | `SR.AUTH.TOKEN_EXPIRED` | `auth` 中间件（token 无效/过期） |
| 20003 | `Forbidden` | 403 | `SR.AUTH.FORBIDDEN` | 业务（权限不足） |
| 20004 | `AccountDisabled` | 403 | `SR.AUTH.ACCOUNT_DISABLED` | 业务（账号被禁） |
| 20005 | `TooManyRequests` | 429 | `SR.AUTH.TOO_MANY_REQUESTS` | `throttle` 中间件 |
| 20006 | `SignatureExpired` | 401 | `SR.AUTH.SIGNATURE_EXPIRED` | 业务（API 签名） |
| 20007 | `SignatureReplayed` | 401 | `SR.AUTH.SIGNATURE_REPLAYED` | 业务（nonce 重复） |
| 20008 | `SignatureInvalid` | 401 | `SR.AUTH.SIGNATURE_INVALID` | 业务（签名不匹配） |
| 20009 | `CredentialsInvalid` | 401 | `SR.AUTH.CREDENTIALS_INVALID` | 业务（密码错） |
| 20010 | `TokenDomainMismatch` | 401 | `SR.AUTH.TOKEN_DOMAIN_MISMATCH` | `auth` 中间件（`aud` 不匹配） |
| 20011 | `SignedUrlExpired` | 403 | `SR.AUTH.SIGNED_URL_EXPIRED` | 上传组件的文件路由 |

::: tip 20009 是预留的
框架不自动抛——登录逻辑要自己写：

```php
if (!password_verify($password, $user['password_hash'])) {
    throw new HttpException(401, ErrorCode::CredentialsInvalid->value, '账号或密码错误');
}
```

也可以改用 `30001`（422）——看你想让前端「跳登录页」还是「标红输入框」。
:::

## 3xxxx · 参数校验（7 条）

| 码 | 常量 | HTTP | symbol | 谁抛 |
| --- | --- | --- | --- | --- |
| 30001 | `ValidationFailed` | 422 | `SR.VALIDATION.FAILED` | `FormRequest` / `Validator` |
| 30002 | `InvalidJson` | 400 | `SR.HTTP.INVALID_JSON` | 请求体 JSON 语法错误 |
| 30003 | `MissingParameter` | 400 | `SR.HTTP.MISSING_PARAMETER` | 业务（手写校验） |
| 30004 | `InvalidParameterType` | 400 | `SR.REQUEST.INVALID_PARAMETER_TYPE` | 业务（手写校验） |
| 30005 | `UnsupportedMediaType` | 415 | `SR.REQUEST.UNSUPPORTED_MEDIA_TYPE` | 业务（Content-Type 不对） |
| 30006 | `NotAcceptable` | 406 | `SR.REQUEST.NOT_ACCEPTABLE` | 业务（Accept 无法满足） |
| 30007 | `MissingIdempotencyKey` | 400 | `SR.REQUEST.MISSING_IDEMPOTENCY_KEY` | 业务（幂等键缺失） |

`30001` 会带 `errors` 字段：

```json
{
  "code": 30001,
  "message": "参数校验失败",
  "errors": {
    "title": ["标题至少 2 个字符"],
    "category_id": ["分类 ID 必须是整数"]
  }
}
```

## 4xxxx · 资源（10 条）

| 码 | 常量 | HTTP | symbol | 谁抛 |
| --- | --- | --- | --- | --- |
| 40001 | `NotFound` | 404 | `SR.RESOURCE.NOT_FOUND` | 业务（查单条不存在） |
| 40002 | `RouteNotFound` | 404 | `SR.ROUTE.NOT_FOUND` | **Router**（无匹配路由） |
| 40003 | `MethodNotAllowed` | 405 | `SR.ROUTE.METHOD_NOT_ALLOWED` | **Router**（路径对方法错，带 `Allow` 头） |
| 40004 | `AlreadyExists` | 409 | `SR.RESOURCE.ALREADY_EXISTS` | 业务（唯一键冲突） |
| 40005 | `StateConflict` | 409 | `SR.RESOURCE.STATE_CONFLICT` | 业务（状态不允许该操作） |
| 40006 | `ResourceGone` | 410 | `SR.RESOURCE.GONE` | 业务（已删除且不再恢复） |
| 40007 | `PayloadTooLarge` | 413 | `SR.REQUEST.PAYLOAD_TOO_LARGE` | `body.size` 中间件 |
| 40008 | `DomainNotFound` | 404 | `SR.RESOURCE.DOMAIN_NOT_FOUND` | **DomainRegistry**（域未配置） |
| 40009 | `IdempotencyConflict` | 409 | `SR.RESOURCE.IDEMPOTENCY_CONFLICT` | 业务（同幂等键不同参数） |
| 40010 | `VersionConflict` | 409 | `SR.RESOURCE.VERSION_CONFLICT` | 业务（乐观锁） |

::: warning 40001 与 30001 别混用
| 场景 | 正确码 |
| --- | --- |
| 查询单条资源不存在（`GET /orders/NOPE`） | **40001**（404） |
| 提交的参数引用了不存在的资源（下单时商品 ID 无效） | **30001**（422） |

搞反的话，前端按状态码走是「跳详情页」，按业务码走是「标红表单」——两套判断打架。
:::

## 6xxxx · 依赖服务（5 条）

| 码 | 常量 | HTTP | symbol | 谁抛 |
| --- | --- | --- | --- | --- |
| 60001 | `DatabaseError` | 500 | `SR.DATABASE.ERROR` | 数据库异常（通常被 `QueryException` 包着） |
| 60002 | `UploadFailed` | 500 | `SR.STORAGE.UPLOAD_FAILED` | 上传组件 |
| 60003 | `CacheError` | 500 | `SR.CACHE.ERROR` | 缓存驱动异常 |
| 60004 | `StorageError` | 500 | `SR.STORAGE.ERROR` | 文件存储异常 |
| 60005 | `UpstreamError` | 502 | `SR.UPSTREAM.ERROR` | 调用第三方失败 |

---

# 5xxxx · 业务自定义

框架**不占用**这一段。业务错误码从这里开始，避免和内置码冲突。

## 按域分段

多域项目还可以给每个域分配段位：

```php
// config/api.php
'domains' => [
    'admin' => ['error_segment' => 51],
    'web' => ['error_segment' => 52],
],
```

于是：

| 域 | 业务码范围 |
| --- | --- |
| `admin` | `51001`、`51002`…… |
| `web` | `52001`、`52002`…… |

**好处**：前端拿到 `52007` 就知道是 `web` 域的问题，不用猜。

::: warning 段位一旦分配不要改
已经上线的前端可能硬编码了判断逻辑。改段位等于改契约。
:::

## 定义业务异常

```php
final class OrderException extends HttpException
{
    public function __construct(
        string $message,
        int $status = 422,
        ErrorCode $code = ErrorCode::ValidationFailed
    ) {
        // 404 时业务码兜底成 NotFound，避免「HTTP 404 + 业务码 30001」的矛盾
        parent::__construct(
            $status,
            $status === 404 ? ErrorCode::NotFound->value : $code->value,
            $message
        );
    }
}
```

那个兜底很重要——见[错误处理](./error-handling#定义业务异常)。

---

# symbol 有什么用

每条码有一个稳定的字符串标识（方法名是 `symbol()`）：

```text
SR.AUTH.TOKEN_EXPIRED
SR.ROUTE.METHOD_NOT_ALLOWED
SR.VALIDATION.FAILED
```

| 用途 | 说明 |
| --- | --- |
| **日志检索** | 比数字好搜，且不会因为段位调整而失效 |
| 前端埋点 | 上报 symbol 比上报 `20002` 更易读 |
| 文档引用 | 跨语言讨论时不用记数字 |

取法：

```php
ErrorCode::TokenExpired->symbol();       // "SR.AUTH.TOKEN_EXPIRED"
ErrorCode::TokenExpired->value;          // 20002
ErrorCode::TokenExpired->message();      // 中文说明
ErrorCode::TokenExpired->httpStatus();   // 401
```

---

# 完整性保障

错误码表和枚举的一致性有测试守着：

```php
// tests/Unit/ErrorCodeContractTest.php
public function testDocumentMatchesEnum(): void
```

它解析内部规划文档里的错误码表，逐个比对：码值、HTTP 状态、消息。

**改了枚举忘了改表，测试会红。**

另外 `error-handling.md` 里的表格也被同样的机制检查过——39 条的值与 HTTP 状态 100% 对齐（脚本核对）。

---

# 常见错误

## 前端按 `message` 判断

```js
if (res.message === 'ok') { ... }      // ❌ 会变
if (res.code === 0) { ... }            // ✅ 契约
```

`message` 改过一次（`ok` → `success`）。以后还可能改。

## 业务码和 HTTP 状态矛盾

```text
HTTP 404 + 业务码 30001（参数校验失败）
```

前端两套判断打架。检查业务异常的构造有没有传对码（或用 404 兜底）。

## 自定义码撞了内置码

```php
throw new HttpException(400, 40001, '我的错误');   // ❌ 40001 是 NotFound
```

业务码从 `5xxxx` 开始，或者用域的 `error_segment`。

## 500 但看不到原因

生产环境 `10001` 的 message 固定是「服务内部错误」。

```bash
grep "<request_id>" storage/logs/app-$(date +%Y-%m-%d).log
```

`request_id` 在响应体里。

---

# 下一步

- **[错误处理](./error-handling)** —— 异常体系、脱敏、自定义异常
- **[校验](./validation)** —— `30001` 的触发与 `errors` 结构
- **[日志](./log)** —— 用 `request_id` 串起一次请求
