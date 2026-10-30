# 更新日志

格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，版本号遵循[语义化版本](https://semver.org/lang/zh-CN/)。

`0.x` 阶段仍可能有破坏性变更，升级前请读对应条目。

## [Unreleased]

- 待办见文档 18.3.0「当前验收状态」的缺口列表

## [0.37.0] - 2026-10-30

### 新增

- `Builder::selectRawWith(string $expression, array $bindings)`：带绑定的 SELECT 表达式

### 为什么需要它

做加权搜索时，排序要把「标题命中」「摘要命中」「正文命中」算成分：

```php
selectRawWith(
    '(CASE WHEN title LIKE ? THEN 100 ELSE 0 END) + (CASE WHEN content LIKE ? THEN 10 ELSE 0 END) AS score',
    ['%kw%', '%kw%']
)
```

`selectRaw()` 只能传死表达式，占位符没有绑定值可传——生成的 SQL 里 `?`
永远匹配不到参数。而把关键词直接拼进 SQL 就是注入入口。

实现要点：

- 校验占位符数量与绑定值数量一致，不等直接抛异常（不静默错位）
- `selectBindings` 排在 `whereBindings` 之前（SELECT 在 SQL 里位于 WHERE 之前）
- `count` / `update` / `delete` 改用 `whereBindings()`，否则会多带 SELECT 的绑定

### 使用提示

`select('*')` 会被标识符校验拒绝（防注入）。取全部列**不要显式写**，
默认就是 `*`；需要追加计算列时直接 `selectRawWith()` 即可。

## [0.36.1] - 2026-10-30

### 修复

- **`Blueprint::unique()` 不接受列名参数，静默作用到「最后一列」**

`unique()` 原本签名是 `unique(): self`，只能链式修饰最近定义的列。
而 PHP 会**静默忽略多余参数**，所以 `$table->unique('sku')` 不报错，
却把唯一索引加到了 `timestamps()` 生成的 `updated_at` 上。

实测后果：迁移成功、表建出来，但 `updated_at` 成了唯一列——
插入第二条数据就报 `Duplicate entry`，报错信息完全指不到真正原因。

现在支持两种用法：

```php
$table->string('slug', 200)->unique();   // 链式（历史写法）
$table->unique('sku');                    // 按列名
$table->unique(['post_id', 'user_id']);   // 复合
```

列名写错会立刻抛 `InvalidArgumentException`，而不是等数据库报「未知列」。

## [0.36.0] - 2026-10-30

### 新增

- `Builder::whereGroup(Closure)` / `orWhereGroup(Closure)`：条件分组（加括号）
- `Builder::whereNotNull()`：`IS NOT NULL`

### 为什么必须加 whereGroup

SQL 里 `AND` 优先级高于 `OR`。做搜索时这样写：

```php
->where('status', 1)
  ->where('title', 'LIKE', '%x%')
  ->orWhere('content', 'LIKE', '%x%')
```

实际生成 `status = 1 AND title LIKE '%x%' OR content LIKE '%x%'`，
等价于 `(status = 1 AND title LIKE '%x%') OR content LIKE '%x%'`。

**后果：草稿（status=0）只要正文匹配就会被搜出来**——实测确认，这是数据泄露。

正确写法：

```php
->where('status', 1)
  ->whereGroup(fn ($q) => $q->where('title', 'LIKE', '%x%')->orWhere('content', 'LIKE', '%x%'))
```

生成 `status = 1 AND (title LIKE '%x%' OR content LIKE '%x%')`。

## [0.35.1] - 2026-10-30

### 变更

- `auto` 驱动改为 **GD 优先**（原为 Imagick 优先）

依据实测（1600×1200 源图 → 300×300 缩略图）：

| 驱动 | 耗时 | 体积 |
| --- | --- | --- |
| GD | **15ms** | 6.9 KB |
| Imagick | 44ms | 7.3 KB |

缩略图尺寸上两者的质量差异肉眼几乎看不出来，但耗时差 3 倍。
要更好重采样质量或保留动图时，显式配 `driver => 'imagick'`。

- 新增测试 `testAutoPrefersGd` / `testExplicitImagickIsRespected` 锁定该行为

## [0.35.0] - 2026-10-30

### 修复

- **`Imagick::setImageOpacity()` 在 ImageMagick 7 已被移除**，`watermark()` 直接抛
  `Call to undefined method`。改用 `setImageAlpha()` + `evaluateImage(EVALUATE_MULTIPLY, CHANNEL_ALPHA)`，
  用 `method_exists` 分支兼容 IM6

### 新增

- EXIF 方向校正的**真实测试**。此前用 `Imagick::setImageOrientation()` 造数据，
  但它只改内存属性、不写进文件，读回来永远是 1——等于这段代码从未被验证过。
  改为手工注入最小 EXIF APP1 段（TIFF 头 + IFD0 + Orientation tag），
  实测 `Orientation=6/8` 时两驱动都正确旋转成 300×400

### 变更

- 图片测试改为**双驱动对照**：尺寸语义（`fit`/`exact`/`cover`/`crop`）、格式转换、
  透明填白在 GD 与 Imagick 上各跑一遍，断言信息带驱动名

### 实测对比（1600×1200 源图）

| 操作 | GD | Imagick |
| --- | --- | --- |
| `cover(300,300)` → webp 80 | 6.9 KB / 15ms | 7.3 KB / 44ms |
| `resize(800,0)` → jpg 90 | 66.9 KB / 19ms | 62.8 KB / 45ms |

Imagick 慢 2–3 倍（Lanczos 重采样 + 进程外编解码），但压缩率略好。
`auto` 模式优先选 GD（见 0.35.1 的调整说明）。

## [0.34.0] - 2026-10-30

### 新增

- **图片处理组件**（`components/image`）：GD + Imagick 双驱动，由配置选择

媒体库、封面图绕不开的能力：缩放、裁切、格式转换、质量压缩、水印。

| 驱动 | 特点 |
| --- | --- |
| `gd` | PHP 内置，几乎无处不在；不支持动图，重采样质量略差 |
| `imagick` | 质量更好（Lanczos）、支持动图与更多格式；需额外安装 |
| `auto`（默认） | 装了 Imagick 用 Imagick，否则 GD，都没有时明确报错 |

**操作延迟执行**：`resize()->crop()->save()` 只解码一次、编码一次。
大图解码占几十 MB，每步都读写文件代价太大。

三种尺寸语义：

- `resize(w, h, 'fit')` — 等比装入框内，**不放大**
- `resize(w, h, 'exact')` — 强制目标尺寸，可能变形
- `cover(w, h)` — 等比填满后居中裁切，结果一定是目标尺寸（缩略图首选）

三个易错点已处理：

- **透明通道**：PNG → JPEG 前填白，否则透明区变黑
- **EXIF 方向**：手机竖拍 `Orientation=6` 会横躺，按方向旋转
- **内存预检**：解码内存 = 宽×高×通道×1.8，与文件大小无关

### 修复

- `fit` 模式未限制放大比例：100×100 装进 400×400 被放大成 400×400
- `imagedestroy()` 在 PHP 8.5 已废弃（8.0 起无效果），改由 GC 回收

## [0.33.1] - 2026-10-30

### 修复

- `Message::plainText()` 块级标签未替换为换行，
  `<p>标题</p><blockquote>引用</blockquote>` 剥离后粘成一行。
  补 blockquote / div / li / h1-h3 / tr

## [0.33.0] - 2026-10-30

### 新增

- **邮件组件**（`components/mail`）：配合队列异步发送

| 部件 | 职责 |
| --- | --- |
| `Message` | 标量属性，可 JSON 序列化进队列；`plainText()` 从 HTML 剥离 |
| `MailerInterface` | 失败必须抛异常——队列靠异常判断是否重试 |
| `SmtpMailer` | `fsockopen` 手写 EHLO / AUTH LOGIN / DATA，正文 base64 |
| `LogMailer` | 只写日志，本地开发与测试用 |

### 明确边界

**不支持 TLS**。证书校验、SNI、协议降级手写极易出错，
而发邮件 90% 的场景是走内网 relay 或第三方 API。
需要加密通道时先部署本地 relay（postfix / mailpit）由它转发，
或自行实现 `MailerInterface`。

## [0.32.0] - 2026-10-30

### 新增

- **队列组件**（`components/queue`）：零外部依赖的 database 驱动

为「评论邮件通知」「注册验证码」这类**外部依赖且耗时**的操作提供异步与重试。
SMTP 发送可能要 1–3 秒、超时长达 30 秒，同步发就会把接口卡死。

| 部件 | 职责 |
| --- | --- |
| `Job` | 任务接口：handle / failed / tries / backoff |
| `DatabaseDriver` | 任务存表，令牌抢占 |
| `SyncDriver` | 入队即执行，仅测试与本地调试 |
| `Worker` | 取任务 → 执行 → 成功删除 / 失败释放 / 耗尽进死信 |

**原子抢占**用「令牌抢占」而非 `SELECT ... FOR UPDATE`，不需要长事务。
三种数据库支持方式不同，必须分支：

- MySQL：`UPDATE ... ORDER BY id LIMIT 1`
- SQLite：派生表子查询（默认未编译 `SQLITE_ENABLE_UPDATE_DELETE_LIMIT`）
- PostgreSQL：子查询 + `FOR UPDATE SKIP LOCKED`

### 其他

- 抢占租约 300 秒：worker 崩溃后任务不会永久卡住
- 死信表 `failed_jobs`，支持重新入队
- 5 个命令：`queue:work`（`--once` / `--max=N`）、`queue:size`、`queue:clear`、`queue:failed`、`queue:retry`
- 队列不可用（未配数据库）时给出可操作提示，而不是抛原始异常

## [0.31.0] - 2026-10-30

### 新增

- `Builder::insertMany(array $rows)`：多值 INSERT 批量写入
  - 给 1000 个用户发通知时，循环 `insert()` 是 1000 次数据库往返
  - 按 500 行分批（SQLite 绑定变量上限 999）
  - 行之间列不一致直接报错，不静默补 null

### 修复

- **`Pipeline` 未把 Request 同步回容器**（严重）

`Request` 是不可变的，中间件用 `withAttribute()` 会产生 clone，
但容器里存的是进管道前的旧实例。结果：认证中间件写入的 `auth.user`
永远读不到，`Auth::user()` / `Auth::id()` 恒为 `null`。

表现很隐蔽——控制器里"取当前用户"拿到 0/null，业务逻辑静默走错分支，
不报错也难以从日志看出。

修复方式：Pipeline 每次往下传时 `$container->instance(Request::class, $request)`。

这个缺陷在 v0.20 引入 `AuthManager::user()` 时就存在，
但当时只测了"未登录返回 null"，没测"登录后能拿到"。

## [0.30.0] - 2026-10-30

### 新增

- `Response::stream(Closure $producer, array $headers = [], int $status = 200)`：流式响应

给示例项目加 CSV 导出时压出来的缺口：`Response::file()` 用 `file_get_contents()`
一次性读入内存，导出 2 万行 CSV 峰值 **24 MB**，10 万行会直接把 FPM 打爆。

- 内容边生成边发送，内存占用与数据量无关
- `isStreamed()` 便于测试与中间件判断
- `send()` 的流式分支先清空输出缓冲再执行回调——否则每块内容仍会先堆在 PHP 缓冲里，等于没省

实测（20005 篇文章）：

| 方式 | 峰值内存 |
| --- | --- |
| 一次性读取 | 24.0 MB |
| 生成器流式 | < 1 KB |

## [0.29.0] - 2026-10-30

### 新增

- 聚合查询：`selectRaw()` / `groupBy()` / `groupByRaw()` / `sum()` / `avg()` / `max()` / `min()`

给示例项目加统计看板时压出来的缺口：Builder 连「每个分类有多少篇文章」都写不了，
只能把全表捞到 PHP 里数——数据量一大就是内存炸弹。

安全边界：`selectRaw` / `groupByRaw` 跳过标识符校验，
注释明确「只接受代码里写死的表达式，不要拼接用户输入」；`groupBy` 仍走校验。

### 修复

- `sum()` 未做数值转换：PDO 对 SUM/AVG 一律返回字符串（即使列是整数），
  声明 `int|float` 的方法直接返回会抛 TypeError

### 已知限制

- `paginate()` 内部的 count 不感知 GROUP BY，分组查询请用 `get()` 自己处理分页

## [0.28.1] - 2026-10-30

### 修复

- 路由参数未做 URL 解码：中文 slug（`新写的文章`）经 `encodeURIComponent` 后
  请求 `/posts/%E6%96%B0...`，编码形式被原样传进控制器，查库永远匹配不到，
  表现为「接口一直 404」。改为 `rawurldecode`（`+` 保持字面加号）

这个缺陷只有用非 ASCII 数据测才会暴露——之前的测试数据全是英文。

## [0.28.0] - 2026-10-30

### 新增

- `Builder::increment()` / `decrement()`：原子自增（`SET col = col + ?`）。
  点赞计数必须用它——先读后写在并发下会丢更新（两个请求都读到 10，各写 11）
- `Blueprint::uniqueIndex()`：复合唯一索引，`(post_id, user_id)` 保证一人一赞
- `Schema::connection()`：迁移可执行原生 SQL。改表（加字段）目前只能这样，
  是刻意边界而非遗漏
- `OptionalAuth` 中间件 + `auth.optional` 别名：公开接口识别可选登录态
  （文章列表要返回「我是否点过赞」，但不能强制登录；Token 失效时静默当游客）

## [0.27.0] - 2026-10-30

### 修复

- `RedisStore::flush()` 在前缀为空时会把 pattern 拼成 `*`，等于**清空整个 Redis 库**
  （同一实例通常还跑着别的应用）。现在直接抛 `CacheException` 并提示去配 `cache.prefix`

### 记录

Redis 的跨 SAPI 行为已实测（与 APCu 对照）：

| 场景 | Redis | APCu |
| --- | --- | --- |
| FPM 跨 worker 共享 | ✅ | ✅ |
| CLI 与 FPM 共享 | ✅（同一个 Redis） | ❌（不同内存段） |

CLI 读不到 FPM 写的值，不是 Redis 不共享，而是**域前缀不同**：
数据在 `sr:web:key`，CLI 查的是 `sr:key`。补齐 `DomainContext` 后即可读到。

## [0.26.1] - 2026-10-30

### 新增

- APCu 驱动实测通过：跨 FPM worker 共享（worker A 写、worker B 读）
- 测试覆盖两条分支：不可用时明确报错、可用时完整读写

### 变更

- `sr cache:clear` 在默认驱动为 `apcu` 时提示：CLI 与 FPM 使用不同内存段，
  该命令清不到 FPM 已写入的条目
- `ApcuStore` 类注释补齐两条实测限制：CLI 不共享内存段、`apc.enable_cli` 需显式开启

### 说明

`apc.enable_cli` 是 `PHP_INI_SYSTEM`，无法 `ini_set`，
CLI 跑真实分支需 `php -d apc.enable_cli=1 tests/run.php`。

## [0.26.0] - 2026-10-30

### 修复

首次用真实 PostgreSQL 18.6 跑框架，迁移连续失败三次：

- `migrations` 表的 `executed_at` 用 `DATETIME`（MySQL 语法），PG 无此类型
  → 按驱动改用 `TIMESTAMP`。原代码 `driver === 'sqlite' ? 'DATETIME' : 'DATETIME'`
  两边一样，是从未被执行过的死分支
- `boolean` 列默认值生成为 `1`/`0`，PG 的 `BOOLEAN DEFAULT 1` 报类型不匹配
  → 改为 `TRUE`/`FALSE`（三种数据库都接受）
- `ConnectionManager` 仍用旧签名 `new ConnectionException($msg, 0, $e)`
  → v0.23 改了构造签名但漏改调用点，连不上库时抛 `TypeError`

### 新增

- `tests/Integration/PostgresDialectTest`：建表方言 / 类型映射 / 事务回滚，
  无 PG 环境时自动跳过

## [0.25.0] - 2026-10-30

### 新增

- `tests/Unit/DataFormatContractTest`：DF-1～DF-6 数据格式契约（11 个用例）
  - 框架保证：布尔输出 `true`/`false`、空数组 `[]` 与空对象 `{}` 区分、
    `null` 字段保留键、不可序列化数据抛 `JsonException`
  - 业务责任（测试记录边界）：大整数 ID 转 string、金额用 int、枚举用字符串
- `CHANGELOG.md`（32 个版本按 Keep a Changelog 整理）
- `LICENSE`（MIT）

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
