# 参与贡献

感谢你愿意花时间。这份文档只写**这个项目特有的约定**，通用的开源礼仪就不重复了。

---

## 一、开始之前

### 环境要求

| 项 | 要求 | 说明 |
| --- | --- | --- |
| PHP | **8.3+** | 用到 `readonly` 属性、`never` 返回类型、枚举 |
| 扩展 | `pdo` `json` `mbstring` `fileinfo` `openssl` | `sr env:check` 会检查 |
| Composer | **不需要** | 框架零依赖，`vendor/` 不存在也能跑 |

可选（只在特定功能需要）：

| 扩展 | 用于 |
| --- | --- |
| `pdo_mysql` / `pdo_pgsql` | 跨库方言测试 |
| `redis` | Redis 缓存驱动 |
| `apcu` | APCu 缓存驱动 |
| `gd` / `imagick` | 图片处理 |
| `pcov` 或 `xdebug` | 覆盖率 |

### 跑起来

```bash
git clone <repo> && cd SunsetReverie

php bin/sr env:check          # 确认环境
php tests/run.php             # 315 个用例
php bin/sr lint:architecture  # 架构检查 8 项
```

没有 `composer install` 这一步。

---

## 二、提交前必做

一条命令跑完全部检查：

```bash
./scripts/ci.sh
```

它会依次跑：环境 → 语法 → 单元测试 → 架构检查 → 静态分析 → 方言测试。

单独跑某一项：

```bash
php tests/run.php                                  # 单元测试
php bin/sr lint:architecture                       # 架构检查
php phpstan.phar analyse --memory-limit=1G         # 静态分析（需先下载 phar）
SR_TEST_DB=mysql php tests/run.php --filter=DialectContract   # 指定数据库
```

**PR 的前提**：`./scripts/ci.sh` 全绿。

---

## 三、代码风格

### 硬性要求

```php
<?php

declare(strict_types=1);           // 所有文件必须（现有 138/138 都遵守）

namespace SunsetReverie\Foo;
```

- **所有方法参数与返回值都要有类型**（含 `void`）
- **数组要写值类型**：`array<string, mixed>`、`list<int>`，不写裸 `array`
  （PHPStan level 6 会拦）
- 类优先 `final`；属性优先 `private readonly`

### 注释：写「为什么」，不写「是什么」

对比：

```php
// ✗ 废话：代码已经说了
// 遍历数组并累加
foreach ($rows as $row) { $total += $row['n']; }

// ✓ 有价值：解释非显然的决策
// 不用 array_sum(array_column(...))：两万行时中间数组会多占 3MB
foreach ($rows as $row) { $total += $row['n']; }
```

**好的注释通常包含**：为什么选这个方案、不这么做会出什么问题、某个奇怪写法的历史原因。

项目里有一类注释反复出现，请沿用：

```php
/*
 * 说明「如果不这么做会怎样」。
 *
 * 实测数据 / 报错原文可以贴进来——它们是注释里最有价值的部分。
 */
```

### 命名

| 类型 | 约定 | 例 |
| --- | --- | --- |
| 类 | PascalCase | `RateLimitServiceProvider` |
| 方法 | camelCase，动词开头 | `resolveDriver()` 而非 `driverResolve()` |
| 布尔 | `is` / `has` / `can` / `should` 开头 | `isAllowed()` |
| 常量 | UPPER_SNAKE | `ALLOWED_ROOTS` |
| 表名 | 复数蛇形 | `post_versions` |

---

## 四、架构约定（会被自动检查）

`sr lint:architecture` 有 8 项检查，**不通过即阻断合并**。理解它们能省下返工：

