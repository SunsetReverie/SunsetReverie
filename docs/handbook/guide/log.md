# 日志

```php
use SunsetReverie\Support\Facades\Log;
```

`Log` 有门面，直接用。

## 八个级别

按严重程度递增（PSR-3 标准）：

| 级别 | 什么时候用 |
| --- | --- |
| `debug` | 开发调试细节，生产通常关闭 |
| `info` | 正常流程的关键节点（请求完成、任务成功） |
| `notice` | 正常但值得注意（配置降级、回退到默认值） |
| `warning` | 可恢复的异常（重试成功、缓存穿透） |
| `error` | 需要人介入的错误（任务失败、外部服务报错） |
| `critical` | 组件不可用（数据库连不上） |
| `alert` | 必须立刻处理（磁盘满、证书过期） |
| `emergency` | 系统不可用 |

```php
Log::debug('缓存未命中', ['key' => $key]);
Log::info('用户登录', ['user_id' => $id]);
Log::warning('第三方接口超时，已重试', ['attempt' => 2]);
Log::error('订单创建失败', ['error' => $e->getMessage()]);
```

## 五个驱动

| driver | 输出 | 适合 |
| --- | --- | --- |
| `single` | 单文件 `storage/logs/app.log` | 小项目 |
| `daily` | 按天切分，自动清理旧文件 | **生产（默认）** |
| `stderr` | 标准错误流 | **容器环境**（交给运行时采集） |
| `stdout` | 标准输出 | 同上 |
| `null` | 丢弃 | 测试 |

::: warning `stack` 通道未实现
`config/log.php` 里有一行注释掉的 `stack`（同时写多个通道）——**这个没做**。

配了会抛：

```text
LogException: 不支持的日志驱动 [stack]，可用：single / daily / stderr / stdout / null
```

需要多输出时，用 `channel()` 分别写：

```php
Log::channel('daily')->error(...);
Log::channel('stderr')->error(...);
```
:::

## 两种格式

```php
'formatter' => 'json',     // 结构化，便于日志系统采集（默认）
'formatter' => 'line',     // 人类可读，开发时用
```

`json` 输出：

```json
{"time":"2026-10-31T14:23:01+08:00","level":"info","channel":"daily","message":"request","status":200,"duration_ms":12.4,"request_id":"01a1905b-...","ip":"127.0.0.1","method":"GET","path":"/web/v1/posts"}
```

`line` 输出：

```text
[2026-10-31 14:23:01] daily.INFO: request {"status":200} {"request_id":"01a1905b-..."}
```

## 配置

```php
// config/log.php
'default' => env('LOG_CHANNEL', 'daily'),
'level' => env('LOG_LEVEL', 'debug'),        // 全局最低级别
'access_log' => env('LOG_ACCESS', true),     // 是否记录访问日志

'channels' => [
    'single' => [
        'driver' => 'single',
        'path' => 'storage/logs/app.log',
        'level' => env('LOG_LEVEL', 'debug'),
        'formatter' => 'json',
    ],

    'daily' => [
        'driver' => 'daily',
        'path' => 'storage/logs',
        'level' => env('LOG_LEVEL', 'debug'),
        'formatter' => 'json',
        'days' => (int) env('LOG_DAYS', 14),    // 保留天数，0 = 不清理
    ],

    'stderr' => ['driver' => 'stderr', ...],
    'null' => ['driver' => 'null'],
],
```

### `level` 是「最低级别」

```php
'level' => 'warning'
```

`debug` / `info` / `notice` 会被**直接丢弃**（连格式化都不做）。这是性能考虑——
生产环境写一堆 info 会拖慢 IO。

**通道级 `level` 覆盖全局**：

```php
'level' => 'warning',                       // 全局：warning 以上
'channels' => [
    'daily' => ['level' => 'debug'],        // 这个通道记全部
],
```

### `days` 的清理时机

不是定时任务，而是**写日志时顺带检查**：

```php
if ($this->daily && $this->days > 0) {
    $this->prune();
}
```

删掉 `days` 天前的 `app-YYYY-MM-DD.log`。

`days = 0` 表示不清理——**磁盘会一直涨**，生产环境别这么配。

## 作用域上下文

`pushContext()` / `popContext()` 让一组日志共享字段，不用每次都传：

```php
Log::pushContext(['request_id' => $id, 'user_id' => $userId]);

Log::info('开始处理');
// → {"message":"开始处理","request_id":"...","user_id":42}

Log::error('处理失败');
// → {"message":"处理失败","request_id":"...","user_id":42}

Log::popContext();   // 清理
```

