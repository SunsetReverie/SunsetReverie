# 命令行

```bash
php bin/sr <command> [options]
php bin/sr help              # 查看全部
```

在项目根目录执行。`bin/sr` 是转发到框架的 shim，不需要全局安装。

## 全部命令

| 分类 | 命令 |
| --- | --- |
| 项目 | `new` `serve` `about` `help` |
| 路由 | `route:list` `route:cache` `route:clear` `route:mode` |
| 配置 | `config:cache` `config:clear` `key:generate` `jwt:secret` |
| 优化 | `optimize` `optimize:clear` |
| 自检 | `env:check` `lint:architecture` `lint:isolation` |
| 数据库 | `migrate` `migrate:rollback` `migrate:status` `migrate:fresh` `db:show` `db:seed` |
| 队列 | `queue:work` `queue:size` `queue:clear` `queue:failed` `queue:retry` |
| 调度 | `schedule:run` `schedule:list` |
| 生成器 | `make:migration` `make:controller` `make:model` `make:middleware` `make:request` `make:seeder` `make:domain` |
| 其他 | `cache:clear` `domain:list` `upload:prune` |

---

# 项目

## `new`

生成项目骨架。

```bash
php bin/sr new myapp                        # 单域
php bin/sr new myapp --domains=admin,web    # 多域
php bin/sr new myapp --force                # 目录已存在时强行覆盖
```

| 参数 | 说明 |
| --- | --- |
| `[目录]` | 目标目录，默认 `myapp` |
| `--domains=a,b` | 生成多域结构（`app/Api/{域}/Controller`） |
| `--force` | 目录非空时覆盖 |

::: warning `--force` 会删掉目标目录里的东西
只在确认目录可清空时用。
:::

## `serve`

启动 PHP 内置服务器（开发用）。

```bash
php bin/sr serve
php bin/sr serve --host=0.0.0.0 --port=8080
```

| 参数 | 默认 | 说明 |
| --- | --- | --- |
| `--host` | `127.0.0.1` | 监听地址 |
| `--port` | `8000` | 端口 |

**只用于开发**——单进程、无并发、无 HTTPS。生产用 Nginx + PHP-FPM。

改代码后**不需要重启**（每次请求重新加载），但改了 `.env` 需要。

## `about`

显示环境与框架信息。

```bash
php bin/sr about
```

输出 PHP 版本、框架版本、运行环境、关键扩展是否安装。

排查「为什么这里行为不对」的第一步——先确认版本。

## `help`

```bash
php bin/sr help
```

---

# 路由

## `route:list`

列出已注册的路由。**排查 404 的第一站**。

```bash
php bin/sr route:list
php bin/sr route:list --domain=admin     # 只看某个域
php bin/sr route:list --json             # JSON，供脚本处理
```

输出：

```text
方法    URI                  名称                 中间件
GET     /admin/v1/posts      admin.v1.post.index  auth
POST    /admin/v1/posts      admin.v1.post.store  auth
```

| 参数 | 说明 |
| --- | --- |
| `--domain=` | 按域过滤 |
| `--json` | 输出 JSON |

## `route:cache` / `route:clear`

```bash
php bin/sr route:cache     # 生成 storage/compiled/routes.php
php bin/sr route:clear     # 删除
```

::: danger 缓存生效时不再执行路由文件
改了 `routes/*` 必须重新 `route:cache`，否则线上跑的还是旧的。

**症状**：新加的路由 404，本地却好的。

开发环境（`APP_ENV=local`）自动忽略缓存，所以这个问题通常只在生产暴露。
:::

## `route:mode`

切换路由组织模式。

```bash
php bin/sr route:mode                    # 查看当前模式
php bin/sr route:mode single             # 切到单文件模式
php bin/sr route:mode directory          # 切到目录模式
php bin/sr route:mode directory --dry-run # 只看会改什么
```

它改的是 `config/api.php` 里的 `routing_mode`。