| 编号 | 约束 | 违反后果 |
| --- | --- | --- |
| ARCH-1 | `core/` 不得引用 `App\` | 核心反向依赖业务，没法多项目复用 |
| ARCH-2 | 核心不得引用第三方命名空间 | 破坏「零依赖」承诺 |
| ARCH-3 | 组件之间无未声明的互相依赖 | 想换掉某个组件时连锁失效 |
| ARCH-4 | 内核仅组装层（`Application` / `Facades`）依赖组件 | 内核与组件纠缠 |
| ARCH-7 | Facade 的 `@method` 与底层类一致 | IDE 提示与运行时不符 |
| ARCH-8 | 组件目录与 autoload 映射表一致 | 运行期「类找不到」 |

ARCH-5 / ARCH-6（Vendor 共存）由测试覆盖，命令里标 `N/A`。

### 加一个新组件要做的事

踩过三次的坑，按顺序做：

1. 建目录 `components/foo/src/`
2. **在 `core/Foundation/autoload.php` 的 `$sunsetComponents` 里登记一行**
   （目录名与命名空间不总是同形，如 `ratelimit` → `RateLimit`，无法自动推断）
3. 写 `FooServiceProvider`，在 `Application` 的 provider 列表里注册
4. 需要跨组件依赖时，在 `ArchitectureChecker::ALLOWED_COMPONENT_DEPS` 里声明并写理由

漏掉第 2 步 → ARCH-8 会报，但报错在运行期表现为「类找不到」，很难查。

### 扩展点：不要改核心

新增驱动（缓存 / 数据库 / 邮件 / 图片）应该在组件内加一个 `match` 分支，
不属于「改核心」。但**不要**为了加驱动去改 `Application` 或 `Router`。

---

## 五、测试

### 写什么测试

优先级从高到低：

1. **能防止回归的**：修过的 bug 必须有测试（项目里有专门的「对照组测试」，比如
   `testWithoutGroupLeaksDraft` 就是记录「不加 whereGroup 会泄露草稿」）
2. **边界条件的**：空输入、零值、超长、并发
3. **跨库差异的**：涉及 SQL 方言的改动，在 `DialectContractTest` 里加断言

### 不写什么

- 纯 getter/setter
- 框架自身的 `array_map` 之类（那是 PHP 的职责）

### 敏感数据要用非 ASCII 测

这不是风格问题，是**真实教训**：中文 slug 的 urldecode bug 存在了很久没人发现，
因为所有测试数据都是 `post-1`、`test-slug` 这种英文。

```php
// 好：能暴露编码问题
$slug = '新写的文章 · 验证四端';

// 差：永远测不出 urldecode 之类的问题
$slug = 'test-slug';
```

---

## 六、提交信息

格式：

```text
<type>(<scope>): <一句话概括>（v<版本>）

<为什么改 + 改了什么>

<如果有破坏性变更，单独一段写明「旧写法 → 新写法」>

- tests：N 个用例 M 断言
```

| type | 用于 |
| --- | --- |
| `feat` | 新能力 |
| `fix` | 修 bug |
| `refactor` | 不改行为的重构 |
| `docs` | 文档 |
| `test` | 只改测试 |
| `chore` | 构建、版本号 |

**要写「为什么」**，不要只写「改了什么」——六个月后你会需要它。

真实例子（本项目）：

```text
fix(routing): 路由参数 urldecode，修复中文 slug 永远 404（v0.28.1）

真实场景：文章 slug 是中文（新写的文章-·-验证四端），
前端 encodeURIComponent 后请求 /web/v1/posts/%E6%96%B0...，
框架把编码形式原样传进控制器，拿去查库必然匹配不到，接口一直 404。

修复：Route::match 提取参数后 rawurldecode。
用 rawurldecode 而非 urldecode —— 路径里的 + 是字面加号，不是空格。

这个缺陷只有用非 ASCII 数据测才会暴露，之前的测试数据全是英文。
```

---

## 七、PR

### 提交前自查

- [ ] `./scripts/ci.sh` 全绿
- [ ] 有破坏性变更时，`docs/upgrade.md` 加了对应段落
- [ ] `CHANGELOG.md` 加了版本条目
- [ ] 修 bug 时附了复现方式
- [ ] 新增能力时，注释里写了「为什么要有它」

### PR 描述

三行就够：

```text
解决什么问题：
怎么解决的：
怎么验证的：（命令 + 输出要点）
```

---

## 八、不确定的时候

- **该不该加某个能力**：先看 `docs/product-plan/` 里的规划，以及 `docs/v1.0-checklist.md`
  的「刻意不做」清单（ORM、Gate、前端）
- **风格拿不准**：翻一个同类文件，照它的样子写
- **觉得框架设计有问题**：直接开 issue。项目里已经有不少「先写错了后来改对」的记录
  （见 CHANGELOG 的「修复」段），说明这条路是通的

一个已经验证过的事实：**这个框架里最有价值的改动，几乎都来自「用真实项目跑一遍」**。
如果你在用它，遇到不顺手的地方，那就是最有价值的反馈。
