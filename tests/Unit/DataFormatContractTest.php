<?php

declare(strict_types=1);

namespace Tests\Unit;

use stdClass;
use SunsetReverie\Http\Envelope;
use SunsetReverie\Http\Response;
use Tests\TestCase;

/**
 * 数据格式契约测试（DF-1～DF-6，见文档 7.7）。
 *
 * 这 6 条约定**一半是框架保证、一半是业务责任**，测试要区分清楚：
 *
 * | 条目 | 谁负责 | 框架保证什么 |
 * | --- | --- | --- |
 * | DF-1 大整数 ID 用字符串 | 业务 | 字符串原样输出，不会被框架转成数字 |
 * | DF-2 金额用最小单位整数 | 业务 | 整数不被转成浮点（不出现 1999.0） |
 * | DF-3 布尔用 true/false | 框架 | 输出 `true` 而非 `1` |
 * | DF-4 空语义明确 | 框架 | 空数组输出 `[]`，空对象输出 `{}` |
 * | DF-5 键必须存在 | 框架 | null 字段保留键（不省略） |
 * | DF-6 枚举用字符串 | 业务 | 字符串原样输出，不被转数字 |
 *
 * 为什么值得测：这些不符合时**不会报错**，只会让前端静默拿到错误的值
 * （ID 精度丢失、金额 19.99 变浮点、状态码 2 猜不出含义）。
 */
final class DataFormatContractTest extends TestCase
{
    /**
     * DF-1：字符串 ID 原样输出，超过 2^53 也不变。
     *
     * @return void
     */
    public function testDf1LargeIntegerIdStaysString(): void
    {
        $body = Response::json(['id' => '9007199254740993'])->body();

        $this->assertSame('{"id":"9007199254740993"}', $body, '字符串 ID 必须带引号输出，否则 JS 解析会丢精度');
    }

    /**
     * DF-1 反面：如果业务传的是 int，框架会输出成数字（无法救）。
     *
     * 这条测试是为了记录边界——提醒业务方 ID 必须自己转字符串。
     *
     * @return void
     */
    public function testDf1IntIdIsEmittedAsNumber(): void
    {
        $body = Response::json(['id' => 9007199254740993])->body();

        $this->assertSame('{"id":9007199254740993}', $body, 'int 会输出为数字（业务需自行转 string）');
    }

    /**
     * DF-2：整数金额不带小数（不出现 1999.0）。
     *
     * @return void
     */
    public function testDf2IntegerAmountHasNoDecimal(): void
    {
        $body = Response::json(['amount' => 1999, 'currency' => 'CNY'])->body();

        $this->assertSame('{"amount":1999,"currency":"CNY"}', $body);
        $this->assertTrue(!str_contains($body, '.0'), '整数金额不应出现 .0');
    }

    /**
     * DF-3：布尔输出 true/false，不是 1/0。
     *
     * @return void
     */
    public function testDf3BooleanStaysBoolean(): void
    {
        $body = Response::json(['active' => true, 'deleted' => false])->body();

        $this->assertSame('{"active":true,"deleted":false}', $body);
    }

    /**
     * DF-4：空数组 `[]` 与空对象 `{}` 区分开。
     *
     * @return void
     */
    public function testDf4EmptyArrayAndObjectDiffer(): void
    {
        $this->assertSame('{"tags":[]}', Response::json(['tags' => []])->body(), '空数组应为 []');
        $this->assertSame('{"extra":{}}', Response::json(['extra' => new stdClass()])->body(), '空对象应为 {}');
    }

    /**
     * DF-5：null 字段必须保留键。
     *
     * @return void
     */
    public function testDf5NullKeepsKey(): void
    {
        $body = Response::json(['nickname' => null, 'bio' => null])->body();

        $this->assertSame('{"nickname":null,"bio":null}', $body, '不得省略值为 null 的键');
    }

    /**
     * DF-6：枚举以字符串输出。
     *
     * @return void
     */
    public function testDf6EnumStaysLiteral(): void
    {
        $body = Response::json(['status' => 'published'])->body();

        $this->assertSame('{"status":"published"}', $body);
    }

    /**
     * 成功包络必须包含全部约定字段（含 data，即使为 null）。
     *
     * @return void
     */
    public function testEnvelopeAlwaysHasDataKey(): void
    {
        $envelope = Envelope::success(null);

        $this->assertTrue(array_key_exists('data', $envelope), 'data 键必须存在');
        $this->assertNull($envelope['data']);

        $body = Response::json($envelope)->body();
        $this->assertStringContains('"data":null', $body, 'JSON 里也应保留 data:null');
    }

    /**
     * 失败包络的 errors 必须是「字段 => 字符串数组」。
     *
     * @return void
     */
    public function testEnvelopeErrorsShape(): void
    {
        $envelope = Envelope::error(30001, '参数校验失败', null, ['title' => ['title 不能为空']]);
        $body = Response::json($envelope)->body();

        $this->assertStringContains('"errors":{"title":["title 不能为空"]}', $body);
    }

    /**
     * 不可序列化的数据应明确失败（而不是输出空对象）。
     *
     * @return void
     */
    public function testNonSerializableDataFailsLoudly(): void
    {
        $this->assertThrows(
            \JsonException::class,
            static fn () => Response::json(['handle' => fopen('php://memory', 'rb')])
        );
    }

    /**
     * 记录边界：业务误传 float 时，框架**不会**拦截。
     *
     * 1999.0 会被输出成 1999（看起来像整数），前端无法察觉业务用了浮点。
     * 这条不是"框架缺陷"，而是说明 DF-2 必须靠业务自己保证——
     * 框架只在文档与类型层面提醒，不做运行时校验（校验会带来性能与兼容代价）。
     *
     * @return void
     */
    public function testDf2FloatAmountIsNotBlocked(): void
    {
        $body = Response::json(['amount' => 1999.0])->body();

        $this->assertSame('{"amount":1999}', $body, 'float 1999.0 会显示为整数，业务需自行保证用 int');
        $this->assertSame('{"amount":19.99}', Response::json(['amount' => 19.99])->body(), '真小数会原样输出');
    }
}
