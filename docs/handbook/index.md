---
layout: home

hero:
  name: 夕梦 SunsetReverie
  text: 轻量 PHP 微服务框架
  tagline: 多 API 域隔离 · 组件可替换 · 无 ORM<br>为「一个人维护的中型后端」而设计
  image:
    src: /logo.svg
    alt: 夕梦
  actions:
    - theme: brand
      text: 快速开始
      link: /guide/install
    - theme: alt
      text: 它解决什么问题
      link: /guide/introduction
    - theme: alt
      text: 升级指南
      link: /guide/upgrade
---

<div class="sr-features">

<div class="sr-feature">
<span class="sr-icon" style="--icon: url(/icons/feather.svg)"></span>
<h3>零运行时依赖</h3>
<p>核心 55 个文件 + 10 个组件，只用 PHP 内置能力。部署不需要 Composer，不需要 vendor 目录。</p>
</div>

<div class="sr-feature">
<span class="sr-icon" style="--icon: url(/icons/topology-star-3.svg)"></span>
<h3>多 API 域</h3>
<p>域 → 版本 → 路由三层隔离，token 不跨域。四个端共用一套领域服务，不用复制四份 controller。</p>
</div>

<div class="sr-feature">
<span class="sr-icon" style="--icon: url(/icons/puzzle.svg)"></span>
<h3>组件可替换</h3>
<p>缓存 4 种驱动、数据库 3 种、图片 2 种、邮件 2 种，全部基于接口而非继承。</p>
</div>

<div class="sr-feature">
<span class="sr-icon" style="--icon: url(/icons/search.svg)"></span>
<h3>看得见 SQL</h3>
<p>没有 ORM。Query Builder 写出来就是一条可读的 SQL，N+1 在写的时候就看得见。</p>
</div>

<div class="sr-feature">
<span class="sr-icon" style="--icon: url(/icons/shield-check.svg)"></span>
<h3>契约优先</h3>
<p>39 条错误码，HTTP 状态与业务码一一对应。异常按语义分类，生产环境自动脱敏。</p>
</div>

<div class="sr-feature">
<span class="sr-icon" style="--icon: url(/icons/circle-check.svg)"></span>
<h3>可验证</h3>
<p>338 个用例 · PHPStan level 8 · 架构检查 8 项 · 三版本 × 三数据库实测。</p>
</div>

</div>

## 它长什么样

```php
// routes/web.php
Route::get('/posts', [PostController::class, 'index'], 'post.index')
    ->middleware(['auth.optional']);
```

```php
// 控制器只做参数转换
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

```php
// 领域服务写真正的规则
final class PostService
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function paginate(int $page, int $perPage): array
    {
        $query = $this->db->table('posts')->where('status', 1);

        return [
            'items' => $query->orderBy('published_at', 'desc')
                ->limit($perPage)
                ->offset(($page - 1) * $perPage)
                ->get(),
            'total' => $query->count(),
        ];
    }
}
```

## 什么时候适合用它

| 适合 | 不适合 |
| --- | --- |
| 多端共存的业务后端（PC / App / 小程序 / 后台） | 需要庞大生态和现成轮子的项目 |
| 团队规模小、希望少一层魔法 | 团队已熟悉某个主流框架且有大量积累 |
| 部署环境受限（不能装 Composer） | 需要重量级 ORM 与复杂关联关系的系统 |
| 想随时能读懂每一行执行路径 | 需要内置管理后台、CMS 等开箱即用功能 |

## 当前状态

::: info v1.0.0-beta.1 · 夕照
日落时分的第一束稳定光 —— 核心可用，生产就绪。

功能完整、可用于生产试用，但 API 仍可能微调。若发现设计问题会发 `beta.2` 而不是升大版本。
:::

| 项 | 结果 |
| --- | --- |
| 测试 | 338 用例 / 889 断言 |
| 静态分析 | PHPStan **level 8** 零错误 |
| 架构检查 | 8 项全过 |
| 覆盖率 | `core`（除 CLI）82.7% · `components` 75.6% |
| PHP 版本 | 8.3.36 · 8.4.27 · 8.5.2 三版本全过 |
| 数据库 | MySQL 8 · PostgreSQL 18 · SQLite 3 |

## 下一步

<div class="sr-links">

<a href="/guide/install" class="sr-link">
<span class="sr-icon" style="--icon: url(/icons/rocket.svg)"></span>
<span><strong>安装</strong> —— 三分钟跑起来</span>
</a>

<a href="/guide/first-api" class="sr-link">
<span class="sr-icon" style="--icon: url(/icons/arrow-right.svg)"></span>
<span><strong>第一个接口</strong> —— 从路由到返回 JSON</span>
</a>

<a href="/guide/introduction" class="sr-link">
<span class="sr-icon" style="--icon: url(/icons/book.svg)"></span>
<span><strong>它解决什么问题</strong> —— 设计取舍与边界</span>
</a>

</div>
