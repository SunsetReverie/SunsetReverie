# 上传

```php
use SunsetReverie\Upload\UploadManager;
```

::: warning 没有 `Upload` 门面
和 `Mail` / `Image` / `Queue` 一样需要配置，用构造函数注入：

```php
final class FileService
{
    public function __construct(private readonly UploadManager $upload)
    {
    }
}
```

下面的示例用 `$this->upload` 表示注入进来的实例。
:::

上传是**最容易被利用**的入口：一个能执行 PHP 的 `.jpg` 就是完整的后门。

框架的处理原则写在 `UploadManager` 的类注释里：

> 1. `isValid()` 检查上传错误
> 2. 大小校验
> 3. **危险扩展名拦截**（即便 MIME 合法也拒绝 `.php` 之类）
> 4. **真实 MIME** 白名单（finfo 读文件内容，不信客户端声明）
> 5. 图片再验一次结构（`getimagesize`），防止"改了后缀的脚本"
>
> 文件名一律服务端生成（UUID + MIME 映射的扩展名），
> 客户端原始文件名只用于展示，**绝不参与路径拼接**。

## 五层校验

| 层 | 检查什么 | 拦的是什么 |
| --- | --- | --- |
| 1 | `isValid()` | 超过 `upload_max_filesize`、传输中断 |
| 2 | `size() > max_size` | 超大文件 |
| 3 | 扩展名在黑名单 | `shell.php`、`x.phtml`、`.htaccess` |
| 4 | `finfo` 读出的真实 MIME | `evil.jpg`（实为 PHP 脚本） |
| 5 | `getimagesize()`（仅图片） | 结构损坏的"图片" |

### 危险扩展名（22 个）

```php
'php', 'php3', 'php4', 'php5', 'phtml', 'phar', 'pht',
'jsp', 'jspx', 'asp', 'aspx', 'cgi', 'pl', 'sh', 'bash',
'exe', 'dll', 'bat', 'cmd', 'com', 'htaccess', 'ini',
```

::: danger 第 3 层和第 4 层是互补的
- 第 4 层（MIME）挡住「内容是脚本但后缀是 jpg」
- 第 3 层（扩展名）挡住「内容真是图片但后缀是 php」

只做一层都有洞。比如一个真实的 GIF 文件改名为 `a.php`——
MIME 检测会通过（内容确实是 image/gif），但扩展名拦截会拒绝。
:::

### 文件名由服务端生成

```php
private const MIME_EXTENSIONS = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    // ...
];
```

落盘名 = UUID + 由**检测出的 MIME** 映射的扩展名。

客户端传的 `../../etc/passwd` 或 `shell.php` **完全不参与路径**，只在
`StoredFile::$originalName` 里留个展示用的记录。

## 上传

```php
public function store(Request $request): array
{
    if (!$request->hasFile('file')) {
        throw new HttpException(422, ErrorCode::MissingParameter->value, '请选择文件');
    }

    $stored = $this->upload->store($request->file('file'), 'avatars');

    return [
        'path' => $stored->path,
        'url' => $stored->url,
        'size' => $stored->size,
        'mime' => $stored->mime,
    ];
}
```

### `StoredFile` 的六个字段

| 字段 | 说明 |
| --- | --- |
| `path` | 相对存储根的路径（`avatars/3f2a....jpg`） |
| `url` | 可直接访问的 URL（`/uploads/avatars/...`） |
| `size` | 字节数 |
| `mime` | **检测出的** MIME（不是客户端声明的） |
| `hash` | 文件内容哈希（可用于去重） |
| `originalName` | 客户端原始文件名，**只用于展示** |

### 去重

```php
// 注意：findByHash 在 ChunkedUploadManager 上，不在 UploadManager
$existing = $this->chunkedUpload->findByHash($hash);
```

`hash` 是内容哈希，相同文件重复上传可以复用已有记录（省磁盘）。
查不到返回 `null`。

## 三种存储

| 驱动 | 位置 | 类 |
| --- | --- | --- |
| `local` | `storage/uploads/` | `LocalStorage` |
| `memory` | 内存（测试用） | `MemoryStorage` |

```php
// config/upload.php
'disk' => env('UPLOAD_DISK', 'local'),
'disks' => [
    'local' => [
        'driver' => 'local',
        'root' => 'storage/uploads',
        'url_prefix' => '/uploads',
    ],
],
```

自定义存储实现 `StorageInterface`，然后：

```php
$this->upload->extend(new S3Storage($config));
```

## 签名 URL

私有文件不该直接暴露。用签名 URL 临时授权：

```php
$url = $this->upload->signedUrl($path, ttl: 900);      // 15 分钟
```

