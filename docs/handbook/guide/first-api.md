# 第一个接口

从零写一个「文章列表」接口，走完路由 → 控制器 → 领域服务的完整链路。

读完这一节，你会知道**每一层该放什么、为什么这么分**。

## 目标

```bash
GET /web/v1/posts?page=1&per_page=10
```

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "items": [
      { "id": 1, "title": "第一篇文章", "published_at": "2026-10-30 12:00:00" }
    ],
    "total": 42,
    "page": 1,
    "per_page": 10
  }
}
```

## 第一步：建表

先有数据表。

```bash
php bin/sr make:migration create_posts_table
```

编辑生成的 `database/migrations/XXXX_create_posts_table.php`：

```php
return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 200);
            $table->string('slug', 200)->unique();       // 链式：作用于 slug 列
            $table->text('content');
            $table->integer('status')->default(0);       // 0 草稿 1 已发布
            $table->integer('view_count')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'published_at']);   // 列表查询走这个索引
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('posts');
    }
};
```

```bash
php bin/sr migrate
```

::: warning 关于 `unique()`
`$table->string('slug')->unique()` 是**链式**用法——作用于最近定义的那一列。

也可以按列名：`$table->unique('slug')`。

但两种别混着写。历史上 `unique('slug')` 曾因签名不接受参数而静默作用到最后一列（`updated_at`），
导致插第二条数据就冲突。现在传列名是安全的，写错列名会直接抛异常。
:::

## 第二步：写领域服务

**先写服务，后写控制器**——因为业务规则不依赖 HTTP。

`app/Domain/Post/PostService.php`（**这个目录要自己建**，骨架不预置）：

```php
<?php

declare(strict_types=1);

namespace App\Domain\Post;

use SunsetReverie\Support\Facades\DB;

final class PostService
{
    private const STATUS_PUBLISHED = 1;

    /**
     * 已发布文章的分页列表。
     *
     * @param int $page 页码（从 1 开始）
     * @param int $perPage 每页条数
     * @return array<string, mixed> 分页结果
     */
    public function paginate(int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));

        $query = DB::table('posts')->where('status', self::STATUS_PUBLISHED);

        // 先取总数：count() 会重写 SELECT，不影响下面的 get()
        $total = $query->count();

        $items = DB::table('posts')
            ->select('id', 'title', 'slug', 'published_at')
            ->where('status', self::STATUS_PUBLISHED)
            ->orderBy('published_at', 'desc')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }
}
```

三个要点：

**1. 夹取参数在服务层做，不是控制器**

```php
$perPage = max(1, min(50, $perPage));
```

如果放在控制器，任何新增的调用方（CLI 命令、队列任务）都得记得再写一遍。

**2. `count()` 和 `get()` 用两个查询**

`count()` 会把 `SELECT ...` 改写成 `SELECT COUNT(*)`。但 Builder 是不可变的吗？不是——**`count()` 不修改内部状态**，
所以理论上可以复用同一个 `$query`。这里分成两个是为了让 `select` / `orderBy` 只作用于列表查询。

**3. 只返回需要的列**

```php
->select('id', 'title', 'slug', 'published_at')
```

不用 `SELECT *`——列表接口返回 `content`（可能几千字）纯属浪费带宽。

## 第三步：写控制器

`app/Api/Web/Controller/PostController.php`：

```php
<?php

declare(strict_types=1);

namespace App\Api\Web\Controller;

use App\Domain\Post\PostService;
use SunsetReverie\Http\Request;

final class PostController
{
    public function __construct(private readonly PostService $posts)
    {
    }

    /**
     * 文章列表。
     *
     * @param Request $request 当前请求
     * @return array<string, mixed> 分页结果
     */
    public function index(Request $request): array
    {
        return $this->posts->paginate(
            page: $request->page(),
            perPage: $request->perPage(),
        );
    }
}
```

控制器只有 3 行有效代码。这是刻意的：

| 控制器该做 | 控制器不该做 |
| --- | --- |
| 从请求里取参数 | 写 SQL |
| 调用领域服务 | 判断业务规则 |
| 返回结果 | 拼装复杂数据结构 |

**`$request->page()` 和 `$request->perPage()` 是框架提供的方法**：

- `page()` 保证 ≥ 1
- `perPage()` 读 `config('pagination.max_per_page')`（默认 50）并夹取

以前每个服务各写一个 `min(50, ...)`，改上限要翻遍代码。现在改配置就全局生效。

## 第四步：注册路由

`routes/web.php`：

```php
<?php

