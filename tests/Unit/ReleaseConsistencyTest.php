<?php

declare(strict_types=1);

namespace Tests\Unit;

use SunsetReverie\Console\Lint\ArchitectureChecker;
use SunsetReverie\Foundation\Application;
use Tests\TestCase;

/**
 * 发布一致性：版本号 / CHANGELOG / 架构检查条数必须互相对得上。
 *
 * 为什么需要这些测试：这三样东西都是「改代码时容易忘」的——
 * v0.39 / v0.40 / v0.41 三次提交都只改了版本号没写 CHANGELOG，
 * 而每次 `str.replace` 找不到锚点都会**静默返回原字符串**，
 * 脚本照常打印「已更新」，实际什么都没改。
 *
 * 自动化能发现的问题，就不该靠记性。
 */
final class ReleaseConsistencyTest extends TestCase
{
    /** @var string 仓库根 */
    private string $root;

    /**
     * 记录根目录。
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    /**
     * 当前版本必须在 CHANGELOG 里有条目。
     *
     * @return void
     */
    public function testVersionHasChangelogEntry(): void
    {
        $version = Application::VERSION;
        $changelog = (string) file_get_contents($this->root . '/CHANGELOG.md');

        $this->assertTrue(
            str_contains($changelog, '## [' . $version . ']'),
            sprintf(
                '版本 %s 在 CHANGELOG.md 里没有条目。发版时必须同步写 CHANGELOG——'
                . '否则使用者无从知道改了什么。',
                $version
            )
        );
    }

    /**
     * CHANGELOG 的版本号必须严格降序。
     *
     * 补条目时插错位置很常见（尤其用脚本按锚点插入），
     * 顺序乱了之后「当前版本」会埋在中间，看不出来。
     *
     * @return void
     */
    public function testChangelogVersionsAreDescending(): void
    {
        $changelog = (string) file_get_contents($this->root . '/CHANGELOG.md');

        /*
         * 只匹配 `x.y.z`，**跳过预发布版本**（如 `1.0.0-beta.1`）。
         *
         * 预发布的排序规则和正式版不同（1.0.0-beta.1 < 1.0.0-beta.2 < 1.0.0），
         * 用 version_compare 比较时还要处理同一个正式版之前的多个 beta ——
         * 这段逻辑本身容易写错，而它保护的是「补条目时插错位置」这种低级错误，
         * 不值得为预发布引入更复杂的比较。
         */
        preg_match_all('/^## \[(\d+\.\d+\.\d+)\]/m', $changelog, $matches);

        $versions = $matches[1];
        $this->assertTrue($versions !== [], 'CHANGELOG 里没有解析到任何版本号');

        $previous = null;

        foreach ($versions as $version) {
            if ($previous !== null) {
                $this->assertTrue(
                    version_compare($previous, $version, '>'),
                    sprintf(
                        'CHANGELOG 版本顺序错误：%s 出现在了 %s 之后（应为降序）',
                        $version,
                        $previous
                    )
                );
            }

            $previous = $version;
        }
    }

    /**
     * 架构检查的条数必须与文档承诺一致（8 项）。
     *
     * 之前文档写 8 项、实现只有 4 项，两者脱节了很久没人发现。
     *
     * @return void
     */
    public function testArchitectureCheckerHasEightChecks(): void
    {
        $results = (new ArchitectureChecker($this->root))->run();

        $this->assertSame(
            8,
            count($results),
            'sr lint:architecture 应输出 8 项（文档 16.3.1 承诺的数量）'
        );

        $ids = array_column($results, 'id');
        sort($ids);

        $this->assertSame(
            ['ARCH-1', 'ARCH-2', 'ARCH-3', 'ARCH-4', 'ARCH-5', 'ARCH-6', 'ARCH-7', 'ARCH-8'],
            $ids,
            '架构检查的编号必须连续且完整'
        );
    }

