# 安全策略

---

## 支持的版本

| 版本 | 安全更新 |
| --- | --- |
| **1.0.x**（当前，beta） | ✅ 优先修复 |
| 0.4x | 仅高危漏洞 |
| < 0.40 | ❌ 请先升级 |

当前处于 `1.0.0-beta` 阶段：功能完整、可用于生产试用，但 API 仍可能微调。
beta 之间的安全修复会直接发新的 beta 版本，不再回补旧的 beta。

---

## 报告漏洞

**请不要开公开 issue。**

| 渠道 | 说明 |
| --- | --- |
| 邮件 | 见仓库 `LICENSE` 中的版权方联系方式，或通过 GitHub Security Advisory 私密提交 |
| GitHub | 仓库 → Security → Report a vulnerability（推荐，可追踪） |

### 报告时请包含

1. 受影响的版本（`php bin/sr about` 的输出）
2. 复现步骤（最小可复现代码或 curl 命令）
3. 影响评估：能读到什么 / 能改什么 / 是否需要登录
4. 如果可能，附一个修复建议

### 响应时限

| 级别 | 定义 | 首次响应 | 修复 |
| --- | --- | --- | --- |
| **P0** | 远程代码执行、认证绕过、任意文件读写 | 2 个工作日 | 7 天内出补丁 |
| **P1** | 越权访问他人数据、SQL 注入、敏感信息泄露 | 5 个工作日 | 14 天内 |
| **P2** | 需要特殊条件才可利用（如需要管理员权限） | 10 个工作日 | 下个 minor |
| **P3** | 理论风险、加固建议 | 视情况 | 视情况 |

修复后会：发布新版本、在 CHANGELOG 标注、致谢报告者（除非要求匿名）。

---

## 框架已有的安全设计

报告前可以先了解这些——如果属于下列机制的正常行为，可能不是漏洞。

### SQL 注入

- 所有值都走 PDO 占位符，不拼接
- 标识符（表名、列名、排序方向）经 `assertIdentifier()` 白名单校验：
  只允许字母、数字、下划线、单个点
- `selectRaw()` / `groupByRaw()` / `selectRawWith()` **跳过**标识符校验，
  因为它们要接受 `COUNT(*)`、`DATE(x)` 这类表达式

> **这是明确的边界**：`selectRaw` 一类方法**只接受代码里写死的表达式**。
> 如果业务代码把用户输入拼进这些方法，那是业务层的注入漏洞，不是框架的。

### 越权

- 所有「读自己数据」的查询必须带 `where('user_id', $userId)`
- 通知的 `markRead` / 通知列表都做了这个约束（有测试：传别人的通知 ID → `updated = 0`）

> **边界**：框架不提供行级权限（RLS）或自动的数据归属过滤。
> 业务层写漏了 `where user_id` 框架不会拦。

### 响应头（防 CRLF 注入）

`Response::withHeader()` 会对字段名与值做清洗：

- 字段名按 RFC 7230 的 token 字符白名单过滤（`[^!#$%&'*+.^_`|~0-9A-Za-z-]` 全部剔除）
- 字段值剥掉 `\r`、`\n`、`\0`

不这么做的话，把用户输入塞进头里就可能造成响应拆分：

```php
->withHeader('X-User', $request->query('name'))
// 请求 name = "alice\r\nSet-Cookie: admin=1"
```

PHP 的 `header()` 自身对换行会告警，但**告警不等于防护**——换个 SAPI 或关掉警告就静默失效。

### 分页与请求体

| 项 | 机制 |
| --- | --- |
| 分页上限 | `Request::perPage()` 读 `config('pagination.max_per_page')` 夹取；默认 50 |
| 请求体大小 | `body.size` 中间件按 `Content-Length` 拒绝超限请求，返回 413 |

请求体检查**只看声明的长度、不读 body**——读完再判断正是要避免的内存问题。
客户端可以不发 `Content-Length`（分块传输），这种情况由 SAPI 的 `post_max_size` 兜底。

### 认证

- Token 绑定 API 域：`web` 域的 token 不能访问 `admin` 域（返回 `20010`）
- 密码用 `password_hash()`（bcrypt），不存明文
- `Authenticate` 中间件无 token → 401；`OptionalAuth` → 静默当游客

### 上传

- 扩展名 + MIME 双检
- 危险扩展名（`php` / `phtml` / `phar` 等）即使 MIME 伪装也拒绝
- 图片额外校验结构（`getimagesize`）
- 存储路径由服务端生成（哈希命名），不使用用户提供的文件名

> **部署要求**：`storage/uploads/` 目录必须**禁止执行 PHP**。Nginx 配置示例见
> 文档附录 H。这是部署方的责任，框架只能提供建议。

### 限流

- `throttle` 中间件按 IP + 路由 + 域计数
- 默认存储走缓存驱动；生产环境建议用 Redis（多机共享）

### 错误信息

- `APP_DEBUG=false` 时不返回堆栈，只返回 `request_id`
- 生产环境请务必关掉 debug

---

## 不在范围内

以下**不视为框架漏洞**（但欢迎讨论）：

| 项 | 原因 |
| --- | --- |
| 没有任何 SQL/命令注入时，业务层拼接了用户输入 | 使用方式问题 |
| 部署时 `APP_DEBUG=true` 暴露堆栈 | 配置问题 |
| 未给 `/uploads/` 加 `deny all` 导致 PHP 被执行 | 部署问题（附录 H 已有说明） |
| 没有 CSRF 防护 | 纯 API + Bearer Token，不用 Cookie 会话 |
| 没有内置 WAF / 防 CC | 应交给反向代理或云服务 |
| 弱密码被登录成功 | 没有密码强度策略是刻意的（由业务决定） |

---

## 部署安全清单

上线前自查（对应文档 11.6）：

- [ ] `APP_DEBUG=false`、`APP_ENV=production`
- [ ] `APP_KEY` 已改成随机值（不要用示例值）
- [ ] 数据库账号最小权限（不要用 root）
- [ ] `storage/` 目录不可通过 Web 直接访问（除 `uploads/`）
- [ ] `storage/uploads/` 禁止执行 PHP
- [ ] Nginx `client_max_body_size` 与 `http.max_body_size`、`upload.max_size` 三者对齐
      （框架层的 `body.size` 中间件能拦，但 Nginx 更早；值不一致会出现「小文件过、大文件返回 HTML」）
- [ ] 生产环境缓存驱动用 Redis（`file` 在多机部署下不一致）
- [ ] `cache.prefix` 非空（否则 `flush` 会清空整个 Redis 库——框架已拒绝执行，但仍需配置）
- [ ] 限流阈值按实际流量调整
- [ ] 队列与定时任务的 cron 已配置

---

## 已知的设计取舍

这些是为了简单性做的选择，有明确记录：

| 取舍 | 影响 | 备注 |
| --- | --- | --- |
| 无 ORM | SQL 需要手写，注入风险靠 `assertIdentifier` + 占位符 | 有测试覆盖 |
| 无行级权限 | 数据归属靠业务层 `where user_id` | 有越权测试示范 |
| 队列抢占租约 300 秒 | worker 崩溃后任务最多卡 5 分钟 | 可接受 |
| `selectRaw` 不做注入防护 | 只接受代码内表达式 | 文档反复强调 |
