# 图片

```php
use SunsetReverie\Image\ImageManager;
```

::: warning 没有 `Image` 门面
和 `Mail` / `Upload` / `Queue` 一样，图片需要配置，用构造函数注入：

```php
final class AvatarService
{
    public function __construct(private readonly ImageManager $image)
    {
    }
}
```

下面的示例用 `$this->image` 表示注入进来的实例。
:::

## 两个驱动

| 驱动 | 依赖 | 优点 | 缺点 |
| --- | --- | --- | --- |
| `gd` | `ext-gd`（PHP 内置） | 快、几乎无处不在 | 不支持动图、重采样略糙 |
| `imagick` | `ext-imagick` | 质量好、支持动图 | 慢、要装 ImageMagick |
| `auto` | 有 imagick 用它，否则 GD | — | — |

```php
// config/image.php
'driver' => env('IMAGE_DRIVER', 'auto'),
```

::: tip 默认为什么是 `auto` 但实际多半走 GD
实测同一张图缩略图：

| 驱动 | 耗时 | 文件大小 |
| --- | --- | --- |
| `gd` | 14 ms | 6.4 KB |
| `imagick` | 34 ms | 6.6 KB |

**GD 快 2 倍，体积几乎一样**。

所以 `auto` 的正确理解是「优先 GD」。装 Imagick 的价值在动图和更精细的重采样，
不在常规缩略图。
:::

## 打开

```php
use SunsetReverie\Support\Facades\Image;

$image = $this->image->open('/path/to/photo.jpg');         // 用配置的驱动
$image = $this->image->open($path, 'imagick');             // 指定驱动
```

`open()` 接受文件路径（不接受 URL——避免 SSRF）。

## 缩放

```php
$image->resize(800);              // 只给宽，按宽度等比
$image->resize(0, 600);           // 只给高，按高度等比
$image->resize(800, 600);         // 等比装进 800×600 的框
$image->resize(800, 600, 'exact'); // 强制拉伸到 800×600（会变形）
```

### 三种模式的实际行为

源码里的 `targetSize()`：

```php
if ($mode === 'exact' || ($width > 0 && $height > 0 && $mode === 'stretch')) {
    return [$width, $height];            // 直接给目标尺寸
}

if ($width > 0 && $height === 0) {       // 只给宽
    return [$width, round($srcH * $width / $srcW)];
}

if ($height > 0 && $width === 0) {       // 只给高
    return [round($srcW * $height / $srcH), $height];
}

// fit：等比装进框，且不放大
$ratio = min(1.0, min($width / $srcW, $height / $srcH));
return [round($srcW * $ratio), round($srcH * $ratio)];
```

| 模式 | 1600×1200 缩到 800×600 | 100×100 缩到 400×400 |
| --- | --- | --- |
| `resize(800)` | 800×600 | **800×800**（放大） |
| `resize(800, 600)` (fit) | 800×600 | **100×100（不放大）** |
| `resize(800, 600, 'exact')` | 800×600 | 400×400（变形） |

::: warning `fit` 不会放大
`$ratio = min(1.0, ...)` 里的 `1.0` 就是上限。

小图装进大框**保持原尺寸**，不会糊。

这是刻意的：放大只会让图片变模糊，没有信息增益。
如果你确实要放大（比如占位图统一尺寸），用 `exact`。
:::

## 裁切

```php
$image->crop(200, 200);           // 从中心裁 200×200
$image->crop(200, 200, 0, 0);     // 从左上角裁
$image->crop(200, 200, 50, 30);   // 从 (50, 30) 开始裁
```

`x` / `y` 传 `-1`（默认）表示**居中**。

::: warning 裁切超出边界会被静默夹取
源码里做了 `min()`：

```php
$width = min($width > 0 ? $width : $srcW, $srcW);
$height = min($height > 0 ? $height : $srcH, $srcH);
```

所以对 100×100 的图调 `crop(400, 400)`，**不报错**，得到的是 100×100（原尺寸）。

这一点的实际影响：如果你的业务要求「头像必须 200×200」，裁完可能不是 200×200，
需要自己检查：

```php
$info = $image->info();
if ($info->width < 200 || $info->height < 200) {
    throw new ImageException('原图太小，无法裁出 200×200 的头像');
}
```
:::

## 正方式封面图：`cover()`

```php
$image->cover(400, 400);
```

等价于「等比放大到刚好覆盖目标框，再从中心裁掉多余部分」——**不会变形，也不会有白边**。

适合头像、卡片封面这类「必须填满固定尺寸」的场景。

| 方法 | 结果 | 会变形 | 有白边 |
| --- | --- | --- | --- |
| `resize(400, 400)` | 400×300（装进框内） | ❌ | ✅ |
| `resize(400, 400, 'exact')` | 400×400 | ✅ | ❌ |
| `cover(400, 400)` | 400×400 | ❌ | ❌ |