declare(strict_types=1);

use App\Api\Web\Controller\PostController;
use SunsetReverie\Support\Facades\Route;

return function (Route $router): void {
    Route::group(['prefix' => '/v1'], function (Route $router): void {
        Route::get('/posts', [PostController::class, 'index'], 'post.index');
    });
};
```

第三个参数是**路由名**。它有两个用处：

- `sr route:list` 里能按名字查
- 生成 URL 时不用硬编码路径

## 第五步：验证

```bash
php bin/sr route:list | grep posts
```

```text
GET    /web/v1/posts    post.index
```

```bash
curl "http://127.0.0.1:8000/web/v1/posts?page=1&per_page=5"
```

## 完整链路回顾

```text
HTTP 请求
  ↓
Router 匹配 /web/v1/posts
  ↓
中间件管道（全局 → 域 → 路由）
  ↓
容器解析 PostController（自动注入 PostService）
  ↓
控制器从 Request 取参数
  ↓
领域服务执行 SQL
  ↓
返回值被包装成 {code, message, data, request_id, timestamp}
  ↓
HTTP 响应
```

::: tip 大多数服务不用写 Provider
`PostController` 的构造函数要 `PostService`，容器自动解析。

`PostService` **没有构造函数参数**（用 `DB::` 门面访问数据库），所以容器也能直接 `new` 出来——**不用注册**。

什么时候才需要写 Provider：

| 情况 | 要注册吗 |
| --- | --- |
| 构造函数参数全是可自动解析的类 | ❌ 不用 |
| 有标量参数（`string $apiKey`） | ✅ 要 |
| 要绑接口到实现（`CacheStoreInterface` → `RedisStore`） | ✅ 要 |
| 要控制生命周期（单例 vs 每次新建） | ✅ 要 |
:::

## 常见错误

### 500 错误：`Target [App\Domain\Post\PostService] is not instantiable`

构造函数里有**无法自动解析的参数**（通常是标量，或需要配置的类）。两种解法：

**推荐**：用门面代替注入，让构造函数变空。

```php
// ❌ 要注入带配置的对象
public function __construct(private readonly Connection $db) {}

// ✅ 用门面，构造函数可以不写
public function __construct() {}
// 或直接删掉构造函数，方法体里用 DB::table()
```

**或者**在 Provider 里显式注册（见[领域服务](./domain-service#注册)）。

检查 `AppServiceProvider::register()`。

### 返回的 JSON 里 `data` 是 null

控制器返回了 `void` 或 `null`。控制器方法的返回值会直接放进 `data`：

```php
public function index(): array      // ✓ data 是数组
public function index(): void       // ✗ data 是 null
```

### `per_page=100000` 请求超时

说明没用 `$request->perPage()` 的夹取，或者服务层自己没做上限检查。

检查配置 `config/pagination.php`：

```php
'max_per_page' => (int) env('PAGINATION_MAX', 50),
```

### 路由 404，但 `route:list` 里有

检查域前缀。多域模式下，`/web/v1/posts` 完整路径是 `域前缀 + 版本 + 路由`：

```php
// config/api.php
'domains' => [
    'web' => ['prefix' => 'web', 'versions' => ['v1']],
],
```

所以 `Route::group(['prefix' => '/v1'])` + `Route::get('/posts')` = `/web/v1/posts`。

## 下一步

这篇讲了最小闭环。接下来可以看：

- **路由**：路径参数、约束、多域组织方式
- **请求与响应**：`Request` 完整 API、流式响应
- **数据库**：Query Builder 全部方法、事务、条件分组
- **校验**：FormRequest，以及为什么它比控制器里手写 `if` 好

::: info 这几篇还没写
文档站在逐步铺开。当前只有「介绍 / 安装 / 第一个接口 / 升级指南」。

如果某篇对你最急，可以提 issue 说明优先顺序。
:::
