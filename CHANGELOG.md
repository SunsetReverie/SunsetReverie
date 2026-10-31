# 更新日志

格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，版本号遵循[语义化版本](https://semver.org/lang/zh-CN/)。

当前处于 **beta** 阶段（`1.0.0-beta.x`）：功能完整、可用于生产试用，
但 API 仍可能微调。破坏性变更会发 `beta.2` 并在此注明，升级前请读对应条目。

## [Unreleased]

### 变更

- 成功响应的 `message` 默认值：`ok` → `success`

```json
{"code":0,"message":"success","data":...,"request_id":"...","timestamp":...}
```

只改默认值，业务仍可传自定义消息：

```php
return $this->success($data, '创建成功');
```

**影响面**：前端如果判断了 `message === 'ok'`，需要改成 `'success'`。
更稳妥的做法是**只判断 `code === 0`**——业务码是契约，`message` 只是展示文案，
以后还可能变。

### 待办

- 待办见文档 18.3.0「当前验收状态」的缺口列表

## [1.0.0-beta.2] - 2026-10-31

### 新增

- **`directory` 路由模式真正可用**（此前 `routing_mode` 只是个配置项，运行时无人读取）

```text
routes/
└── admin/
    ├── health.php       → /admin/health
    ├── v1/posts.php     → /admin/v1/posts
    └── v2/posts.php     → /admin/v2/posts
```

规则：

- **文件名不进 URL**（`posts.php` 里的 `/list` 就是 `/admin/list`）
- **版本目录自动加前缀**（`v1/` → `/v1` + `v1.`）
- **目录最深两层**，更深的目录不扫描
- **字母序加载**（显式 `sort`，不依赖 `glob` 顺序）
- 子文件可直接写 `Route::get(...)`，不必 `return` 闭包

### 新增：6 项启动校验

二选一的约束现在**强制执行**，配错立即报错而不是静默失效：

| 场景 | 错误 |
| --- | --- |
| `single` 但存在目录 | `routing_mode=single，但存在目录 routes/admin/` |
| `directory` 但存在单文件 | `routing_mode=directory，但存在单文件 routes/admin.php` |
| `directory` 但缺目录 | `routing_mode=directory，但缺少目录 routes/admin/` |
| 版本目录未声明 | `routes/admin/v3/ 不是已声明的版本` |
| 子文件设 prefix | `directory 模式下不允许在路由文件里设置 prefix` |
| `routing_mode` 值非法 | `只能是 single 或 directory` |

报错信息都带「改哪里、怎么改」。

### 修复

- **未命名路由的 name 退化成组前缀**

```php
Route::get('/a', ...);      // 旧：name = 'admin'
Route::get('/b', ...);      // 旧：name = 'admin'   ← 完全重名
```

路由名是限流配置（`ratelimit.routes`）和访问日志的 key，重名会命中错误规则。
现在不传名就是 `null`。

- `Route` 门面 `@method` 注解补上 `any()`

### 说明

`as` 与 `prefix` 的分工在文档里写清了：

- `prefix` 改 URL，`as` 只改路由名
- 子文件**禁 prefix、允许 as**——混乱来自路径，`as` 不影响 URL

新增 `tests/Integration/DirectoryRoutingTest`（9 个用例）覆盖上述全部行为。

## [1.0.0-beta.1] - 2026-10-31

### 发布说明

首个 beta：**功能完整、可用于生产试用，但 API 仍可能微调**。

与 0.45.0 相比**没有代码变更**——这次改的只是版本号语义。

### beta 意味着什么

| 维度 | 承诺 |
| --- | --- |
| **可以用** | 13 条发布门禁全绿、338 用例、PHPStan level 8、三库验证、真实项目全接口回归 |
| **还可能变** | 试用中发现设计问题会发 `beta.2`，不需要升大版本 |
| **不建议** | 基于 beta 做长期 API 兼容承诺 |

排序上 `1.0.0-beta.1 < 1.0.0`，所以从 beta 升到正式版不需要特殊动作，
确认稳定后直接发 `1.0.0` 即可。

### 为什么是 beta 而不是 1.0.0

`1.0.0` 在 SemVer 里意味着「API 冻结」——之后任何破坏性变更都必须升 `2.0.0`。

而这个框架从 `0.28` 到 `0.45` 一共有 3 次破坏性变更
（`selectRaw` 的 `*`、`unique('col')` 语义、路由参数 urldecode），
平均每 6 个版本一次，说明**设计还在收敛期**。

现在冻结 API 为时过早：该测的都测了，剩下能发现问题的只有真实使用。
beta 的定位正是「可以先拿去用，发现问题我还能改」。

### 验证状态

| 项 | 结果 |
| --- | --- |
| 单元测试 | 338 用例 / 889 断言（PHP 8.3 / 8.4 / 8.5 三版本全过） |
| 静态分析 | PHPStan **level 8** 零错误 |
| 架构检查 | 8 项全过 |
| 覆盖率 | `core`（除 CLI）82.7% / `components` 75.6% |
| 数据库方言 | SQLite / MySQL / PostgreSQL 各 4/4 |
| 真实项目 | 63 条路由 / 20 张表，全接口回归 47 项断言通过 |
| 发布门禁 | **13 / 13** |

## [0.45.0] - 2026-10-31

### 变更

- PHPStan 从 level 7 升到 **level 8**，零错误

### 说明

原本打算把门禁从 level 8 降到 level 7，理由是「level 8 大概只会报一堆
`mixed` 断言噪音、收益不高」。但实际跑了一次，只报 **5 个**错误，
全是 `offsetAccess.notFound`：

| 位置 | 问题 |
| --- | --- |
| `DialectContractTest:158` | `$row['price']` 在 `array\|null` 上访问 |
| `DatabaseTest:103` | `$first['name']` 同上 |
| `QueueTest:84/85/86` | `$job['payload']` / `['attempts']` 同上 |

根因和 `ImageTest` 那次一样：`assertTrue($x !== null)` **不会让静态分析
收窄类型**，必须用 `fail()`（返回 `never`）的 if 分支。改完 level 8 立即归零。

### 一条值得记的教训

**不能凭「预期」决定是否降低标准。**

这次的猜测（level 8 ≈ 大量噪音）和实际（5 个错误、10 分钟修完）差了很远。
跑一次的成本是 3 秒，凭猜测降标准的代价是**永远不知道本来能不能做到**。

下次遇到「这个标准可能不现实」的判断，先跑一次拿数据，再决定改不改。

## [0.44.0] - 2026-10-31

### 变更

- PHPStan 从 level 6 升到 **level 7**，零错误（27 个问题全部修掉）

### 修复（level 7 暴露的类型问题）

**生产代码 14 项**

- `Connection::select()` 用 `array_values()` 保证「列表」语义
- `Connection::affect()` 的 `$bindings` 从 `list<mixed>` 放宽为 `array<int, mixed>`
  （调用方传的是普通数组，两者在静态分析里不等价）
- `Connection::lastInsertId()` 处理 `PDO::lastInsertId()` 返回 `false` 的情况
- `Blueprint::modifyLast()` 避免 `$arr[$i] .= $s`——那种写法会让静态分析
  认为 `list<string>` 变成「可能稀疏的数组」，改为弹出再追加
- `GdDriver::canvas()` 对宽高做 `max(1, ...)`（`imagecreatetruecolor` 要求正整数，
  等比缩放到极小值时算出来可能是 0）
- `Container::build()` 加 `class_exists()` 检查（`ReflectionClass` 需要 `class-string`）
- `Application::reflectMethod()` 改用 `ReflectionMethod::getClosure()`——
  数组形式 `[$obj, $method]` 在静态分析里不满足 `callable` 约束
- `Application` 的中间件列表加类型注解
- `ProviderRepository` 的属性与返回类型从 `class-string<ServiceProvider>` 放宽为 `string`
  （类名来自配置，无法在编译期保证）
- `HandleCors` 的 `$allowedOrigins` 放宽为 `array<int, mixed>`
- `Request::$post` 与 `bodyData()` 补齐值类型
- `Router::groupMiddleware()` 逐项转 `string` 保证 `list<string>`

**测试代码 13 项**

- `ImageTest` 新增 `requireImage()`：`assertTrue($x !== false)` **不会**让静态分析
  收窄类型，必须用 `fail()`（返回 `never`）的 if 分支
- `ChunkedUploadTest` 的 `str_split` 长度做 `max(1, ...)`
- `UploadTest` 的 query 值加 `is_string()` 检查

### 说明

level 6 → 7 的差异是「更严格的类型精度」：level 6 只看明显错误，
level 7 会追到「声明 `list` 但可能返回稀疏数组」这种程度。

**27 个问题里没有新的功能性 bug**——说明 level 6 已经覆盖了真正的运行时风险，
level 7 主要是让类型声明更精确，减少「文档说 A、实现是 B」的偏差。

## [0.43.1] - 2026-10-30

### 修复

- `ARCH-8` 在找不到 `autoload.php` 时只输出 PHP warning，而不是明确报违规

用 `php -n`（不加载 php.ini，模拟干净环境）跑测试时发现：

```text
Warning: file_get_contents(.../core/Foundation/autoload.php):
         Failed to open stream: No such file or directory
```

warning 会被 `error_reporting`、SAPI、错误处理器差别对待——
有时是噪声、有时被静默吞掉。这类「配置错了」应该显式说出来。

改为返回带具体路径的违规信息（并指出 `frameworkRoot` 的值）。

### 说明

这个 bug 不是 CI 才能发现的：本地 `php -n` 就能复现，
说明「换个环境跑」这件事不依赖 GitHub Actions。

## [0.43.0] - 2026-10-30

### 修复

按文档 11.6「输入输出安全清单」逐条核对后，补齐 3 项缺口。

- **响应头 CRLF 注入**

`Response::withHeader()` 此前直接把值塞进 headers 数组。若 header 值来自用户输入：

```php
->withHeader('X-User', $request->query('name'))
// name = "alice\r\nSet-Cookie: admin=1"
```

会拆出额外的响应头（响应拆分）。PHP 的 `header()` 对换行会告警，
但告警不等于防护——换 SAPI 或关掉警告就静默失效。

现在：字段名按 RFC 7230 token 字符白名单过滤，值剥掉 `\r` `\n` `\0`。

- **分页无统一上限**

分页大小原来由各领域服务自己写 `min(50, ...)`，各写各的数字，
漏改一处就能被拉出 10 万条。

新增 `Request::perPage()` / `Request::page()`，上限读 `config('pagination.max_per_page')`。

- **请求体大小无框架层限制**

依赖 Nginx 的 `client_max_body_size`，FPM 直连、内置服务器、容器内互调都没有这层保护。

新增 `EnsureBodySize` 中间件（别名 `body.size`）：只看 `Content-Length`，
超限直接 413，**不读 body**——读完再判断正是要避免的内存问题。
分块传输（无 Content-Length）放行，由 SAPI 的 `post_max_size` 兜底。

### 说明

11.6 的 10 项里，另有 2 项标为「不适用」而非「未实现」：

- SSRF：框架没有 HTTP 客户端组件，没有可被利用的出口
- `Path::safeJoin`：当前无「用户提供路径」的文件操作场景

这两项的前提一旦变化（新增 HTTP 客户端 / 文件下载功能）必须重估。

### 新增

- `skeleton/config/pagination.php`、`skeleton/config/http.php`
- `tests/Unit/SecurityHardeningTest.php`（6 个用例）

## [0.42.0] - 2026-10-30

### 新增

- 测试运行器支持行覆盖率：`--coverage` / `--coverage-min=N` / `--coverage-detail=Xxx`
- 13 个命令行端到端测试（`tests/Feature/ConsoleCommandTest.php`）

### 修复

- **`sr lint:architecture` 在业务项目里检查的是项目根而非框架根**

`ConsoleKernel` 把 `$app->basePath()`（项目根）传给 `ArchitectureChecker`，
而架构检查扫描的是框架自身的 `core/` 与 `components/`。

在框架仓库里跑时两者相同，看不出问题；在业务项目里跑（`bin/sr` 是 shim）时，
`ARCH-8` 读 `core/Foundation/autoload.php` 直接抛 warning：

```text
file_get_contents(.../core/Foundation/autoload.php): Failed to open stream
```

改用 `dirname(__DIR__, 2)` 推导框架根——shim 场景下 `__DIR__` 仍指向框架目录。

- **`sr make:migration` 在未配置数据库时失败**

```text
生成失败：未找到数据库连接配置 [mysql]，请在 config/database.php 中定义
```

根因：`Migrator` 构造强制要求 `Connection`，而 `make()` 只用到 `directory`。
新建项目最先要用的命令之一，却要求先配好数据库。

抽出 `Migrator::createFile(directory, name)` 静态方法，`makeMigration` 直接调用。

### 覆盖率现状（首次测量）

| 范围 | 覆盖率 | 行数 |
| --- | --- | --- |
| **core（除 Console）** | **82.2%** | 1155 / 1405 |
| components | 75.6% | 1994 / 2638 |
| CLI 层（core/Console） | 42.0% | 563 / 1341 |
| **合计** | **68.9%** | 3712 / 5384 |

CLI 层是主要缺口：40 个命令的实现占了 1341 行，
且子进程执行的测试不会被父进程 pcov 统计（跨进程限制）。

## [0.41.0] - 2026-10-30

### 新增

- `scripts/ci.sh`：本地 CI，一条命令跑完 环境 → 语法 → 测试 → 架构 → 静态分析 → 方言
- `.github/workflows/ci.yml`：三个 job（test 矩阵 / static / database）
- `tests/Integration/DialectContractTest`：同一套 4 个断言在 SQLite / MySQL / PostgreSQL 上跑

### 修复

- **`selectRaw()` 首次追加时清掉默认的 `*`**

`Builder` 默认 `$columns = ['*']`，而 `selectRaw()` 是追加语义，于是
`table('t')->selectRaw('COUNT(*) AS n')->groupBy('x')` 生成
`SELECT *, COUNT(*) AS n FROM t GROUP BY x`。

MySQL 在 `ONLY_FULL_GROUP_BY` 关闭时容忍，**PostgreSQL 直接报**
`column "t.id" must appear in the GROUP BY clause`。

这个差异只有真跑 PG 才会暴露——跨库契约测试抓到的第一例。

### 说明

之前「三库测试」是手工在临时项目验证的，PG 服务一重启就没了。
现在由 CI 的 service 容器每次新建，且测试读 `SR_TEST_DB` 环境变量。

## [0.40.0] - 2026-10-30

### 变更

架构检查从「4 条」补齐到「8 项」，并修正文档与实现的语义错位。

文档 16.3.1 承诺的 8 条里，有 2 条**前提在框架里根本不存在**：
没有 `Contracts\` 目录（契约以 `XxxInterface` 平铺在各组件内），
也没有 `domain_providers` 配置项。而「驱动分支数必须为 0」也不现实——
任何驱动注册都需要默认分支或注册表。

重写后：

| 编号 | 检查项 |
| --- | --- |
| ARCH-1～4 | 依赖方向（原有，措辞对齐实现） |
| ARCH-5/6 | Vendor 共存（标 N/A，由 `VendorCoexistTest` 覆盖） |
| ARCH-7 | Facade `@method` 与底层类一致（新增） |
| ARCH-8 | 组件目录与 autoload 映射表一致（新增） |

### 修复

ARCH-8 立刻报出 `autoload.php` 登记了 `Console => components/console`，
该目录不存在。注册处有 `is_dir()` 保护所以当前无害，但一旦有人真建了那个目录，
就会**静默覆盖**内核的 `SunsetReverie\Console\*` 映射。已删除该幽灵条目。

## [0.39.0] - 2026-10-30

### 新增

- `phpstan.neon`（level 6）+ `.gitignore` 忽略 `phpstan.phar`
- 文档 `docs/v1.0-checklist.md`：13 条门禁的进度与做法

### 修复

首次跑 PHPStan 报 65 个错误，全部处理。其中三个是真 bug：

- **`Worker::$sleepSeconds` 只写不读**：常驻模式在 CLI 里硬编码 `sleep(1)`，
  构造参数形同虚设。新增 `Worker::work()` 返回 Generator，把节奏收回 Worker 层
- **`LogMailer` 调用不存在的 `LogManager::info()`**：靠 `__call` 兜住没崩，
  但静态分析在该类上完全失效。改为显式 `channel()->info()`
- **`ApiSurfaceTest` 里两个测试方法被误粘进别的类**：
  `testAuthUserWithoutLogin` 分别出现在 `MarkerMiddleware` 和 `DeniedRequest` 内部，
  `$this` 指向中间件而非 TestCase，`assertNull` 等调用全部落空。
  测试框架发现不了（它只扫 `test` 开头的方法，不检查方法在哪个类里）

其他修正：
- `insertGetId()` 的 `@return string` 与实现（int）不符
- `Pipeline::through()` 注解漏了「实例」形态
- Facade 的 `@method` 用裸 `array`，补成 `list<mixed>` / `array<string, mixed>`
- `EnvParseException` 的 `readonly $file/$line` 覆盖了 `Exception` 同名属性，
  会让 `getFile()/getLine()` 返回 .env 位置而非异常抛出位置
- `tempnam()` 可能返回 `false` 被直接传给 `ImageResult(string)`（两个图片驱动）

### 说明

PHPStan 能拦住的 5 个历史 bug 里它拦住了 4 个，
包括「`sum()` 返回 string 导致 TypeError」这类只有运行到才会炸的问题。

## [0.38.0] - 2026-10-30

### 新增

- `Blueprint::json(string $name)`：JSON 列

做文章版本历史时压出来的缺口：版本快照要存「标题 + 正文 + 摘要 + 封面 + 分类 + 标签 ID 数组」，
用列拆开的话以后加字段就得改表，而标签 ID 本身就是数组。

三种数据库的对应类型不同：

| 驱动 | 类型 | 说明 |
| --- | --- | --- |
| MySQL | `JSON` | 5.7+ 原生，带格式校验 |
| PostgreSQL | `JSONB` | 二进制存储，可建索引、查询更快 |
| SQLite | `TEXT` | 无原生类型，靠 JSON1 扩展函数 |

默认 NULL——MySQL 的 JSON 列不允许非 NULL 字面量默认值。

**边界说明**：按 JSON 内部字段过滤的语法三库不一致
（MySQL/PG 用 `->>`，SQLite 用 `json_extract`），这类查询请写原生 SQL，
不要指望 DSL 抹平差异。

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
