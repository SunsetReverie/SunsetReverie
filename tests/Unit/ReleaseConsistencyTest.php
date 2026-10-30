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
}
