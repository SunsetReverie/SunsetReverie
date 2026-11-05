# 配置

## 三层结构

```text
.env                       环境变量（不提交）
  ↓  env() 读取
config/*.php               配置文件（15 个，提交）
  ↓  Config::get() / config() 读取
业务代码
```

```php
// config/database.php
'host' => env('DB_HOST', '127.0.0.1'),
```

```php
// 业务代码
config('database.default');        // 等价于 Config::get()
Config::get('database.default');
```

::: warning `.env` 不进仓库
`.gitignore` 已经排除了它。新环境部署时从 `.env.example` 复制一份：

```bash
cp .env.example .env
php bin/sr key:generate
```
:::

## 15 个配置文件

| 文件 | 管什么 |
| --- | --- |
| `api.php` | API 域、路由模式、中间件别名 |
| `app.php` | 应用名、环境、调试、时区、全局中间件 |
| `auth.php` | 守卫、JWT 密钥与有效期 |
| `cache.php` | 缓存驱动与连接 |
| `cors.php` | 跨域策略 |
| `database.php` | 数据库连接 |
| `http.php` | 请求体大小上限 |
| `image.php` | 图片驱动、质量、内存上限 |
| `log.php` | 日志通道与格式 |
| `mail.php` | 邮件驱动与 SMTP |
| `pagination.php` | 分页默认值与上限 |
| `queue.php` | 队列驱动与表名 |
| `ratelimit.php` | 限流规则 |
| `upload.php` | 上传大小、白名单、分片 |
| `validation.php` | 校验相关（当前只有 1 个键） |

### 数组结构的配置在哪详述

上表里有些文件只是「一行说明」，实际内容是一组嵌套数组。这些在对应组件文档里讲：

