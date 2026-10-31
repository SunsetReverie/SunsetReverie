# 校验

## 两种用法

### 1. FormRequest（推荐）

```bash
php bin/sr make:request Admin/StorePostRequest
```

生成 `app/Api/Admin/Request/StorePostRequest.php`：

```php
<?php

declare(strict_types=1);

namespace App\Api\Admin\Request;

use SunsetReverie\Validation\FormRequest;

final class StorePostRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|min:2|max:200',
            'content' => 'required|string',
            'category_id' => 'required|integer',
            'summary' => 'nullable|string|max:500',
            'tags' => 'array',
        ];
    }
}
```

控制器里类型提示，**校验失败自动 422**，控制器代码不执行：

```php
public function store(StorePostRequest $request): array
{
    $data = $request->validated();

    return ['id' => $this->posts->create($data, (int) Auth::id())];
}
```

### 2. 手动调 Validator

适合校验「不属于请求」的数据（比如导入的 CSV 行）：

```php
$validator = $this->validator->validate($data, [
    'email' => 'required|email',
    'age' => 'integer|between:1,120',
]);

if ($validator->errors() !== []) {
    throw new ValidationException($validator->errors());
}
```

## 15 个规则

| 规则 | 说明 | 参数 |
| --- | --- | --- |
| `required` | 必填（`null` / `''` / 空数组都算缺失） | — |
| `string` | 必须是字符串 | — |
| `integer` | 必须是整数（`"42"` 通过，`"4.2"` 不通过） | — |
| `numeric` | 数字或数字字符串 | — |
| `boolean` | `true` / `false` / `1` / `0` / `"1"` / `"0"` | — |
| `array` | 必须是数组 | — |
| `email` | 用 `filter_var(FILTER_VALIDATE_EMAIL)` | — |
| `date` | 能被 `strtotime()` 解析 | — |
| `alpha_dash` | 只含字母、数字、`-`、`_` | — |
| `min` | 下限，见下文 | `min:2` |
| `max` | 上限，见下文 | `max:200` |
| `between` | 区间（含边界） | `between:1,120` |
| `in` | 枚举 | `in:hot,new,top` |
| `not_in` | 排除 | `not_in:admin,root` |
| `confirmed` | 与 `{field}_confirmation` 相等 | — |

### `min` / `max` 的语义随类型变

内部用 `sizeOf()`：

```php
private function sizeOf(mixed $value): float
{
    if (is_array($value))  return (float) count($value);
    if (is_string($value)) return (float) mb_strlen($value);   // 多字节安全
    return is_numeric($value) ? (float) $value : 0.0;
}
```

| 值 | `max:3` 的含义 |
| --- | --- |
| `"abc"` | 长度 ≤ 3 |
| `"中文啊"` | 长度 ≤ 3（按字符算，不是字节） |
| `[1, 2, 3]` | 元素数 ≤ 3 |
| `42` | 数值 ≤ 3 ❌ |
| `42.5` | 数值 ≤ 3 ❌ |

**所以 `max` 既能限长也能限值**，看字段类型。

::: warning 字符串用 `mb_strlen`
`"中文"` 是 2 个字符、6 个字节。用 `strlen` 会让 `max:2` 误判为超限。

框架用的是 `mb_strlen`，所以 `max` 按**字符**算。
:::

### `confirmed` 的字段名规则

```php
'password' => 'required|string|min:8|confirmed',
```

要求请求里同时有 `password_confirmation`，且值相等。

**字段名是固定的**：`{字段名}_confirmation`，不能配。

## FormRequest

### 三个可覆写的方法

```php
final class StorePostRequest extends FormRequest
{
    /** 规则 */
    public function rules(): array { ... }

    /** 自定义消息（可选） */
    public function messages(): array
    {
        return [
            'title.required' => '请填写标题',
            'title.max' => '标题不能超过 :max 个字符',
        ];
    }

}
```

::: warning 没有 attributes() 方法
框架的 `FormRequest` 只有 `rules()` / `messages()` / `authorize()` 三个可覆写方法。

字段别名靠默认消息里的 `{field}` 占位符替换（`{field}` 会变成字段名本身），
想让消息更友好，直接在 `messages()` 里写具体文案。
:::

### `validated()` 是白名单

```php
// 请求体
{"title": "标题", "content": "正文", "is_admin": true}

// rules() 里没有 is_admin
$data = $request->validated();
// $data = ["title" => "标题", "content" => "正文"]
```

::: danger 这是安全设计，不是缺陷
客户端不能通过「多加一个字段」来改任意列。

**副作用**：服务端生成的字段（`slug`、`status`、`created_at`）如果不在
`rules()` 里，传了也会被丢弃。

xi-blog 踩过：创建文章时传 `status: 1` 想直接发布，实际存的是草稿——
因为规则里没有 `status`。

**想让客户端控制，就写进规则；不想让，就别写，但要清楚它会被丢掉。**
:::

### 校验失败的响应

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

`errors` 是 `字段 => 消息数组`。一个字段可能有多条错误（规则从左到右，全部检查）。

## 自定义规则

```php
$this->validator->extend('phone', function (mixed $value): bool {
    return is_string($value) && preg_match('/^1[3-9]\d{9}$/', $value) === 1;
});

// 用
'tel' => 'required|phone',
```

`extend()` 的回调只判断真假，**默认消息自己提供**：

```php
$this->validator->withMessages(['tel.phone' => '手机号格式不正确']);
```

## 常见错误

### `validated()` 里少字段

见上文白名单。检查 `rules()` 里有没有这个字段。

### `integer` 拒绝了 `"42"`

不会——`"42"` 能通过 `integer`（字符串形式的整数）。

但 `"4.2"` 不通过，`"042"` 通过（会被转成 42）。

### `required` 对 `0` 的行为

```php
'status' => 'required|integer',   // status = 0
```

`0` **能通过** `required`（只有 `null`、`''`、`[]` 算缺失）。

这是刻意的——`0` 是合法值。但如果你的业务里 `0` 应该被当成「未选择」，
用 `in:1,2,3` 而不是 `required`。

### `max` 对中文误判

不会。用的是 `mb_strlen`。

但如果**你自己**在别处用 `strlen` 校验长度，会不一致。

### 数组元素不逐个校验

```php
'tags' => 'required|array|max:5',    // 数组本身：必填、是数组、最多 5 个
```

**没有 `tags.*` 通配**——框架只校验字段本身，不会遍历数组元素。

需要逐个校验时，在领域服务里做：

```php
foreach ($data['tags'] as $tag) {
    if (!is_int($tag)) {
        throw new ValidationException(['tags' => ['标签必须是整数']]);
    }
}
```

这是刻意的取舍：把「数组元素校验」做完整需要一条小型规则引擎，
而这类需求往往伴随更复杂的业务约束（唯一性、存在性），
不如在服务里写清楚。

### 空数组通过 `required`

```php
'items' => 'required|array',   // items = []
```

`[]` **不通过** `required`——空数组算缺失。

如果想允许空数组，用 `array` 而不是 `required|array`，或加 `min:1` 明确要求。

## 下一步

- **[控制器](./controller)** —— FormRequest 在控制器里的用法
- **[错误处理](./error-handling)** —— `30001` 的完整约定
- **[上传](./upload)** —— 文件的类型与大小校验（不归 Validation 管）