生成的 URL 长这样：

```text
/files?path=avatars%2F3f2a.jpg&expires=1793390000&sign=8f3c...
```

三个参数：`path`、`expires`（Unix 时间戳）、**`sign`**（不是 `signature`）。

内置的 `FileController` 会校验：

```php
$this->upload->verifySignature($path, $expires, $sign);
```

::: warning `/files` 直接访问会 403
```text
GET /files?path=xxx
→ 403 / 20011「链接已失效或已过期」
```

这是**正确行为**——没有签名参数一律拒绝。

需要公开访问的图片走 `url_prefix`（`/uploads/xxx`），那是 Web 服务器直接返回的静态文件，
不经过框架。
:::

### 什么时候用签名 URL

| 场景 | 用什么 |
| --- | --- |
| 公开头像、文章封面 | `/uploads/xxx`（静态直出） |
| 用户上传的合同、发票 | 签名 URL（15 分钟有效） |
| 后台导出文件 | 签名 URL + 短 TTL（60 秒） |

## 分片上传

大文件（> 10MB）用分片，避免单请求超时和内存爆掉。

```php
$chunked = $this->chunkedUpload;

// 1. 每片单独上传
$chunked->chunk($uploadId, $index, $request->file('chunk'));

// 2. 查进度
$status = $chunked->status($uploadId);   // 已收片数 / 总片数

// 3. 全部到齐后合并
$stored = $chunked->merge($uploadId);
```

### 配置

```php
'chunk' => [
    'chunk_size' => (int) env('UPLOAD_CHUNK_SIZE', 5 * 1024 * 1024),   // 5MB
    'min_chunk_size' => 1024 * 1024,        // 1MB
    'max_chunk_size' => 16 * 1024 * 1024,   // 16MB
    'ttl' => 86400,                          // 24 小时未完成就清理
],
```

片大小超出 `[min, max]` 会被拒绝——防止有人用 1 字节的片刷请求。

### 清理未完成的分片

```bash
php bin/sr upload:prune
```

删掉超过 `ttl` 还没合并的分片目录。

## 配置

```php
// config/upload.php
'disk' => env('UPLOAD_DISK', 'local'),
'max_size' => (int) env('UPLOAD_MAX_SIZE', 10485760),   // 10MB
'allowed_mimes' => [...],                                // 真实 MIME 白名单
'sign_secret' => env('UPLOAD_SIGN_SECRET', ''),          // 签名密钥
'chunk' => [...],
```

::: danger `sign_secret` 必须设置
为空时签名可被伪造。

```bash
# 生成一个随机值写进 .env
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```
:::

### `max_size` 与 PHP 配置的关系

框架的 `max_size` 是**应用层**限制。PHP 还有两层：

```ini
upload_max_filesize = 20M    ; 单个文件
post_max_size = 25M          ; 整个请求体（含其他字段）
```

**三者关系**：`post_max_size` ≥ `upload_max_filesize` ≥ `max_size`。

如果 `max_size` 设得比 `upload_max_filesize` 大，文件在到达框架前就被 PHP 丢掉，
你会看到「上传失败但没有任何错误消息」——那是 `$_FILES` 为空。

## 常见错误

### `上传失败` 但没细节

```php
$file->errorMessage();
```

`UploadedFile` 有 `errorMessage()`，把 PHP 的 `UPLOAD_ERR_*` 翻译成中文。

常见的是 `UPLOAD_ERR_INI_SIZE`（超过 `upload_max_filesize`）。

### `不支持的文件类型`

MIME 白名单里没有。检查 `allowed_mimes`：

```php
'allowed_mimes' => [
    'image/jpeg', 'image/png', 'image/webp',
    'application/pdf',
],
```

**注意**：`image/jpg` 不是合法 MIME（正确是 `image/jpeg`）。

### 上传的图片打不开

1. 检查 `url_prefix` 与 Nginx 的 `location` 是否一致
2. 检查 `storage/uploads/` 的权限
3. 检查是否用了签名 URL 但已过期

### 大文件上传 413

```text
413 Payload Too Large
```

三层都要改：

| 层 | 位置 |
| --- | --- |
| Nginx | `client_max_body_size` |
| PHP | `upload_max_filesize` / `post_max_size` |
| 框架 | `config/upload.php` 的 `max_size` |

`body.size` 中间件看的是 `Content-Length`，所以它也会拦。

## 下一步

- **[图片](./image)** —— 上传后的缩略图处理
- **[校验](./validation)** —— 表单字段校验（不含文件）
- **安全策略**（`SECURITY.md`，仓库根目录）—— 部署清单里的上传相关项
