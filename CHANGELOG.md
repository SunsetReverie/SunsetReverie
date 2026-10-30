# 更新日志

格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，版本号遵循[语义化版本](https://semver.org/lang/zh-CN/)。

`0.x` 阶段仍可能有破坏性变更，升级前请读对应条目。

## [Unreleased]

- 待办见文档 18.3.0「当前验收状态」的缺口列表

## [0.24.0] - 2026-10-30

### 新增

- 与 Composer 依赖共存：项目存在 `vendor/autoload.php` 时自动加载（此前第三方类完全不可见）
  - `core/Foundation/autoload.php` 用 `SR_BASE_PATH`（CLI / serve）
  - `Application::bootstrap()` 用 `basePath()` 兜底（HTTP 入口），配置文件里也能用三方类
- 无 `vendor` 目录时静默跳过，零依赖场景不受影响

## [0.23.0] - 2026-10-30

### 新增

- Redis 缓存驱动：`SCAN` 按前缀清理（绝不 `FLUSHDB`），`increment` 首次写入补 TTL
- APCu 缓存驱动：按前缀清理，扩展缺失时明确报错（不静默降级）
- 数据库连接超时：`connections.*.timeout`（默认 5 秒），MySQL 额外设置 `MYSQL_ATTR_CONNECT_TIMEOUT`

### 变更

- 默认驱动表只保留 `array` / `file`；`redis` / `apcu` 必须显式配置（避免静默连到 localhost）

### 修复

- `ConnectionException` 构造签名与 `ConnectionManager` 调用不匹配（连接失败时会 TypeError）
- `HttpException` 支持 `previous`，数据库异常链不再丢失

## [0.22.0] - 2026-10-30

### 修复

- `HEAD` 请求返回 405：改为回退到 `GET` 路由，响应不带 body（监控探活依赖此语义）

## [0.21.1] - 2026-10-30

### 修复

- 上传危险扩展名返回 `60002` 改为 `30005`（按文档语义）
- 图片结构损坏返回 `30001`；非法目录与未配签名密钥显式使用 `60002`

## [0.21.0] - 2026-10-30

### 新增

- 错误码补齐至 **39 条**（与文档 14.2 完全一致），此前仅 18 条
- `tests/Unit/ErrorCodeContractTest`：解析文档表格逐条比对码值 / HTTP / 消息，
  枚举里也不允许出现文档未登记的码

### 修复

- `UploadException` 全部使用 `30001` 的问题（超限 → `40007`，类型不符 → `30005`）
- `QueryException` / `ConnectionException` → `60001`，`CacheException` → `60003`
- `DomainNotFoundException` 改为 `HttpException` 子类（404 / `40008`）

## [0.20.1] - 2026-10-30

### 修复

- `FormRequest` 补 `request()` / `attribute()` / `header()` / `file()` 代理：
  控制器签名从 `Request` 换成 `FormRequest` 后仍能读取当前登录用户

## [0.20.0] - 2026-10-30

### 新增

- **中间件别名**：`->middleware(['auth'])`、`->middleware(['throttle:300,1'])`
  - 域级 `middleware_aliases` 覆盖全局（文档 6.7.3）
  - 内置别名：`auth` / `throttle` / `cors` / `log` / `domain` / `trace` / `security`
- **`FormRequest` + `validated()`**：参数注入时自动校验，只返回规则声明过的字段
- `Builder`：`paginate()` / `findOrFail()` / `when()` / `whereBetween()`
- `AuthManager`：`user()` / `check()` / `id()`
- `UploadManager::extend()`：替换存储驱动
- `Application`：`terminate()` / `terminating()` / `environment()`

### 修复

- `Pipeline` 只接受类名字符串，无法处理已解析的中间件实例

## [0.19.0] - 2026-10-30

### 新增

- **静态门面**：`Route` / `DB` / `Cache` / `Log` / `Auth` / `Config` / `Validator`
  - 文档中的 `Route::get()` / `DB::table()` 等写法现在可直接运行
  - `CacheManager` / `LogManager` 增加 `__call`，未定义方法转发到默认驱动 / 通道
- `DatabaseServiceProvider` 绑定 `Connection::class`：领域服务不再需要手写 Provider 绑定

### 变更

- 引导顺序调整为「配置 → 容器绑定 → 挂载门面 → Provider → 路由」

## [0.18.0] - 2026-10-30

### 新增

- 命令：`make:model` / `make:middleware` / `make:request` / `make:seeder` / `make:domain`
- 命令：`migrate:fresh` / `db:show` / `route:mode`

### 变更

- 生成器命令从 `ConsoleKernel` 拆出为 `MakeCommands`

## [0.17.0] - 2026-10-30

### 新增

- 命令：`lint:isolation`（域之间互不引用 / 领域层不依赖接口层 / 公共层不依赖上层）
- 命令：`make:controller` / `jwt:secret` / `cache:clear` / `domain:list` / `db:seed` / `upload:prune`

### 修复

- `CacheManager` 无内置默认驱动，框架自身执行 `cache:clear` 直接失败

## [0.16.0] - 2026-10-30

### 新增

- `tests/Integration`：多域端到端测试（完整走 `Application::boot()` 流程）

### 修复

- **长驻模式**：`CacheManager` 的驱动前缀在域切换后不重建，B 域会读到 A 域的键
- **长驻模式**：`RateLimiter` 构造时固定了驱动实例，域切换后限流计数串域

## [0.15.1] - 2026-10-30

### 修复

- `sr new --domains` 残留单域骨架的 `app/Controller/HomeController.php`
- 限流规则支持前缀通配（`admin.*`），精确匹配优先、多个通配取最长
- `Builder::insertGetId()` 返回 `int`（此前返回 `string`，业务侧每次都要手动转换）

## [0.15.0] - 2026-10-30

### 新增

- `Request::post()` / `all()`，`input()` 支持 JSON 请求体

### 说明

- 这两个缺口是用真实示例项目跑出来的：登录接口直接 500

## [0.14.1] - 2026-10-30

### 新增

- 命令：`lint:architecture`（ARCH-1 无 `App\` 引用 / ARCH-2 零三方依赖 / ARCH-3 组件不互引 / ARCH-4 仅组装层依赖组件）

## [0.14.0] - 2026-10-29

### 新增

- 分片上传：`ChunkedUploadManager`（init / chunk / merge / status），
  合并时重算 SHA-256、秒传索引、过期清理

## [0.13.1] - 2026-10-29

### 修复

- 单域项目不再被默认域误伤（JWT 不再强制要求 `aud`、缓存前缀不再多出 `web:`）

## [0.13.0] - 2026-10-29

### 新增

- 跨域 Token 拦截：多域时签发写入 `aud`，校验强制比对（`20010`）
- `route:list --domain=` 按域过滤

## [0.12.0] - 2026-10-29

### 新增

- 域级隔离：错误码段位（`error_segment`）、缓存键前缀、上传目录

## [0.11.0] - 2026-10-29

### 新增

- 命令：`key:generate` / `env:check` / `optimize` / `optimize:clear`

## [0.10.0] - 2026-10-29

### 新增

- **多 API 域**：`config/api.php` 声明域，`routes/{domain}.php` 独立入口，
  自动套用域前缀与路由名前缀
- 域解析四段式：Host → Path 前缀（最长匹配）→ Header → Default
- `sr new --domains=a,b` 生成多域骨架
- 日志增加 `domain` 字段

## [0.9.0] - 2026-10-29

### 新增

- 上传组件：五步安全校验（上传状态 / 大小 / 危险扩展名 / 真实 MIME / 图片结构）
- 私有文件访问：签名 URL 走 `/files` 校验，失败返回 `20011`

## [0.8.0] - 2026-10-29

### 新增

- 日志组件：JSON Lines、六种通道（single/daily/stderr/stdout/null/stack）、
  上下文栈、递归脱敏
- `LogRequest` 中间件：注入请求上下文并记录访问日志

## [0.7.0] - 2026-10-29

### 新增

- 限流：固定窗口，超限 429 + `Retry-After` + `X-RateLimit-*`
- CORS：预检短路 204，来源白名单，默认不放行任何跨域

## [0.6.0] - 2026-10-29

### 新增

- JWT 认证：HS256、`alg` 白名单、`hash_equals` 验签、密钥 ≥32 字节强制
- `Authenticate` 中间件

## [0.5.0] - 2026-10-29

### 新增

- 迁移工具：`migrate` / `migrate:rollback` / `migrate:status` / `make:migration`
- 建表 DSL：`Schema` + `Blueprint`，按 mysql / sqlite / pgsql 生成方言 SQL

## [0.4.0] - 2026-10-29

### 新增

- 控制器参数注入：按类型解析 `Request` / 容器服务 / 路由参数
- 验证组件：`Validator`（18 条内置规则 + `extend()`）+ 422 字段级错误
- 项目骨架与 `sr new`

## [0.3.0] - 2026-10-29

### 新增

- Provider 机制（`dependsOn` 拓扑排序、环检测）
- 数据库层：`Connection`（嵌套事务保存点）+ `Query\Builder`
- 缓存：`Array` / `File` 驱动

## [0.2.0] - 2026-10-29

### 新增

- 配置系统：`.env` 解析、点语法、`config/{env}/` 深度合并
- 中间件管道（洋葱模型）
- 路由增强：参数约束、路由组、路由缓存

## [0.1.0] - 2026-10-29

### 新增

- 最小可运行内核：容器、路由、请求 / 响应、统一包络、异常渲染、CLI
