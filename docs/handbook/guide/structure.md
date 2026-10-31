# 代码组织

## 一条规则：命名空间就是路径

```text
App\Domain\Post\PostService        → app/Domain/Post/PostService.php
App\Support\Captcha\CaptchaService → app/Support/Captcha/CaptchaService.php
App\Jobs\SendMail                  → app/Jobs/SendMail.php
```

**前缀 `App\` 映射到项目根的 `app/`**，剩下的逐段对应目录。

实测：

```php
(new ReflectionClass(App\Support\Captcha\CaptchaService::class))->getFileName();
// → {项目根}/app/Support/Captcha/CaptchaService.php
```

所以**任何子目录都能用**，不需要注册、不需要配置。

::: warning 目录名要和命名空间**大小写一致**
Linux 上 `app/support/` 和 `App\Support\` 是两回事。

macOS 的文件系统默认不区分大小写，本地能跑、上线 404——这是最常见的「线上才报错」问题之一。
:::

## 八个目录

```text
app/
├── Api/            接口层：控制器、FormRequest
├── Console/        定时任务
├── Domain/         业务领域：Service、Exception
├── Http/           中间件
├── Jobs/           队列任务
├── Provider/       依赖装配
└── Support/        工具模块：验证码、加密、Slug……
```

`sr new` 只预置其中几个（`Api/` 在多用域时生成，`Console/` 总是有）。
**其余目录要自己建**——空目录没有意义，用到再建。

---

# Domain —— 业务领域

```text
app/Domain/
├── Post/
│   ├── PostService.php
│   └── Exception/
│       └── PostException.php
├── Order/
│   ├── OrderService.php
│   └── Exception/
└── User/
```

| 判断标准 | 说明 |
| --- | --- |
| 有**业务规则**吗 | 状态流转、权限、金额计算 → 是 |
| 依赖别的领域吗 | 可以（`OrderService` 依赖 `ProductService`） |
| 换项目能直接复用吗 | **不能**，业务是定制的 |

**一个领域一个目录**，目录名用单数（`Post` 不是 `Posts`）。

详见[领域服务](./domain-service)。

---

# Support —— 工具模块

**这是「通用能力」，不含业务规则。**

```text
app/Support/
├── Captcha/
│   ├── CaptchaService.php
│   └── CaptchaException.php
├── Slug.php                 ← 单文件也行
└── Qrcode/
    └── QrcodeService.php
```

| 判断标准 | 说明 |
| --- | --- |
| 有业务规则吗 | **没有**，是通用能力 |
| 依赖 `Domain/` 吗 | **不依赖** |
| 换个项目能直接用吗 | **能**，复制过去就行 |

## 和 Domain 的区别

| | `Domain/Post` | `Support/Captcha` |
| --- | --- | --- |
| 内容是 | 「文章怎么发布」 | 「验证码怎么生成」 |
| 换个项目 | 要重写 | 复制即用 |
| 依赖 | DB、别的 Service | 缓存、GD 扩展 |
| 出错时 | 业务异常（`PostException`） | 工具异常（`CaptchaException`） |

::: tip 一句话区分
**「换个项目还能用吗」**——能就是 `Support/`，不能就是 `Domain/`。
:::

## 完整例子：验证码

```php
// app/Support/Captcha/CaptchaService.php
<?php

declare(strict_types=1);

namespace App\Support\Captcha;

use SunsetReverie\Support\Facades\Cache;

/**
 * 图形验证码。
 *
 * 状态存在缓存里（5 分钟），不建表。
 * 校验后立即失效——一次性，防暴力试错。
 */
final class CaptchaService
{
    private const TTL = 300;
    private const LENGTH = 4;

    /**
     * 生成验证码。
     *
     * @param string $scene 场景（login / register / comment），不同场景互不干扰
     * @param string $owner 归属标识，通常是 IP 或用户 ID
     * @return string 明文（由控制器渲染成图片）
     */
    public function generate(string $scene, string $owner): string
    {
        $code = $this->randomCode();

        Cache::put($this->key($scene, $owner), strtolower($code), self::TTL);

        return $code;
    }

    /**
     * 校验并立即失效。
     *
     * @param string $scene 场景
     * @param string $owner 归属标识
     * @param string $input 用户输入
     * @return bool
     */
    public function verify(string $scene, string $owner, string $input): bool
    {
        $key = $this->key($scene, $owner);
        $expected = Cache::get($key);

        // 无论对错都删——防止反复猜
        Cache::forget($key);

        return is_string($expected)
            && hash_equals($expected, strtolower(trim($input)));
    }

    /**
     * 缓存键。
     *
     * @param string $scene 场景
     * @param string $owner 归属标识
     * @return string
     */
    private function key(string $scene, string $owner): string
    {
        return 'captcha:' . $scene . ':' . $owner;
    }