| 配置路径 | 详述位置 |
| --- | --- |
| `api.domains` | [路由](./routing#定义域) —— 域的 prefix / versions / middleware |
| `api.middleware_aliases` | [中间件](./middleware#写一个中间件) —— 别名注册 |
| `app.global_middleware` | [中间件](./middleware#三层绑定) —— 全局中间件顺序 |
| `auth.guards` | [认证](./auth#配置) —— 守卫与 JWT |
| `cache.stores` | [缓存](./cache#配置) —— 四个驱动的连接参数 |
| `cors.*` | 见下方「跨域」一节 |
| `database.connections` | [多数据库](./multi-database#配置) —— 三库连接 |
| `log.channels` | [日志](./log#配置) —— 五个通道 |
| `mail.mailers` | [邮件](./mail#配置) —— log / smtp |
| `queue.drivers` | [队列](./queue#配置) —— database / sync |
| `ratelimit.routes` | [限流](./ratelimit#按路由名配置) —— 按路由名覆盖规则 |
| `upload.disks` / `upload.chunk` | [上传](./upload#配置) —— 存储与分片 |

**这一节不放重复内容**——同一个配置说两遍，改的时候必有一处忘改。

---

## 环境变量总表

`.env` 里能配的全部（53 个）。

### 应用

| 变量 | 默认 | 说明 |
| --- | --- | --- |
| `APP_NAME` | `SunsetReverie` | 显示名 |
| `APP_ENV` | `local` | `local` / `test` / `staging` / `production` |
| `APP_DEBUG` | `false` | 出错时是否输出堆栈。**生产必须 false** |
| `APP_KEY` | 空 | 加密密钥，`key:generate` 生成 |
| `APP_TIMEZONE` | `Asia/Shanghai` | 时区 |

::: danger `APP_DEBUG=true` 在生产会泄露堆栈与配置
`env:check` 会检查这一项。上线前确认是 `false`。
:::

### API 域

| 变量 | 默认 | 说明 |
| --- | --- | --- |
| `API_DEFAULT_DOMAIN` | `web` | 未匹配到域时用哪个 |
| `API_ROUTING_MODE` | `single` | `single` / `directory` |

### 认证

| 变量 | 默认 | 说明 |
| --- | --- | --- |
| `AUTH_GUARD` | `jwt` | 默认守卫 |
| `JWT_SECRET` | 空 | `jwt:secret` 生成。**换它会让所有 token 失效** |
| `JWT_TTL` | `7200` | access token 有效期（秒） |
| `JWT_REFRESH_TTL` | `2592000` | refresh token 有效期（秒，30 天） |
| `JWT_REFRESH_GRACE` | `30` | 并发刷新宽限期（秒），`0` 关闭 |
| `JWT_ENFORCE_VERSION` | `false` | 开启后 `revokeAll()` 能让旧 Token 立即失效（每请求多一次缓存读） |
| `JWT_ENFORCE_AUDIENCE` | `true` | 多域项目保持开启，防止 Token 跨域复用 |

### 数据库

| 变量 | 默认 | 说明 |
| --- | --- | --- |
| `DB_CONNECTION` | `mysql` | `mysql` / `pgsql` / `sqlite` |
| `DB_HOST` | `127.0.0.1` | |
| `DB_PORT` | `3306`（mysql）/ `5432`（pgsql） | |
| `DB_DATABASE` | `app` | 库名 |
| `DB_USERNAME` | `root` / `postgres` | |
| `DB_PASSWORD` | 空 | |
| `DB_TIMEOUT` | `5` | 连接超时（秒） |
| `DB_SQLITE_DATABASE` | `storage/database.sqlite` | 仅 sqlite |

### 缓存

| 变量 | 默认 | 说明 |
| --- | --- | --- |
| `CACHE_DRIVER` | `file` | `file` / `redis` / `apcu` / `array` |
| `CACHE_PREFIX` | `sr:` | 键前缀，多项目共用一个 Redis 时隔离 |
| `CACHE_TTL` | `3600` | 默认存活秒数（`0` = 永久） |
| `REDIS_HOST` | `127.0.0.1` | |
| `REDIS_PORT` | `6379` | |
| `REDIS_PASSWORD` | 空 | |
| `REDIS_DB` | `0` | 库编号 |
| `REDIS_TIMEOUT` | `1.5` | 连接超时。**别设太大**，缓存挂了应该快速失败 |

### HTTP

| 变量 | 默认 | 说明 |
| --- | --- | --- |
| `HTTP_MAX_BODY_SIZE` | `8388608`（8MB） | `body.size` 中间件的上限 |

### 分页

| 变量 | 默认 | 说明 |
| --- | --- | --- |
| `PAGINATION_DEFAULT` | `20` | 不传 `per_page` 时的默认值 |
| `PAGINATION_MAX` | `50` | 上限，超过会被夹住 |

### 上传

| 变量 | 默认 | 说明 |
| --- | --- | --- |
| `UPLOAD_DISK` | `local` | 存储驱动 |
| `UPLOAD_MAX_SIZE` | `10485760`（10MB） | 单文件上限 |
| `UPLOAD_SIGN_SECRET` | 空 | 签名 URL 的密钥。**为空时签名可伪造** |
| `UPLOAD_CONTENT_ADDRESSED` | `false` | 用内容哈希做文件名（去重 + 秒传）。图床类项目建议开 |
| `UPLOAD_CHUNK_SIZE` | `5MB` | 分片大小 |

### 图片

| 变量 | 默认 | 说明 |
| --- | --- | --- |
| `IMAGE_DRIVER` | `auto` | `auto` / `gd` / `imagick` |
| `IMAGE_QUALITY` | `82` | JPEG 质量 |
| `IMAGE_MEMORY_MB` | `0` | 解码内存上限，`0` = 不限制 |

::: warning `IMAGE_MEMORY_MB=0` 有风险
图片解码后的内存与文件大小无关：6000×4000 的图约占 96MB。

不设上限时，一个几百 KB 的大图能把 PHP 进程撑爆。

建议设成 `memory_limit` 的 1/3。
:::

### 日志

| 变量 | 默认 | 说明 |
| --- | --- | --- |
| `LOG_CHANNEL` | `daily` | `daily` / `single` / `stderr` / `stdout` / `null` |
| `LOG_LEVEL` | `debug` | 最低级别。**生产建议 `info` 或 `warning`** |
| `LOG_DAYS` | `14` | 按天切分时保留几天（`0` = 不清理） |
| `LOG_ACCESS` | `true` | 是否记录访问日志 |

### 邮件

| 变量 | 默认 | 说明 |
| --- | --- | --- |
| `MAIL_MAILER` | `log` | `log`（不真发）/ `smtp` |
| `MAIL_HOST` | `127.0.0.1` | |
| `MAIL_PORT` | `1025` | |
| `MAIL_USERNAME` | 空 | |
| `MAIL_PASSWORD` | 空 | |
| `MAIL_FROM` | `noreply@xi.blog` | 发件地址 |
| `MAIL_FROM_NAME` | `夕梦博客` | 发件人显示名 |
| `MAIL_TIMEOUT` | `10` | SMTP 超时（秒） |

::: danger 默认 `MAIL_MAILER=log`——不会真发
开发环境方便，**生产必须改成 `smtp`**，否则用户收不到任何邮件。

而且不会有报错——邮件「成功」写进日志了。
:::

### 队列

| 变量 | 默认 | 说明 |
| --- | --- | --- |
| `QUEUE_DRIVER` | `database` | `database` / `sync` |
| `QUEUE_NAME` | `default` | 默认队列名 |

`sync` 驱动会**立即同步执行**——测试时用，生产别用。

### 限流

| 变量 | 默认 | 说明 |
| --- | --- | --- |
| `RATELIMIT_ENABLED` | `true` | 关掉等于没有限流 |
| `RATELIMIT_STORE` | 空 | 空 = 用 `cache.default` |

高并发建议单独指到 `redis`。

---

## 跨域（CORS）

```php
// config/cors.php
'allowed_origins' => [],          // ← 默认空数组 = 不启用 CORS
'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
'allowed_headers' => ['Content-Type', 'Authorization', 'X-Request-Id', 'Idempotency-Key'],
'exposed_headers' => ['X-Request-Id', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Retry-After'],
'max_age' => 86400,
'supports_credentials' => false,
```

### 默认不启用

`allowed_origins` 为空时，`cors` 中间件**不发任何 CORS 头**。

浏览器会拦掉跨域请求——这是安全的默认值：**要用才显式配**。

### 必须列完整域名

```php
// ✅ 精确列出
'allowed_origins' => [
    'https://admin.example.com',
    'https://app.example.com',
    'http://localhost:5173',      // 本地开发
],

// ❌ 不支持通配子域
'allowed_origins' => ['https://*.example.com'],
```

匹配逻辑是**精确比对**：

```php
return in_array('*', $allowedOrigins, true)
    || in_array($origin, $allowedOrigins, true);
```

`https://*.example.com` 会被当成一个普通字符串，永远匹配不上。

**子域多的话只能逐个列**（或者自己扩展中间件）。

### `*` 与 `supports_credentials` 互斥

```php
'allowed_origins' => ['*'],
'supports_credentials' => true,      // ❌ 浏览器会拒绝
```

带 `*` 时响应头是 `Access-Control-Allow-Origin: *`，
而规范禁止 `*` 与 `Allow-Credentials: true` 同时出现。

**本框架用 Bearer Token 不用 Cookie**，所以 `supports_credentials` 保持 `false` 就行。

### `exposed_headers` 决定前端能读到哪些响应头

```js
// 只有列在 exposed_headers 里的，JS 才读得到
fetch(url).then(res => {
    res.headers.get('X-RateLimit-Remaining');   // 默认能读
    res.headers.get('X-Custom');                // undefined
});
```

默认暴露了 4 个：`X-Request-Id`、`X-RateLimit-Limit`、`X-RateLimit-Remaining`、`Retry-After`。

自己加了自定义响应头要在前端读的话，记得加进 `exposed_headers`。

### `max_age` 是预检缓存

```php
'max_age' => 86400,     // 24 小时
```

浏览器记住「这个预检通过了」的时长。期间不再发 `OPTIONS` 请求。

值越大，预检越少，但改了 CORS 配置后生效越慢（用户要等缓存过期或清缓存）。

### 预检请求会被短路

```text
OPTIONS /admin/v1/posts
  → cors 中间件直接返回 204，不进入路由和鉴权
```

**这是必要的**：预检请求不带 `Authorization`，走正常流程必然 401。

### 常见错误

| 现象 | 原因 |
| --- | --- |
| 浏览器报 `No 'Access-Control-Allow-Origin'` | `allowed_origins` 是空的 |
| 配了子域通配但不生效 | 不支持通配，要逐个列 |
| 前端读不到自定义响应头 | 没加进 `exposed_headers` |
| 带 Cookie 时 CORS 全挂 | `*` 与 `supports_credentials` 冲突 |
| 改了配置要等很久才生效 | `max_age` 太长，清浏览器缓存或调小 |

## 生产必改的 6 项

```ini
APP_ENV=production
APP_DEBUG=false                    # 否则泄露堆栈
LOG_LEVEL=warning                  # debug 会写满磁盘
MAIL_MAILER=smtp                   # 默认是 log，不会真发
RATELIMIT_STORE=redis              # 限流是高频写
UPLOAD_SIGN_SECRET=<随机 64 位>     # 为空时签名可伪造
```

`env:check` 会检查其中几项。

生成随机密钥：

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

---

## 常见错误

### 改了 `.env` 不生效

```bash
php bin/sr config:clear      # 清配置缓存
killall -USR2 php-fpm        # 清 opcache
```

`php bin/sr serve` 不需要（每次请求重新读）。

### `env()` 在配置文件外返回 null

`env()` **只在 `config/*.php` 里可靠**——配置缓存之后，`.env` 不再被读取。

业务代码里用 `config()`：

```php
env('APP_NAME');              // ❌ 缓存后是 null
config('app.name');           // ✅
```

### 布尔值的转换规则

`env()` 会把这些字符串转成对应类型：

| `.env` 里写的 | 读出来的 | 类型 |
| --- | --- | --- |
| `true` / `"true"` / `'true'` | `true` | boolean |
| `false` / `"false"` / `'false'` | `false` | boolean |
| `null` | `null` | null |
| `3600` | `3600` | integer |
| 空值（`KEY=`） | `''` | string |
| 其他任意文本 | 原样 | string |

**实测确认：引号会被剥掉，加不加引号结果一样。**

```ini
APP_DEBUG=false       # → false（boolean）
APP_DEBUG="false"     # → false（boolean）—— 也可以
APP_DEBUG='false'     # → false（boolean）
```

所以「必须加引号」或「必须不加引号」都是误解。

::: tip 但仍建议不加引号
一致性——同一个文件里有些加有些没加，读的时候要多想一次。

而且**带空格的值必须加引号**：

```ini
APP_NAME=My App        # → "My App"（能读，但依赖 trim 行为）
APP_NAME="My App"      # ✅ 明确
```
:::

### 配置缓存后 `Config::set()` 还有用吗

有用——`set()` 改的是内存里的值，不影响缓存文件。

但**只对当前请求有效**，下一个请求重新从缓存加载。

### 多环境怎么管理

三条路：

| 做法 | 说明 |
| --- | --- |
| 每台机器一个 `.env` | 最简单，手动维护 |
| 环境变量注入（Docker / K8s） | 不落盘，`.env` 可以只留开发用的 |
| `config/{env}/` 目录 | **框架不支持**——需要自己扩展 |

---

## 下一步

- **[命令行](./commands)** —— 40 个命令
- **[门面](./facades)** —— `Config::get()` 的完整 API
- **[部署](./deployment)** —— 生产环境的完整清单
