# 请求与响应

## Request

### 公开属性

```php
public readonly string $method;      // GET / POST / PUT ...
public readonly string $path;        // /web/v1/posts
public readonly string $requestId;   // 01a18f94-...
public readonly string $ip;          // 客户端 IP（已处理代理头）
```

`$requestId` 会出现在响应体和日志里，排查问题时用来串联。

### 取参数

| 方法 | 取什么 |
| --- | --- |
| `query($key, $default)` | URL 查询串 |
| `post($key, $default)` | 表单 / JSON 体 |
| `all()` | 合并 query + body |
| `input($key, $default)` | 先查 body，再查 query |
| `body()` | 原始请求体字符串 |
| `json()` | 解析后的 JSON（非 JSON 时返回空数组） |
| `isJson()` | 请求体是否为 JSON |

```php
// GET /posts?page=2&tags[]=php
$request->query('page');          // '2'
$request->query('missing', 'x');  // 'x'
$request->query('tags');          // ['php']
```

::: tip `input()` 的查找顺序
先把参数写在 body 里、又允许写在 query 里时用它：

```php
$keyword = $request->input('q', '');   // body 里有就用 body 的，否则用 query 的
```

**注意 `post()` 会读 JSON 体**——不是只有 `application/x-www-form-urlencoded`。
框架在 `isJson()` 时会把解析结果放进 `post`。
:::

### 分页参数

```php
$request->page();             // 页码，保证 ≥ 1
$request->perPage();          // 每页条数，读 config('pagination.max_per_page') 并夹取
$request->perPage(10, 100);   // 默认 10，上限 100
```

**用这两个方法，不要自己解析 `$_GET`**：

```php
$request->query('per_page');           // ❌ 客户端传 100000 就拉 10 万条
$request->perPage();                   // ✅ 自动夹到 50
```

### 请求头

```php
$request->header('content-type');           // 小写键名，值原样
$request->header('x-token', 'default');     // 带默认值
```

**键名不区分大小写**（内部统一转小写）。

### 上传文件

```php
$file = $request->file('avatar');      // UploadedFile|null

if ($request->hasFile('avatar')) {
    $stored = $this->upload->store($file, 'avatars');
}
```

上传会校验扩展名和 MIME（双检），并拒绝危险扩展名——见[上传](./upload)。

### 属性：中间件与控制器之间传数据

```php
// 中间件里写
$request->withAttribute('user', $user);

// 控制器里读
$user = $request->attribute('user');
```

这是**中间件往下游传递解析结果**的标准方式。框架的 `auth` 中间件就是这么传登录用户的。

::: warning 属性不是请求参数
`attribute()` 取的是 PHP 进程内传递的对象，客户端**无法伪造**。

而 `query()` / `post()` 全部来自客户端，永远不能信任。
:::

## Response

### 五个工厂方法

```php
Response::json($data, $status = 200, $headers = []);
Response::text($string, $status = 200);
Response::noContent();                   // 204
Response::file($path, $downloadName = null);
Response::stream($callback, $headers = []);
```

### 链式加头

```php
return Response::json(['ok' => true])
    ->withHeader('X-Custom', 'value')
    ->withHeaders(['X-A' => '1', 'X-B' => '2']);
```

::: warning 没有 withStatus()
状态码只能在工厂方法里指定：

```php
Response::json($data, 201);       // ✅
Response::json($data)->withStatus(201);   // ❌ 方法不存在
```

这是刻意的：状态码在构造时确定，避免"先建后改"造成的混乱。
:::

### 文件下载

```php
return Response::file($absolutePath, 'report.csv');
```

自动设置：

```text
Content-Type:     按扩展名推断
Content-Length:   文件大小
Content-Disposition: attachment; filename="report.csv"
```

**路径必须是绝对路径**，框架不做路径拼接（避免路径穿越）。

### 流式响应

导出大量数据时用它——**内存占用与总行数无关**：

```php
return Response::stream(
    static function (): void {
        $out = fopen('php://output', 'wb');

        fwrite($out, "\xEF\xBB\xBF");            // UTF-8 BOM，Excel 需要
        fputcsv($out, ['ID', '标题'], ',', '"', '');

        // 这里的 cursor() 是**你自己写的**：框架没有提供游标查询，
        // 需要你在仓储里用「主键 > lastId + LIMIT」实现一个 Generator
        foreach ($repository->cursor() as $row) {
            fputcsv($out, $row, ',', '"', '');
        }

        fclose($out);
    },
    [
        'Content-Type' => 'text/csv; charset=utf-8',
        'Content-Disposition' => 'attachment; filename="export.csv"',
    ]
);
```

三个要点：

**1. 回调里不要 return 任何东西**

直接往 `php://output` 写。返回值会被丢弃。

**2. 只发一次，不能重试**

一旦开始输出，响应头已经发出去了。中途出错只能中断连接——客户端拿到半截文件。

所以**数据准备阶段的错误要在开始输出前抛出**：

```php
return Response::stream(static function (): void {
    $rows = $this->fetchAllOrFail();      // ← 先准备好，出错还没发头
    // ... 开始输出
});
```

**3. 用 cursor 不用 offset**

```php
// ✅ 每批都是主键索引的常量代价
->where('id', '>', $lastId)->orderBy('id')->limit(500)

// ❌ 越往后越慢（要扫描并丢弃前面的行）
->offset(95000)->limit(500)
```

## 响应封装

所有非 `Response` 返回值会被包成标准结构：

```json
{
  "code": 0,
  "message": "success",
  "data": { ... },
  "request_id": "01a18f94-...",
  "timestamp": 1793380598
}
```

| 字段 | 含义 |
| --- | --- |
| `code` | **业务码**，0 表示成功。和 HTTP 状态码是两套 |
| `message` | 人类可读的说明 |
| `data` | 业务数据 |
| `request_id` | 与日志关联 |
| `timestamp` | Unix 时间戳 |

### 为什么要两套码

```text
HTTP 404  +  业务码 40001（NotFound）      → 前端跳详情页
HTTP 422  +  业务码 30001（ValidationFailed）→ 前端标红表单字段
```

前端可以用一套逻辑处理所有成功响应（`code === 0` 就是成功），
失败时再用 `code` 判断具体类型，不必关心 HTTP 状态。

### 自定义 message

```php
return $this->success($data, '创建成功');
```

或直接用 `Response`：

```php
return Response::json([
    'code' => 0,
    'message' => '自定义',
    'data' => $data,
]);
```

但更推荐前者——后者要自己补 `request_id` 和 `timestamp`。

## 常见错误

### `data` 里嵌套了一层

```php
return ['code' => 0, 'data' => $data];
// → {"code":0,"data":{"code":0,"data":...}}   ❌
```

数组会被当成**纯业务数据**放进 `data`。要控制外层就返回 `Response`。

### 文件下载变成乱码

检查两点：

1. 是否设了 `Content-Type`（不设会走默认的 `application/json`）
2. CSV 是否有 UTF-8 BOM（Excel 不加 BOM 会按 GBK 解读）

### 流式下载中途断开

几乎都是**输出开始后抛了异常**。检查：

- 数据库游标是否在输出过程中才执行（应该提前）
- 是否有 `fwrite` 失败（磁盘满、客户端断开）

### 拿不到请求头

```php
$request->header('X-Token');    // ✅ 大小写不敏感
```

如果是 nginx 反向代理，`Authorization` 头可能需要显式转发：

```nginx
proxy_set_header Authorization $http_authorization;
```

## 下一步

- **[中间件](./middleware)** —— 写自定义、参数化、执行顺序
- **[错误处理](./error-handling)** —— 异常体系、39 条错误码