    /**
     * 帮助文案里描述架构检查时，不能写死过期的条数。
     *
     * @return void
     */
    public function testHelpDoesNotHardcodeStaleCount(): void
    {
        $kernel = (string) file_get_contents($this->root . '/core/Console/ConsoleKernel.php');

        $this->assertFalse(
            str_contains($kernel, '4 条依赖规则'),
            '帮助文案还在说「4 条依赖规则」，实际已是 8 项'
        );
    }
    /**
     * 升级指南标注的「适用版本」必须与 VERSION 一致。
     *
     * 这是个特别容易忘的地方：发版时改了 VERSION 和 CHANGELOG，
     * 但升级指南开头那句「适用版本」还停在上一个版本——
     * v1.0.0-beta.1 发布时就漏了，仍是 0.45.0，
     * 读者会以为这份指南没覆盖当前版本。
     *
     * @return void
     */
    public function testUpgradeGuideStatesCurrentVersion(): void
    {
        $path = $this->root . '/docs/handbook/guide/upgrade.md';

        if (!is_file($path)) {
            // 文档站未随仓库发布时跳过（内部仓库形态）
            return;
        }

        $content = (string) file_get_contents($path);

        preg_match('/适用版本：\*\*([^*]+)\*\*/u', $content, $matched);

        // 用 fail() 而不是 assertTrue(isset())：前者返回 never，
        // 静态分析才会认为 $matched[1] 一定存在（assert 不收窄类型）
        if (!isset($matched[1])) {
            $this->fail('升级指南缺少「适用版本」标注');
        }

        $this->assertSame(
            Application::VERSION,
            $matched[1],
            sprintf(
                '升级指南写的适用版本是 %s，但框架是 %s。发版时忘了同步这一行。',
                $matched[1],
                Application::VERSION
            )
        );
    }

    /**
     * SECURITY.md 的支持版本表必须覆盖当前主版本。
     *
     * 同样是发版时容易漏的：表格里还写着 0.45.x（当前），
     * 实际早已是 1.0.x。
     *
     * @return void
     */
    public function testSecurityDocCoversCurrentMajor(): void
    {
        $path = $this->root . '/SECURITY.md';

        if (!is_file($path)) {
            return;
        }

        $major = explode('.', Application::VERSION)[0];
        $content = (string) file_get_contents($path);

        $this->assertTrue(
            str_contains($content, $major . '.'),
            sprintf(
                'SECURITY.md 的支持版本表里找不到 %s.x，可能还停在旧的大版本',
                $major
            )
        );
    }

    /**
     * 文档里出现的预发布版本号必须等于当前版本。
     *
     * 踩过的坑：升级到 beta.2 之后，导航栏、README 徽章、首页标签、
     * 安装文档的 tag 示例还写着 beta.1——使用者看到会困惑「到底哪个是当前版本」。
     *
     * 导航栏已改为从 Application::VERSION 自动读取，这条测试守住其余硬编码处。
     *
     * @return void
     */
    public function testDocsDoNotReferenceStalePrereleaseVersion(): void
    {
        $current = Application::VERSION;

        // 不是预发布版本时没有可比的（比如将来发 1.0.0 正式版）
        if (!str_contains($current, '-')) {
            return;
        }

        $targets = [$this->root . '/README.md', $this->root . '/SECURITY.md'];
        $handbook = $this->root . '/docs/handbook';

        if (is_dir($handbook)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($handbook, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'md') {
                    $targets[] = $file->getPathname();
                }
            }
        }

        $bad = [];

        foreach ($targets as $path) {
            if (!is_file($path)) {
                continue;
            }

            $content = (string) file_get_contents($path);
            $rel = str_replace($this->root . '/', '', $path);

            /*
             * 形如 1.0.0-beta.1 / 2.1.0-rc.3
             *
             * 横线允许 1～2 个：shields.io 的徽章会把 `-` 转义成 `--`
             * （version-1.0.0--beta.1-blue），只写一个会漏掉 README 徽章。
             */
            preg_match_all('/\b\d+\.\d+\.\d+-{1,2}[a-z]+\.\d+\b/', $content, $m);

            foreach (array_unique($m[0]) as $found) {
                // 徽章里的 `--` 还原成 `-` 再比
                if (str_replace('--', '-', $found) !== $current) {
                    $bad[] = sprintf('%s 里写着 %s', $rel, $found);
                }
            }
        }

        $this->assertSame(
            [],
            $bad,
            sprintf(
                "文档里的版本号和当前版本（%s）不一致：\n  %s\n"
                . "升版本时记得同步这些位置，或者改成引用「当前版本」而不写具体号。",
                $current,
                implode("\n  ", $bad)
            )
        );
    }
}
