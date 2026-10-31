# 认证

框架只提供 **token 的签发与校验**，不提供用户表、不改密码、不管注册流程。

::: warning 没有密码哈希封装
框架**不封装** `password_hash()`——用 PHP 原生的就行：

```php
// 注册
$hash = password_hash($password, PASSWORD_DEFAULT);

// 登录
if (!password_verify($password, $user['password_hash'])) {
    throw new HttpException(401, ErrorCode::CredentialsInvalid->value, '账号或密码错误');
}
```

理由：这两个函数是 PHP 内置的，再包一层只是多一层间接。
:::

## 三种状态

| 中间件 | 无 token | 有 token 但无效 | 有效 token |
| --- | --- | --- | --- |
| 无 | 放行，`Auth::id()` 为 `null` | 放行，`Auth::id()` 为 `null` | 解析，`Auth::id()` 有值 |
| `auth.optional` | 放行，`null` | **放行**，`null` | 解析，有值 |
| `auth` | **401** | **401** | 解析，有值 |

```php
// 完全不关心登录状态
Route::get('/health', ...);

// 游客也能看，登录了多返回点东西
Route::get('/posts', ...)->middleware(['auth.optional']);

// 必须登录
Route::post('/posts', ...)->middleware(['auth']);
```

## API

```php
use SunsetReverie\Support\Facades\Auth;

Auth::id();        // ?string —— 未登录返回 null
Auth::user();      // ?AuthenticatedUser
Auth::check();     // bool
Auth::guard();     // 当前 Guard 实例
Auth::jwt();       // JwtGuard（签发 token 用）
```

::: warning `id()` 返回 `string` 不是 `int`
```php
Auth::id();              // "42"（string）或 null
(int) Auth::id();        // 42 —— 需要整数时要自己转
Auth::id() ?? '';        // ⚠️ 未登录时是 ''，不是 '0'
```

`(int) null === 0`，所以常见写法：

```php
$userId = (int) (Auth::id() ?? 0);     // 0 表示游客
```

**注意 v0.31 的行为变更**：之前 `Auth::id()` 恒为 `null`（bug），
如果你的代码里有 `Auth::id() ?? 默认用户` 这种兜底，升级后会走另一条分支。
见[升级指南](./upgrade)。
:::

## 签发 token

```php
public function login(LoginRequest $request): array
{
    $user = $this->users->findByEmail($request->validated()['email']);

    if ($user === null || !password_verify($request->validated()['password'], $user['password_hash'])) {
        throw new HttpException(401, ErrorCode::CredentialsInvalid->value, '账号或密码错误');
    }

    return [
        'token' => Auth::jwt()->issue((string) $user['id']),
        'expires_in' => 7200,
    ];
}
```

`issue()` 的两个参数：

```php
issue(string $subject, array $claims = []): string
```

- `$subject` 写进 `sub` 声明（通常是用户 ID）
- `$claims` 是额外的自定义声明

```php
Auth::jwt()->issue('42', [
    'name' => '夕梦',
    'roles' => ['admin'],
]);
```

## 域绑定（多域项目必读）

**同一个 token 不能跨域使用。**

### 怎么实现的

签发时把**当前域名**写进 `aud`：

```php
$audience = $this->domainContext?->name() ?? '';

if ($audience !== '' && $this->enforceAudience && !isset($claims['aud'])) {
    $claims['aud'] = $audience;
}
```

校验时比对：

```text
token.aud = 'web'  →  请求 /admin/...  →  aud 不匹配  →  401 / 20010
```

### 没有 aud 的 token 同样拒绝

```php
/*
 * 多域项目**没有 aud 的 Token 同样拒绝**，
 * 否则等于留下「去掉 aud 就能跨域使用」的后门。
 */
```

所以自己手工构造 token（不带 `aud`）在**多域项目里用不了**。

### 单域项目不校验

`domains` 为空时不校验 `aud`——单域没有「跨域」的概念。