### 框架自己就这么用

`LogRequest` 中间件为每个请求建立上下文：

```php
$logger->pushContext([
    'request_id' => $request->requestId,
    'ip' => $request->ip(),
    'method' => $request->method,
    'path' => $request->path,
    'route' => (string) $request->attribute('route.name', ''),
    'domain' => (string) $request->attribute('api.domain', ''),
]);

try {
    $response = $next($request);

    $logger->info('request', [
        'status' => $response->status(),
        'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
    ]);

    return $response;
} catch (Throwable $e) {
    $logger->error('request failed', [
        'exception' => $e::class,
        'message' => $e->getMessage(),
        'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
    ]);

    throw $e;
} finally {
    $logger->popContext();
}
```

所以你在控制器里写的任何日志，都会自动带上这 6 个字段——**排查时不用自己拼**。

## 访问日志

```php
'access_log' => env('LOG_ACCESS', true),
```

开着时每个请求一条 `info('request')`：

| 字段 | 说明 |
| --- | --- |
| `request_id` | 与响应体里的 `request_id` 一致 |
| `ip` / `method` / `path` | 请求基本信息 |
| `route` | 路由名（如 `admin.v1.post.index`） |
| `domain` | 域名（如 `admin`） |
| `status` | HTTP 状态码 |
| `duration_ms` | 耗时（毫秒，两位小数） |

**`OPTIONS` 请求不记录**（CORS 预检，量大且没信息量）。

### 关掉访问日志的场景

| 场景 | 原因 |
| --- | --- |
| 前面有 Nginx 已经在记 access.log | 重复 |
| 压测 / 基准测试 | IO 会污染结果 |
| `APP_ENV=test` | 测试不需要 |

```ini
LOG_ACCESS=false
```

## 用 `request_id` 串起一次请求

这是排查问题的核心手段：

```bash
# 1. 用户报错，从响应体里拿到 request_id
curl ... 
# → {"code":500,"request_id":"01a1905b-ef3e-7e58-ac91-0dd70ccedc2a"}

# 2. 在日志里搜
grep "01a1905b-ef3e-7e58-ac91-0dd70ccedc2a" storage/logs/app-$(date +%Y-%m-%d).log
```

会搜到：访问日志 1 条 + 你的业务日志 N 条 + 异常日志 1 条——**全带同一个 request_id**。

## 生产建议

```ini
LOG_CHANNEL=daily
LOG_LEVEL=info         # 或 warning
LOG_ACCESS=true
LOG_DAYS=30
```

| 项 | 建议 | 理由 |
| --- | --- | --- |
| `LOG_CHANNEL` | `daily` | 单文件会涨到几百 MB，`tail` 都卡 |
| `LOG_LEVEL` | `info` 或 `warning` | `debug` 在生产会把磁盘写满 |
| `LOG_DAYS` | 30 | 留存够排查，也不会无限增长 |

容器环境改用 `stderr`，由 Docker / K8s 的日志驱动收集，别写文件——
**容器里的文件在重启后丢失**。

## 常见错误

### 日志文件不生成

| 检查 | 命令 |
| --- | --- |
| 目录存在且可写 | `ls -ld storage/logs` |
| PHP 进程用户有权限 | `ps aux \| grep php-fpm` |
| `LOG_LEVEL` 太高 | 写的是 `debug` 但级别设成了 `warning` |

### 日志里没有 `request_id`

`TraceRequest` 中间件没跑。检查 `config/app.php` 的 `global_middleware` 里有没有它。

### 日志文件涨得很快

三个原因：

1. `LOG_LEVEL=debug`（生产不该开）
2. 访问日志 + 你自己又在控制器里 `Log::info()` 一堆
3. 某个循环里打了日志

```bash
# 看谁在刷
grep -o '"message":"[^"]*"' storage/logs/app-*.log | sort | uniq -c | sort -rn | head
```

### 中文变成 `\u4e2d\u6587`

`json_encode` 没加 `JSON_UNESCAPED_UNICODE`。

检查 `JsonFormatter` 的实现——框架的是加了的。如果你自己写了 Formatter，记得加。

## 下一步

- **[错误处理](./error-handling)** —— 异常怎么产生、怎么脱敏
- **[队列](./queue)** —— 任务失败的日志在哪看
- **部署**（参考章节，待补）—— 日志轮转与容器采集
