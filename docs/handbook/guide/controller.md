# 控制器

控制器是**接口层**，只做三件事：接参数、调领域服务、返回结果。

```php
final class PostController
{
    public function __construct(private readonly PostService $posts)
    {
    }

    public function index(Request $request): array
    {
        return $this->posts->paginate(
            page: $request->page(),
            perPage: $request->perPage(),
        );
    }
}
```

不写 SQL、不判业务规则。这一篇主要讲**参数怎么进来、结果怎么出去**——这两件事最容易出错。

## 参数注入

控制器方法的参数由框架自动解析，顺序是：

```text
1. 是类类型？
   ├─ Request 或其子类   → 注入当前请求
   ├─ FormRequest 子类   → 实例化 + 立即校验（失败 422）
   └─ 其他类             → 从容器解析
2. 是标量（int / string / bool / float）？
   ├─ 与路由参数同名     → 注入（自动类型转换）
   ├─ 有默认值           → 用默认值
   ├─ 允许 null          → 注入 null
   └─ 都没有             → 500「无法解析控制器参数」
```

### 类类型自动注入

```php
public function index(Request $request): array
//                          ↑ 注入当前请求

public function store(StorePostRequest $request): array
//                    ↑ 实例化 + 校验

public function search(PostService $posts, Request $request): array
//                     ↑ 从容器解析
```

::: tip 顺序无所谓
`Request` 必须在第一个是常见误解。任意位置都行：

```php
public function show(PostService $posts, int $id, Request $request): array
```
:::

### 标量参数要和路由参数同名

```php
// 路由：Route::get('/posts/{id}', [PostController::class, 'show'])
// 请求：GET /posts/42

public function show(int $id): array      // ✅ 注入 42
public function show(int $postId): array  // ❌ 500「无法解析控制器参数 [$postId]」
```

**变量名必须一致**。这是最容易踩的坑——因为 IDE 不会提示，PHP 也不报错，只有运行时才炸。

### 自动类型转换

路由参数永远是字符串，框架按参数类型提示转换：

| 类型提示 | 转换 |
| --- | --- |
| `int` | `(int)` |
| `float` | `(float)` |
| `bool` | `filter_var(..., FILTER_VALIDATE_BOOL)` |
| `string` / 无类型 | 原样 |

```php
// GET /posts/42
public function show(int $id)      // $id === 42（int）
public function show(string $id)   // $id === '42'（string）
```

::: warning 没有 `int` 提示时的静默错误
```php
public function show($id)          // $id === '42'（字符串）
$post = $this->posts->find($id);   // 严格模式下可能查不到
```

**总是写类型提示**。`declare(strict_types=1)` 下字符串和整数传给 `int` 参数会抛 `TypeError`——
但那是在你的代码里，不是在路由参数上（框架已经转换过了）。
:::

### 缺失参数的处理

```php
public function index(int $page = 1)     // ✅ 有默认值
public function index(?int $page)        // ✅ 允许 null
public function index(int $page)         // ❌ 500
```

第三种情况会抛：

```text
无法解析控制器参数 [$page]：标量参数需与路由参数同名或提供默认值
```

**这是刻意的**：不猜。框架不知道你想用 0 还是 1 还是 null。

## 返回值

四种情况：

| 返回 | 响应 |
| --- | --- |
| `Response` 实例 | 原样返回 |
| `null` | `204 No Content` |
| `string` | `200` + `text/plain` |
| 其他（数组、对象） | `200` + `{code:0, message:"ok", data:...}` |

```php
public function index(): array
{
    return ['items' => [...]];        // → {"code":0,"data":{"items":[...]}}
}

public function health(): string
{
    return 'ok';                      // → text/plain: ok
}

public function destroy(int $id): null
{
    $this->posts->delete($id);

    return null;                      // → 204
}

public function export(): Response
{
    return Response::stream(...);     // → 自己控制一切
}
```

**最常返回的是数组**——会被自动包成标准结构：

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... },
  "request_id": "01a18f94-...",
  "timestamp": 1793380598
}
```

::: tip 不要自己拼响应结构
```php
return ['code' => 0, 'data' => ...];   // ❌ 会变成 {"code":0,"data":{"code":0,"data":...}}
```

数组会被当成**业务数据**放进 `data`。要控制外层结构就返回 `Response` 实例。
:::

## FormRequest

把校验从控制器里抽出来：

```bash
php bin/sr make:request Admin/StorePostRequest
```

生成 `app/Api/Admin/Request/StorePostRequest.php`：

```php
final class StorePostRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|min:2|max:200',
            'content' => 'required|string',
            'category_id' => 'required|integer',
            'summary' => 'nullable|string|max:500',
        ];
    }
}
```

控制器里直接类型提示：

```php
public function store(StorePostRequest $request): array
{
    $data = $request->validated();    // 只有规则里声明的字段

    return ['id' => $this->posts->create($data, (int) Auth::id())];
}
```

**校验失败自动 422**，控制器代码根本不会执行。

### `validated()` 是白名单

```php
// 请求体
{"title": "标题", "content": "正文", "category_id": 1, "is_admin": true}

// rules() 里没有 is_admin
$data = $request->validated();
// $data 里没有 is_admin
```

这是**安全设计**：客户端不能通过多加字段来改任意列。

::: warning 白名单的副作用
`slug`、`status` 这类"服务端生成"的字段如果不在 `rules()` 里，传了也会被丢弃。

xi-blog 里就踩过：创建文章时传 `status: 1` 想直接发布，实际存的是草稿——
因为规则里没有 `status`，被 `validated()` 过滤掉了。

**想让客户端控制，就写进规则**；不想让，就别写，但要清楚它会被丢掉。
:::

## 依赖注入

构造函数要什么，容器给什么：

```php
public function __construct(
    private readonly PostService $posts,
    private readonly AuthManager $auth,
) {
}
```

### 哪些能自动解析

| 情况 | 能否自动 |
| --- | --- |
| 参数全是可解析的类 | ✅ 直接 `new` |
| 有标量参数（string、int 等） | ❌ 必须在 Provider 里注册 |
| 需要配置（如数据库连接） | ❌ 要注册 |

`PostService` 的构造函数要 `Connection`（需要数据库配置），所以必须注册：

```php
// app/Provider/AppServiceProvider.php
$this->container->singleton(PostService::class, static fn (Container $c): PostService
    => new PostService($c->get(ConnectionManager::class)->connection()));
```

**不注册的后果**：

```text
Target [App\Domain\Post\PostService] is not instantiable
```

## 常见错误

### 500：无法解析控制器参数

```text
无法解析控制器参数 [$postId]：标量参数需与路由参数同名或提供默认值
```

检查两处：

1. 路由里参数名是 `{id}` 还是 `{postId}`？
2. 方法参数名是否和它完全一致（**区分大小写**）？

### 500：Controller is not instantiable

依赖没注册。看 `AppServiceProvider::register()`。

### 422：校验失败但看不出哪个字段

响应体的 `errors` 里有明细：

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

### 返回的 `data` 是 null

控制器返回了 `void` 或有 return 语句但没返回值：

```php
public function index(): void      // ❌ data 是 null
public function index(): array     // ✅
```

## 下一步

- **[请求与响应](./request-response)** —— `Request` / `Response` 的完整 API
- **[领域服务](./domain-service)** —— 业务规则该写在哪、事务怎么管
- **[错误处理](./error-handling)** —— 抛什么异常、错误码怎么定