    /**
     * 随机码（排除易混淆字符）。
     *
     * @return string
     */
    private function randomCode(): string
    {
        $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';   // 去掉 0/O/1/I/l
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $code;
    }
}
```

### 四个设计点

| 点 | 为什么 |
| --- | --- |
| 用 `Cache` 而不是建表 | 5 分钟后自动过期，不需要清理任务 |
| **校验后立即 `forget`** | 一次性，防止反复猜同一个码 |
| `hash_equals` | 常量时间比较，防时序攻击 |
| 用 `scene` 区分 key | 登录的验证码不能拿去注册用 |

### 接口层怎么用

```php
// app/Api/Web/Controller/CaptchaController.php
public function show(Request $request, CaptchaService $captcha): Response
{
    $code = $captcha->generate('login', $request->ip());

    // 用 GD 画出来（或返回 SVG）
    $im = imagecreatetruecolor(120, 40);
    imagefill($im, 0, 0, imagecolorallocate($im, 245, 245, 250));

    for ($i = 0; $i < strlen($code); $i++) {
        $color = imagecolorallocate($im, random_int(30, 120), random_int(30, 120), random_int(120, 200));
        imagestring($im, 5, 15 + $i * 25, 12, $code[$i], $color);
    }

    ob_start();
    imagepng($im);

    return Response::stream(...);   // 输出图片
}
```

`CaptchaService` 从容器自动注入——它没有构造函数参数，容器直接 `new`。

### 什么时候放单文件

| 情况 | 放哪 |
| --- | --- |
| 一个静态方法 / 纯函数 | `app/Support/Slug.php` |
| 多个类协同（Service + Exception） | `app/Support/Captcha/`（目录） |
| 需要注册绑定 / 自己的配置文件 | 目录 + `Provider` |

```php
// app/Support/Slug.php —— 单文件就够
final class Slug
{
    public static function make(string $text): string
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9\x{4e00}-\x{9fa5}]+/u', '-', $text), '-'));
        return $slug === '' ? 'n-a' : $slug;
    }
}
```

**别为了整齐给每个小工具都建目录**。

---

# Api —— 接口层

```text
app/Api/
├── Admin/
│   ├── Controller/
│   └── Request/          FormRequest
├── Web/
│   ├── Controller/
│   └── Request/
└── App/                  如果有多端
```

单域项目用 `app/Controller/`（`sr new` 不传 `--domains` 时生成）。

| 目录 | 放什么 |
| --- | --- |
| `Controller/` | 控制器：取参数 → 调服务 → 返回 |
| `Request/` | `FormRequest`：校验规则 |

**控制器不写业务**——见[控制器](./controller)。

---

# Http —— 中间件

```text
app/Http/
└── Middleware/
    ├── RequireVerified.php
    └── RequireAdmin.php
