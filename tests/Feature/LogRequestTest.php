<?php

declare(strict_types=1);

namespace Tests\Feature;

use SunsetReverie\Foundation\Application;
use SunsetReverie\Http\Request;
use Tests\TestCase;

/**
 * 访问日志中间件端到端测试。
 */
final class LogRequestTest extends TestCase
{
    /** @var string 日志文件路径 */
    private string $logFile;

    /**
     * 准备日志文件路径。
     *
     * @return void
     */
    protected function setUp(): void
    {
        Application::reset();
        $this->logFile = sys_get_temp_dir() . '/sr-access-' . uniqid('', true) . '.log';
    }

    /**
     * 清理日志文件。
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    /**
     * 请求结束后应写入一条访问日志（含 request_id / status / path）。
     *
     * @return void
     */
    public function testWritesAccessLog(): void
    {
        $app = $this->bootWithFileLog();
        $request = Request::create('GET', '/health');

        $app->handleRequest($request);

        $this->assertTrue(is_file($this->logFile), '应生成日志文件');

        $line = trim((string) file_get_contents($this->logFile));
        $record = (array) json_decode($line, true);

        $this->assertSame('info', $record['level']);
        $this->assertSame('request', $record['message']);
        $this->assertSame($request->requestId, $record['request_id']);
        $this->assertSame(200, $record['context']['status']);
        $this->assertSame('/health', $record['context']['path']);
        $this->assertTrue(isset($record['duration_ms']));
    }

    /**
     * 404 也应记录（日志在管道内层，能看到错误响应）。
     *
     * @return void
     */
    public function testLogsErrorStatus(): void
    {
        $app = $this->bootWithFileLog();

        $app->handleRequest(Request::create('GET', '/nope'));

        $record = (array) json_decode(trim((string) file_get_contents($this->logFile)), true);

        $this->assertSame(404, $record['context']['status']);
    }

    /**
     * access_log=false 时不应写日志。
     *
     * @return void
     */
    public function testAccessLogCanBeDisabled(): void
    {
        $app = $this->bootWithFileLog();
        $app->config()->set('log.access_log', false);

        $app->handleRequest(Request::create('GET', '/health'));

        $this->assertFalse(is_file($this->logFile));
    }

    /**
     * 预检请求不记录（避免被跨域探测刷屏）。
     *
     * @return void
     */
    public function testPreflightIsNotLogged(): void
    {
        $app = $this->bootWithFileLog();
        $app->config()->set('cors', ['allowed_origins' => ['https://a.com']]);

        $app->handleRequest(Request::create('OPTIONS', '/health', [], '', [
            'Origin' => 'https://a.com',
            'Access-Control-Request-Method' => 'GET',
        ]));

        $this->assertFalse(is_file($this->logFile));
    }

    /**
     * 业务代码通过 logger() 写入的日志应自动带上请求上下文。
     *
     * @return void
     */
    public function testBusinessLogCarriesRequestContext(): void
    {
        $app = $this->bootWithFileLog();

        $app->router()->get('/business-log', static function (): array {
            logger()->info('用户下单', ['order_id' => 1001, 'password' => 'should-be-masked']);

            return ['ok' => true];
        }, 'business.log');

        $app->handleRequest(Request::create('GET', '/business-log'));

        $lines = array_values(array_filter(explode(PHP_EOL, (string) file_get_contents($this->logFile))));
        $business = (array) json_decode($lines[0], true);

        $this->assertSame('用户下单', $business['message']);
        $this->assertSame('business.log', $business['route'], '路由名应自动注入');
        $this->assertSame(1001, $business['context']['order_id']);
        $this->assertSame('***', $business['context']['password'], '密码应被脱敏');
    }

    /**
     * 构造写文件日志的应用。
     *
     * @return Application 应用实例
     */
    private function bootWithFileLog(): Application
    {
        $app = Application::boot(dirname(__DIR__, 2));

        $app->config()->set('log', [
            'default' => 'single',
            'level' => 'debug',
            'access_log' => true,
            'channels' => [
                'single' => [
                    'driver' => 'single',
                    'path' => $this->logFile,
                    'formatter' => 'json',
                    'level' => 'debug',
                ],
            ],
        ]);

        return $app;
    }
}