## 水印

```php
$image->watermark('/path/to/logo.png');
$image->watermark($logo, 'top-left', opacity: 60, offset: 20);
```

九个位置：`top-left` `top-center` `top-right` `center-left` `center` `center-right`
`bottom-left` `bottom-center` `bottom-right`。

`opacity` 是 0–100，`offset` 是边距像素。

## 格式与质量

```php
$image->format('webp');           // jpeg / png / webp / gif
$image->quality(90);              // 1–100，只对 jpeg / webp 有效
```

默认 JPEG 质量来自配置：

```php
'jpeg_quality' => (int) env('IMAGE_QUALITY', 82),
```

## 保存与输出

```php
// 保存到文件
$result = $image->save('/path/to/output.jpg');
$result->path;      // 实际写入路径
$result->width;     // 输出宽
$result->height;
$result->size;      // 字节
$result->mime;

// 直接输出到响应（浏览器显示）
return $image->output();
```

### `info()` 能拿到什么

```php
$info = $image->info();

$info->width;        // 像素宽
$info->height;
$info->mime;         // image/jpeg
$info->format;       // jpeg
$info->size;         // 字节
$info->animated;     // 是否动图（GD 只取第一帧）
$info->orientation;  // EXIF 方向（1–8，1 表示正常）
```

`orientation` 可以直接用来判断「这张照片需不需要旋转」。

### 链式调用

```php
$result = $this->image->open($src)
    ->resize(800, 600)
    ->watermark($logo)
    ->format('webp')
    ->quality(85)
    ->save($dst);
```

每步都返回**新实例**（不可变），所以可以放心复用：

```php
$base = $this->image->open($src);

$thumb = $base->resize(200)->save($thumbPath);
$large = $base->resize(1600)->save($largePath);
// $base 没被改动
```

## 三个必须处理的坑

`GdDriver` 的类注释里写明了：

### 1. 透明 PNG 转 JPEG 会变黑

```php
// PNG（透明背景）→ JPEG
// 直接转，透明区变成黑色
```

正解：先填白底再转。框架内部处理了，但**你自己做格式转换时要记得**。

### 2. EXIF 方向：手机照片会横躺

```php
// iPhone 竖拍的照片，Orientation=6
// 不旋转的话，缩略图是横的
```

GD 驱动用 `exif` 扩展读 Orientation 并旋转。**如果 PHP 没装 `exif` 扩展**，
这个纠正会失效——照片会横躺。

```bash
php -m | grep exif
```

### 3. 内存与文件大小无关

```text
500 KB 的 JPEG（6000×4000）
  → 解码后约 6000 × 4000 × 4 字节 ≈ 96 MB
```

**解码后的内存是 `宽 × 高 × 4`**，和文件大小没关系。

配置里可以设上限：

```php
'memory_limit_mb' => (int) env('IMAGE_MEMORY_MB', 0),   // 0 = 不限制
```

设了之后，超限的图会**在解码前**被拒绝（读图片头算尺寸），而不是等到 OOM。

::: danger 建议设置这个值
不设的话，一个 10000×10000 的 PNG（可能只有几百 KB）能把 PHP 进程撑爆。

按你的服务器内存算：`memory_limit_mb` 应该小于 `memory_limit` 的 1/3。

例如 `memory_limit=256M` → 设 `IMAGE_MEMORY_MB=64`。
:::

## 配置

```php
// config/image.php
'driver' => env('IMAGE_DRIVER', 'auto'),
'jpeg_quality' => (int) env('IMAGE_QUALITY', 82),
'memory_limit_mb' => (int) env('IMAGE_MEMORY_MB', 0),
```

## 自定义驱动

```php
$this->image->extend('custom', new MyDriver());
```

实现 `ImageDriverInterface`（`open` / `apply` / `info`）。

## 常见错误

### `不支持的图片格式`

`open()` 只认 jpeg / png / webp / gif。检查文件头是否真的匹配。

### 缩略图是横的

EXIF 方向没纠正。装 `exif` 扩展，或者用 Imagick 驱动。

### `Allowed memory size exhausted`

没设 `memory_limit_mb`，或者设得太大。见上文第 3 个坑。

### 处理后图片变模糊

```php
$image->resize(200)->resize(800);   // ❌ 先从 1600 缩到 200，再放大到 800
```

**从原图出发**做每一个尺寸：

```php
$base = $this->image->open($src);
$base->resize(200)->save($thumb);
$base->resize(800)->save($medium);
```

### 动图变成了静态图

GD 只取第一帧。需要保留动画用 Imagick 驱动。

## 下一步

- **[上传](./upload)** —— 上传后接图片处理
- **[缓存](./cache)** —— 缩略图路径可以缓存，避免重复处理