## AuthenticatedUser

```php
final class AuthenticatedUser
{
    public function __construct(
        public readonly string $id,          // sub 声明
        private readonly array $claims = [], // 全部声明
    ) {
    }

    public function claim(string $key, mixed $default = null): mixed;
    public function claims(): array;
}
```

```php
$user = Auth::user();

$user->id;                    // "42"
$user->claim('name');         // "夕梦"
$user->claim('roles', []);    // ['admin']
$user->claims();              // 全部声明
```

::: danger 它不含用户资料，也不查数据库
类注释写得很明确：

> 直接从 Token 载荷构造，**不做数据库查询**；
> 需要完整用户信息时，业务在控制器里用 id 自行查询。

所以 `Auth::user()->email` **不存在**——token 里没这个字段。

```php
// ✅ 需要资料就自己查
$profile = $this->users->findById((int) Auth::id());

// ❌ 不要期待 token 里带全部字段
Auth::user()->email;
```
:::

**为什么这么设计**：token 里的信息越多，越长、越容易过期不一致
（用户改了昵称，旧 token 里还是旧的）。只放 ID，资料用 ID 查。

## 配置

```php
// config/auth.php
'default' => env('AUTH_GUARD', 'jwt'),

'guards' => [
    'jwt' => [
        'driver' => 'jwt',
        'secret' => env('JWT_SECRET', ''),
        'ttl' => (int) env('JWT_TTL', 7200),      // 秒
        'leeway' => 30,                            // 时钟偏移容忍（秒）
        'claim_key' => 'sub',                      // 从哪个声明取用户 ID
    ],
],
```

### `secret` 必须设置

```bash
php bin/sr jwt:secret     # 生成并写入 .env
```

::: danger 换 secret 会让所有已发 token 立即失效
用户会全体掉线。

换之前想清楚：是**所有** token 都有安全问题，还是只换某几个用户？
后者应该做的是「让那几个用户的 token 失效」，不是换全局密钥。
:::

### `leeway` 是给分布式部署的

多台机器时间不完全同步时，`exp` 判断会误杀。`30` 秒的容忍足够。

## 中间件

```php
Route::get('/me', ...)->middleware(['auth']);
Route::get('/posts', ...)->middleware(['auth.optional']);
```

`Authenticate` 中间件做的事：

```text
1. 从 Authorization: Bearer <token> 取 token
2. 无 token → 401 / 20001（未登录）
3. decode 失败 → 401 / 20002（token 无效或过期）
4. 多域时校验 aud → 不匹配 401 / 20010
5. 把 AuthenticatedUser 写进 request attribute（auth.user）
```

`OptionalAuth` 的差别：前两步失败时**不抛异常**，继续往下走。

## 常见错误

### `Auth::id()` 一直是 null

四个可能：

| 原因 | 检查 |
| --- | --- |
| 路由没挂 `auth` 或 `auth.optional` | 中间件没跑，`auth.user` 没写入 |
| `Authorization` 头没传 | `Bearer <token>`，注意空格 |
| 多域项目 token 没 `aud` | 自己手工造的 token |
| `JWT_SECRET` 两边不一致 | 签发和校验用了不同的 key |

### `token 无效` 但刚签发的

```text
JWT_TTL=7200
```

如果服务器时间比签发时**快**了超过 `leeway`，会立刻判过期。查一下两边的 `date`。

### 跨域请求 401 / 20010

`aud` 不匹配。要么用目标域重新登录，要么这个接口不该要求 `auth`。

### 想「放行所有 token」

**不要**。`auth.optional` 已经满足「有就解析、没有也放行」；
如果连**无效** token 都想放行，那是设计问题，不是配置问题。

## 下一步

- **[控制器](./controller)** —— `Auth::id()` 在控制器里的用法
- **[中间件](./middleware)** —— 写「要求邮箱已验证」这类中间件
- **限流（组件章节，待补）** —— 登录接口防爆破（`throttle:5,60`）