**两种模式的区别**见[路由](./routing#两种模式)。切完记得检查目录结构——
框架启动时会校验，配错直接报错。

---

# 配置

## `config:cache` / `config:clear`

```bash
php bin/sr config:cache     # 把所有配置合并成一个 PHP 数组缓存
php bin/sr config:clear     # 删除
```

**生产必做**：不用每次请求都解析 15 个配置文件 + 读 `.env`。

::: warning 缓存后改 `.env` 不生效
```bash
php bin/sr config:clear     # 改完 .env 要清缓存
php bin/sr config:cache
```
:::

## `key:generate`

生成 `APP_KEY` 并写入 `.env`。

```bash
php bin/sr key:generate          # 生成并写入
php bin/sr key:generate --show   # 只打印，不写入
```

`APP_KEY` 用于加密（比如签名 URL）。**每个项目必须有一个，且不能泄露。**

`sr new` 时已经生成过了，只有手动改 `.env` 或迁移项目时才需要重新跑。

## `jwt:secret`

生成 `JWT_SECRET` 并写入 `.env`。

```bash
php bin/sr jwt:secret
php bin/sr jwt:secret --show
```

::: danger 换 secret 会让所有已发 token 立即失效
用户会全体掉线。换之前想清楚：是**所有** token 都有安全问题，
还是只换某几个用户？后者应该让那几个用户的 token 失效，不是换全局密钥。
:::

---

# 优化

## `optimize` / `optimize:clear`

```bash
php bin/sr optimize          # config:cache + route:cache
php bin/sr optimize:clear    # 清除两者
```

生产部署的最后一步。

**部署流程**：

```bash
git pull
php bin/sr migrate
php bin/sr optimize       # ← 这步
# reload php-fpm
```

改代码后如果没跑 `optimize:clear` + `optimize`，可能出现「配置改了没生效」。

---

# 自检

## `env:check`

环境自检。

```bash
php bin/sr env:check
```

检查：PHP 版本、必需扩展、目录可写、`APP_KEY` 是否设置、`APP_DEBUG` 在生产是否关闭。

输出：

```text
✓ PHP 8.5.2（要求 >= 8.3）
✓ 扩展 json / mbstring / pdo / openssl
✓ storage/logs 可写
✗ APP_KEY 为空，请执行 sr key:generate
```

**任何一项 ✗ 都会导致运行时出问题**，部署后先跑这个。

## `lint:architecture`

架构检查（8 项）。

```bash
php bin/sr lint:architecture
```

检查内容：

| # | 检查 |
| --- | --- |
| ARCH-1 | 核心不依赖具体组件 |
| ARCH-2 | 依赖方向单向 |
| ARCH-3 | 组件之间无循环依赖 |
| ARCH-4 | 内核无 `if ($driver === 'xxx')` 类分支 |
| ARCH-5 | 内核无 `if ($domain === 'xxx')` 类分支 |
| ARCH-6 | 内核不直接 `new` 组件具体类 |
| ARCH-7 | Facade 的 `@method` 与实现一致 |
| ARCH-8 | 组件目录与 autoload 映射表一致 |

**在业务项目里跑也能用**（只检查框架代码，不检查 `app/`）。

## `lint:isolation`

域隔离检查。

```bash
php bin/sr lint:isolation
```

检查三个域之间有没有互相引用（`app/Api/Admin` 不该 import `app/Api/Web`）。

适合放进 CI。

---

# 数据库

## `migrate`

执行未运行的迁移。

```bash
php bin/sr migrate
```

按文件名时间戳顺序执行，记录在 `migrations` 表。

## `migrate:rollback`

回滚最近批次。

```bash
php bin/sr migrate:rollback
php bin/sr migrate:rollback --step=2     # 回滚最近 2 批
```

| 参数 | 默认 | 说明 |
| --- | --- | --- |
| `--step=N` | `1` | 回滚几批 |

**回滚顺序是反的**：最后执行的迁移最先回滚。

## `migrate:status`

```bash
php bin/sr migrate:status
```

列出哪些跑了、哪些没跑、最后一个批次号。

## `migrate:fresh`

删掉所有表再重建。

```bash
php bin/sr migrate:fresh           # 拒绝执行
php bin/sr migrate:fresh --force   # 实际执行
```

::: danger 会丢数据
不加 `--force` 拒绝执行是保护。**只在开发环境用，生产绝对不要跑。**
:::

## `db:show`

```bash
php bin/sr db:show
```

输出连接信息（driver / host / database）与表概览（表名、行数、大小）。

排查「连的是哪个库」很有用——尤其是有多个 `.env` 的时候。

## `db:seed`

```bash
php bin/sr db:seed            # 执行 scripts/seed.php
php bin/sr db:seed Demo       # 执行 scripts/Demo.php
```

| 参数 | 默认 | 说明 |
| --- | --- | --- |
| `[名字]` | `seed` | 对应 `scripts/{名字}.php` |

文件不存在会报错，不会静默跳过：

```text
种子脚本不存在：scripts/Demo.php
```

种子脚本用 `make:seeder` 生成骨架。

---

# 队列

## `queue:work`

消费队列。

```bash
php bin/sr queue:work                    # 常驻，Ctrl+C 退出
php bin/sr queue:work --once             # 处理到队列空就退出
php bin/sr queue:work --max=100          # 最多处理 100 个
php bin/sr queue:work --queue=export     # 指定队列名
```

| 参数 | 说明 |
| --- | --- |
| `--once` | **处理到空**就返回（不是处理一个） |
| `--max=N` | 最多处理 N 个 |
| `--queue=` | 队列名，默认取 `config('queue.default_queue')` |

::: warning `--once` 不是「处理一个」
`--once` 调的是 `workUntilEmpty()`——把队列里所有任务处理完才退出。

只想处理一个用于调试的话，用 `--max=1`。
:::

## `queue:size`

```bash
php bin/sr queue:size
```

待处理任务数。

## `queue:clear`

```bash
php bin/sr queue:clear
```

清空队列（**不含死信**）。

## `queue:failed`

```bash
php bin/sr queue:failed
```

列出死信（超过重试次数仍失败的任务），含异常信息。

## `queue:retry`

```bash
php bin/sr queue:retry <id>     # 单条重新入队
php bin/sr queue:retry --all    # 全部重新入队
```

::: warning 不要直接 `--all`
如果死信里全是同一个错误，说明下游挂了或配置错了——重新入队会立刻再失败一轮，
把下游打得更惨。**先修根因。**
:::

---

# 调度

## `schedule:run`

```bash
php bin/sr schedule:run
```

执行所有到期任务。

**由 cron 每分钟调用**：

```cron
* * * * * cd /path/to/project && php bin/sr schedule:run >> /dev/null 2>&1
```

任务定义在 `app/Console/Schedule.php`。

## `schedule:list`

```bash
php bin/sr schedule:list
```

列出所有定时任务与下次执行时间。调 cron 之前先跑这个确认任务被识别了。

---

# 生成器

七个 `make:*` 命令，生成的路径各不相同：

| 命令 | 生成位置 | 示例 |
| --- | --- | --- |
| `make:migration` | `database/migrations/` | `make:migration create_posts_table` |
| `make:controller` | `app/Api/{域}/Controller/` | `make:controller Admin/PostController` |
| `make:model` | `app/Domain/{名}/` | `make:model Post` |
| `make:middleware` | `app/Http/Middleware/` | `make:middleware RequireVerified` |
| `make:request` | `app/Api/{域}/Request/` | `make:request Admin/StorePostRequest` |
| `make:seeder` | `scripts/` | `make:seeder DemoSeeder` |
| `make:domain` | `routes/` + `app/Api/{域}/Controller/` | `make:domain report` |

::: tip `make:model` 生成的是「领域服务」不是「ORM 模型」
```bash
php bin/sr make:model Post
```

生成 `app/Domain/Post/PostService.php`——没有 ORM，所以没有「模型类」这种东西。

命名沿用 `model` 是为了照顾习惯，实际内容是一个空的服务类。
:::

`make:controller` / `make:request` 的斜杠前缀是**域名**：

```bash
php bin/sr make:controller Admin/PostController
# → app/Api/Admin/Controller/PostController.php
```

单域项目不用加域名前缀（生成到 `app/Controller/`）。

---

# 其他

## `cache:clear`

```bash
php bin/sr cache:clear
```

清空**默认缓存驱动**。

::: warning 会清掉限流计数
限流也走缓存，`cache:clear` 之后所有计数归零——攻击者可以借此重置配额。

生产环境慎用，或者用 `RATELIMIT_STORE` 把限流放到独立的 store。
:::

## `domain:list`

```bash
php bin/sr domain:list
```

列出 `config/api.php` 里配置的所有域：域名、前缀、版本、中间件、错误码段位。

## `upload:prune`

```bash
php bin/sr upload:prune
```

清理超过 `ttl`（默认 24 小时）还没合并的分片上传会话。

适合放进 cron 每天跑一次：

```cron
0 3 * * * cd /path/to/project && php bin/sr upload:prune
```

---

# 常用组合

## 新项目初始化

```bash
php bin/sr new myapp
cd myapp
php bin/sr env:check
php bin/sr migrate
php bin/sr serve
```

## 生产部署

```bash
git pull
php bin/sr env:check          # 环境没问题吗
php bin/sr migrate            # 表结构
php bin/sr optimize           # 配置 + 路由缓存
killall -USR2 php-fpm         # 重载
php bin/sr queue:size         # 队列没堵吧
```

## 排查 404

```bash
php bin/sr route:list | grep 你的路径
php bin/sr route:clear        # 是不是缓存了旧路由
```

## 排查「改了没生效」

```bash
php bin/sr config:clear && php bin/sr route:clear
killall -USR2 php-fpm         # opcache
supervisorctl restart queue-worker   # 如果改的是 Job
```

---

# 注意事项

## 没有 `queue:restart`

Laravel 有 `queue:restart`（让 worker 自己退出重启），这个框架**没有**。

| worker 启动方式 | 重启方法 |
| --- | --- |
| supervisor 托管 | `supervisorctl restart queue-worker` |
| 自己 `nohup` 起的 | `kill` 后重新启动 |

## 所有命令都在项目根执行

`bin/sr` 靠 `dirname(__DIR__)` 定位框架，再靠当前工作目录定位项目。

在别的目录执行会读不到 `.env` 和 `config/`。

## 下一步

- **[门面](./facades)** —— 代码里能直接调的静态 API
- **[配置](./config)** —— 54 个配置键
- **[部署](./deployment)** —— Nginx / FPM / cron 的完整配置
