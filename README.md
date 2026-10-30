# SunsetReverie

零依赖的 PHP 微服务框架。**不装 Composer 也能跑**。

多 API 域隔离、可替换组件、无 ORM，为「一个人维护的中型后端」设计。

```bash
git clone <repo> && cd SunsetReverie
php bin/sr env:check     # 检查环境
php bin/sr new demo      # 生成骨架
cd demo && php bin/sr serve
```

---

## 它解决什么问题

**多端并存**：PC 站、移动 App、小程序、后台——四个端的接口形态不同，
但业务规则相同。多数框架里这会变成四套 controller 各写一遍，或者一堆 `if ($platform === ...)`。

这个框架把「域」做成第一等公民：每域独立前缀、独立版本、独立中间件，
共用同一批领域服务。token 跨域不通用（`web` 的 token 访问 `admin` 会被拒）。

**零依赖**：核心 138 个文件，`grep -r '^use [A-Z]' core/` 找不到任何第三方命名空间。
部署不需要 `composer install`，不需要 `vendor/` 目录，不需要记 lock 文件冲突怎么解。

---

## 特性

| | |
| --- | --- |
| **零运行时依赖** | 核心与组件只用 PHP 内置能力 |
| **多 API 域** | 域 → 版本 → 路由三层隔离，token 不串用 |
| **组件可替换** | 缓存 4 种驱动、数据库 3 种、图片 2 种、邮件 2 种，都靠接口而非继承 |
| **契约优先** | 错误码 39 条与文档逐条对齐，有契约测试守着 |
| **可验证** | 315 个用例、架构检查 8 项、PHPStan level 6 零错误 |

---

## 快速开始

### 1. 检查环境

```bash
php bin/sr env:check
```

需要 PHP 8.3+ 和几个常见扩展。不需要 Composer。

### 2. 生成项目

```bash
# 单域（只有一个 API 分组）
php bin/sr new myapp

# 多域（admin / web / app / miniapp）
php bin/sr new myapp --domains=admin,web,app,miniapp
```

### 3. 建表并启动

```bash
cd myapp
php bin/sr migrate
php bin/sr serve
```

`curl http://127.0.0.1:8000/health` 应该有响应。

---

## 写一个接口

**路由**（`routes/web.php`）：

```php
use App\Api\Web\Controller\PostController;
use SunsetReverie\Support\Facades\Route;

return function (Route $router): void {
    Route::group(['prefix' => '/v1'], function (Route $router): void {
        Route::get('/posts', [PostController::class, 'index'], 'post.index')
            ->middleware(['auth.optional']);

        Route::post('/posts/{id}/like', [PostController::class, 'like'], 'post.like')
            ->middleware(['auth']);
    });
};
```

**控制器**只做参数转换，业务在领域服务：

```php
final class PostController
{
    public function __construct(private readonly PostService $posts)
    {
    }

    public function like(Request $request, int $id): array
    {
        return $this->posts->like($id, (int) Auth::id());
    }
}
```

**领域服务**拿数据库连接，写真正的规则：

```php
final class PostService
{
    public function like(int $postId, int $userId): array
    {
        // 幂等：靠 (post_id, user_id) 唯一索引兜底，不靠应用层判断
        try {
            $this->db->transaction(function () use ($postId, $userId): void {
                $this->db->table('post_likes')->insert([...]);
                // 原子自增，不是「先读后写」——后者并发下会丢更新
                $this->db->table('posts')->where('id', $postId)->increment('like_count');
            });
        } catch (QueryException $e) {
            if (!$this->isDuplicateKey($e)) {
                throw $e;
            }
        }

        return $this->likeState($postId, $userId);
    }
}
```

---

## 为什么不提供某些东西

这些是**刻意不做**，不是没做完：

| 不做 | 原因 |
| --- | --- |
| **ORM** | 隐式 SQL 与 N+1 在真实业务里更难排查。Query Builder + 领域服务已够用，且能一眼看出查了几次库 |
| **Gate / Policy** | 完整授权体系是大工程。四档做法（中间件 / 服务内判断 / 独立策略类 / 数据归属过滤）见文档 11.2 |
| **自动 CRUD 生成** | 生成的代码往往要改一半，不如手写清楚 |
| **内置前端 / 模板引擎** | 这是 API 框架 |

---

## 文档

| 文档 | 内容 |
| --- | --- |
| [产品规划](docs/product-plan/夕梦框架产品规划文档.md) | 设计决策与完整能力说明（含「为什么这样设计」） |
| [升级指南](docs/upgrade.md) | 破坏性变更与迁移方法 |
| [v1.0 交付清单](docs/v1.0-checklist.md) | 13 条发布门禁的当前进度 |
| [参与贡献](CONTRIBUTING.md) | 代码风格、架构约定、提交流程 |
| [安全策略](SECURITY.md) | 漏洞报告、已有安全设计、部署清单 |

---

## 当前状态

```text
版本     0.41.0（开发阶段，接口可能变化）
测试     315 用例 / 799 断言
架构     8 项检查全过
静态分析 PHPStan level 6 零错误
门禁     13 条中达成 7 条
```

### 已验证的环境

| 项 | 版本 |
| --- | --- |
| PHP | 8.5.2（CI 覆盖 8.3 / 8.4 / 8.5） |
| MySQL | 8.x |
| PostgreSQL | 18.6 |
| SQLite | 3.x（测试默认） |
| 缓存 | file / array / redis / apcu |

---

## 开发

```bash
./scripts/ci.sh                  # 全部检查
php tests/run.php                # 单元测试
php bin/sr lint:architecture     # 架构检查
```

静态分析需要单独下载 PHPStan（不引入 Composer）：

```bash
curl -L -o phpstan.phar https://github.com/phpstan/phpstan/releases/latest/download/phpstan.phar
php phpstan.phar analyse --memory-limit=1G
```

---

## 许可

[MIT](LICENSE)
