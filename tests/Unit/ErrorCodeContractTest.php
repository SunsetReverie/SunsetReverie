<?php

declare(strict_types=1);

namespace Tests\Unit;

use ReflectionClass;
use SunsetReverie\Exception\ErrorCode;
use Tests\TestCase;

/**
 * 错误码契约测试：文档 14.2 全表必须与枚举完全一致。
 *
 * 为什么值得单独测：错误码是**对外契约**，前端会按它写分支逻辑
 * （比如 20002 跳登录页、20005 倒计时）。文档与实现一旦漂移，
 * 要么前端按文档写的分支永远不触发，要么按实现写的在新版本被打断。
 *
 * 这条测试让"改枚举不改文档"或"改文档不改枚举"立刻变红。
 */
final class ErrorCodeContractTest extends TestCase
{
    /**
     * 文档中每一条错误码都必须能在枚举里找到，且四个字段完全一致。
     *
     * @return void
     */
    public function testDocumentMatchesEnum(): void
    {
        $rows = $this->documentRows();

        $this->assertTrue($rows !== [], '未能从文档 14.2 解析出错误码表，检查表格格式是否被改动');

        $problems = [];

        foreach ($rows as $name => $row) {
            if (!defined(ErrorCode::class . '::' . $name)) {
                $problems[] = sprintf('%s 在文档中但枚举里没有', $name);

                continue;
            }

            /** @var ErrorCode $case */
            $case = constant(ErrorCode::class . '::' . $name);

            if ($case->value !== $row['code']) {
                $problems[] = sprintf('%s 码值不一致：文档 %d / 实现 %d', $name, $row['code'], $case->value);
            }

            if ($case->httpStatus() !== $row['http']) {
                $problems[] = sprintf('%s HTTP 不一致：文档 %d / 实现 %d', $name, $row['http'], $case->httpStatus());
            }

            if ($case->message() !== $row['message']) {
                $problems[] = sprintf(
                    '%s 消息不一致：文档「%s」/ 实现「%s」',
                    $name,
                    $row['message'],
                    $case->message()
                );
            }
        }

        $this->assertTrue($problems === [], "错误码契约不一致：\n  " . implode("\n  ", $problems));
    }

    /**
     * 枚举里的每个 case 都必须在文档表格中出现（防止"偷偷加码"）。
     *
     * @return void
     */
    public function testEnumHasNoUndocumentedCase(): void
    {
        $documented = array_keys($this->documentRows());
        $reflection = new ReflectionClass(ErrorCode::class);
        $missing = [];

        foreach ($reflection->getConstants() as $name => $value) {
            if (!in_array($name, $documented, true)) {
                $missing[] = sprintf('%s (%d)', $name, $value);
            }
        }

        $this->assertTrue(
            $missing === [],
            "以下错误码在枚举中但文档 14.2 未登记：\n  " . implode("\n  ", $missing)
        );
    }

    /**
     * 关键错误码的语义抽查（防止"码值对但含义错"）。
     *
     * @return void
     */
    public function testCriticalCodesHaveExpectedSemantics(): void
    {
        $expectations = [
            // 认证
            [ErrorCode::Unauthenticated, 401],
            [ErrorCode::TokenExpired, 401],
            [ErrorCode::TokenDomainMismatch, 401],
            [ErrorCode::CredentialsInvalid, 401],
            // 限流与权限
            [ErrorCode::TooManyRequests, 429],
            [ErrorCode::Forbidden, 403],
            [ErrorCode::AccountDisabled, 403],
            // 请求与资源
            [ErrorCode::ValidationFailed, 422],
            [ErrorCode::PayloadTooLarge, 413],
            [ErrorCode::UnsupportedMediaType, 415],
            [ErrorCode::NotFound, 404],
            [ErrorCode::AlreadyExists, 409],
            [ErrorCode::VersionConflict, 409],
            // 服务端
            [ErrorCode::DatabaseError, 500],
            [ErrorCode::CacheError, 500],
            [ErrorCode::UpstreamError, 502],
        ];

        foreach ($expectations as [$case, $http]) {
            $this->assertSame($http, $case->httpStatus(), $case->name . ' 的 HTTP 状态不对');
            $this->assertTrue($case->message() !== '', $case->name . ' 缺少默认消息');
            $this->assertStringContains('SR.', $case->symbol());
        }
    }

    /**
     * 解析文档 14.2 表格。
     *
     * @return array<string, array{code: int, http: int, message: string}>
     */
    private function documentRows(): array
    {
        $path = dirname(__DIR__, 2) . '/docs/product-plan/夕梦框架产品规划文档.md';

        if (!is_file($path)) {
            return [];
        }

        $content = (string) file_get_contents($path);
        $start = strpos($content, '### 14.2');
        $end = strpos($content, '### 14.3', $start === false ? 0 : $start);

        if ($start === false || $end === false) {
            return [];
        }

        $section = substr($content, $start, $end - $start);
        $rows = [];

        if (preg_match_all('/^\| (\d+) \| `([A-Za-z]+)` \| (\d{3}) \| ([^|]+) \|$/m', $section, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        foreach ($matches as $match) {
            $rows[$match[2]] = [
                'code' => (int) $match[1],
                'http' => (int) $match[3],
                'message' => trim($match[4]),
            ];
        }

        return $rows;
    }
}