```

`php bin/sr make:middleware RequireVerified` 生成。

实现 `MiddlewareInterface`：

```php
public function handle(Request $request, Closure $next): Response
{
    // 前置判断
    return $next($request);
}
```

详见[中间件](./middleware)。

---

# Jobs —— 队列任务

```text
app/Jobs/
├── SendCommentMail.php
└── SyncToSearch.php
```

实现 `Job` 接口的四个方法（`handle` / `failed` / `tries` / `backoff`）。

::: danger `handle()` 没有参数
不是 `handle(Container $container)`。需要容器里的东西用 `Application::getInstance()` 或构造函数注入。

详见[队列](./queue)。
:::

---

# Console —— 定时任务

```text
app/Console/
└── Schedule.php
```

```php
return function (Schedule $schedule): void {
    // call(名字, 频率, 闭包) —— 三个参数
    $schedule->call('prune-sessions', 'everyMinute', static function (): void {
        // 到点执行
    });

    $schedule->call('daily-report', "dailyAt('03:30')", static function (): void {
        // 注意 dailyAt 的引号：整串是「频率表达式」
    });
};
```

**频率是字符串，不是链式方法**——没有 `->daily()` 这种写法。

可用值：

| 频率串 | 含义 |
| --- | --- |
| `everyMinute` | 每分钟 |
| `everyFiveMinutes` / `everyTenMinutes` / `everyFifteenMinutes` / `everyThirtyMinutes` | |
| `hourly` / `hourlyAt(15)` | 每小时 / 每小时的第 15 分 |
| `daily` / `dailyAt('03:30')` | 每天 / 每天某时刻 |
| `weekly` / `monthly` | |
| `*/5 * * * *` | 直接写 5 段 cron 表达式 |

::: warning 任务名必须唯一且非空
```text
InvalidArgumentException: 定时任务必须有名字（用于日志与锁）
InvalidArgumentException: 定时任务名重复：[prune-sessions]
```

名字用于日志和并发锁（防止上一轮没跑完下一轮又起）。
:::

由系统 cron 驱动：

```cron
* * * * * cd /path/to/project && php bin/sr schedule:run >> /dev/null 2>&1
```

详见[命令行](./commands#schedule-run)。

---

# Provider —— 依赖装配

```text
app/Provider/
└── AppServiceProvider.php
```

**大多数项目不需要它**——构造函数参数能自动解析时，容器自己就能造出实例。

什么时候需要：

| 情况 | 要不要 Provider |
| --- | --- |
| 构造函数全是可自动解析的类 | ❌ 不用 |
| 有标量参数（`string $apiKey`） | ✅ 要 |
| 要绑接口到实现 | ✅ 要 |
| 要控制生命周期（单例） | ✅ 要 |

```php
final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(PaymentGateway::class, static fn (Container $c): PaymentGateway
            => new StripeGateway($c->get(Config::class)->get('payment.secret')));
    }

    public function boot(): void
    {
        // 启动后执行：注册路由、监听事件
    }
}
```

::: warning `app/Provider/` **不会自动加载**
必须在 `config/app.php` 里登记：

```php
'providers' => [
    \App\Provider\AppServiceProvider::class,
],
```

目录存在但没登记 → 静默不生效。这是「为什么我的绑定没生效」的常见原因。
:::

没有 `make:provider` 命令，手写即可。

---

# 该放哪：决策路径

```text
有新代码要写
  │
  ├─ 是 HTTP 接口相关？ → Api/{域}/Controller 或 Request
  │
  ├─ 有业务规则（状态流转/权限/金额）？
  │    └─ ▶ Domain/{领域}/
  │
  ├─ 换项目能直接复用吗？
  │    └─ ▶ Support/{工具}/
  │
  ├─ 是耗时操作（发邮件/调三方）？
  │    └─ ▶ Jobs/
  │
  ├─ 是定期执行？
  │    └─ ▶ Console/Schedule.php
  │
  ├─ 在请求前后拦截？
  │    └─ ▶ Http/Middleware/
  │
  └─ 需要装配依赖？
       └─ ▶ Provider/
```

## 几个容易放错的

| 代码 | 别放这 | 该放 |
| --- | --- | --- |
| 验证码生成 | `Domain/Auth/`（它不是业务） | `Support/Captcha/` |
| 金额格式化 | `Support/Money.php`（它有业务规则：四舍五入还是截断） | `Domain/Order/` |
| 发送短信 | `Support/Sms/`（如果只是封装 API） | `Support/Sms/` ✅ |
| 短信验证码校验规则（有效 5 分钟、最多试 3 次） | `Support/`（这是业务策略） | `Domain/Auth/` |

**分界线**：**「怎么发」是工具，「什么时候发、发几次」是业务。**

---

# 单域 vs 多域

| | 单域 | 多域 |
| --- | --- | --- |
| 控制器 | `app/Controller/` | `app/Api/{域}/Controller/` |
| 路由文件 | `routes/api.php` | `routes/{域}.php` |
| `Domain/` `Support/` | 一样 | 一样 |

**领域层和工具层不分域**——`PostService` 被 admin 和 web 共用，没必要写两份。

域的区别只在**接口层**（谁能访问、返回什么字段）。

---

# 常见错误

## 目录名大小写不一致

```text
app/Support/captcha/     ← 目录小写
App\Support\Captcha\     ← 命名空间大写 C
```

macOS 上能跑，Linux 上 404。

**规则**：目录名和命名空间**逐字一致**。

## 往框架的 `components/` 里加东西

```text
sunsetreverie/components/captcha/src/...     ← ❌
```

那是框架目录，而且映射表是硬编码的 10 个，新加的不会生效。即使改了 `autoload.php` 登记，框架更新时也会被覆盖。

## Provider 写了但没登记

```text
app/Provider/AppServiceProvider.php   ← 文件在
config/app.php 'providers' => []      ← 没登记
```

绑定静默不生效。

## 把业务规则写进 Support

```php
// ❌ Support/Coupon.php 里判断「满 100 减 20」
// 这是业务规则，换个项目就不适用了
if ($amount >= 100) {
    return $amount - 20;
}
```

放 `Domain/Coupon/`。

## 给每个小工具建目录

```text
app/Support/Slug/
├── Slug.php
└── (空)
```

单文件够了就单文件。

---

## 下一步

- **[领域服务](./domain-service)** —— `Domain/` 里怎么写
- **[控制器](./controller)** —— `Api/` 里怎么写
- **[门面](./facades)** —— `Support/` 里的工具通常用门面访问基础设施
