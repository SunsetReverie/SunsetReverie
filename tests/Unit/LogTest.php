<?php

declare(strict_types=1);

namespace Tests\Unit;

use SunsetReverie\Log\Formatter\JsonFormatter;
use SunsetReverie\Log\Formatter\LineFormatter;
use SunsetReverie\Log\Handler\FileHandler;
use SunsetReverie\Log\Handler\HandlerInterface;
use SunsetReverie\Log\Level;
use SunsetReverie\Log\Logger;
use Tests\TestCase;

/**
 * 收集型处理器（测试用）。
 */
final class CollectingHandler implements HandlerInterface
{
    /** @var list<array{level: Level, message: string, context: array<string, mixed>}> 记录 */
    public array $records = [];

    /**
     * @param Level $minimum 最低级别
     */
    public function __construct(private readonly Level $minimum = Level::Debug)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function isHandling(Level $level): bool
    {
        return $level->allows($this->minimum);
    }

    /**
     * {@inheritDoc}
     */
    public function handle(Level $level, string $message, array $context): void
    {
        $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }
}

/**
 * 日志组件测试。
 */
final class LogTest extends TestCase
{
    /** @var string 临时日志目录 */
    private string $directory;

    /**
     * 准备临时目录。
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/sr-log-' . uniqid('', true);
    }

    /**
     * 清理临时目录。
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        if (is_dir($this->directory)) {
            @rmdir($this->directory);
        }
    }

    /**
     * 低于最低级别的日志不应写入。
     *
     * @return void
     */
    public function testLevelFiltering(): void
    {
        $handler = new CollectingHandler(Level::Warning);
        $logger = new Logger([$handler]);

        $logger->debug('hidden');
        $logger->info('hidden');
        $logger->warning('shown');
        $logger->error('shown too');

        $this->assertSame(2, count($handler->records));
        $this->assertSame('shown', $handler->records[0]['message']);
    }

    /**
     * 上下文栈应自动合并，且出栈后不再带入。
     *
     * @return void
     */
    public function testContextStackMergesAndPops(): void
    {
        $handler = new CollectingHandler();
        $logger = new Logger([$handler]);

        $logger->pushContext(['request_id' => 'req-1', 'ip' => '127.0.0.1']);
        $logger->info('inside', ['user_id' => 7]);
        $logger->popContext();
        $logger->info('outside');

        $this->assertSame('req-1', $handler->records[0]['context']['request_id']);
        $this->assertSame(7, $handler->records[0]['context']['user_id']);
        $this->assertNull($handler->records[1]['context']['request_id'] ?? null);
    }

    /**
     * 敏感字段应被脱敏（含嵌套）。
     *
     * @return void
     */
    public function testSensitiveFieldsAreMasked(): void
    {
        $handler = new CollectingHandler();
        $logger = new Logger([$handler]);

        $logger->info('login', [
            'password' => 'secret123',
            'Authorization' => 'Bearer abc',
            'profile' => ['token' => 'xyz', 'name' => 'Tom'],
        ]);

        $context = $handler->records[0]['context'];

        $this->assertSame('***', $context['password']);
        $this->assertSame('***', $context['Authorization']);
        $this->assertSame('***', $context['profile']['token']);
        $this->assertSame('Tom', $context['profile']['name'], '非敏感字段应保留');
    }

    /**
     * JSON 格式化：关键上下文提升到顶层，其余进 context。
     *
     * @return void
     */
    public function testJsonFormatterPromotesKeys(): void
    {
        $line = (new JsonFormatter())->format(Level::Info, 'request', [
            'request_id' => 'req-1',
            'route' => 'home',
            'status' => 200,
        ], 'app');

        $record = (array) json_decode($line, true);

        $this->assertSame('info', $record['level']);
        $this->assertSame('app', $record['channel']);
        $this->assertSame('req-1', $record['request_id']);
        $this->assertSame('home', $record['route']);
        $this->assertSame(200, $record['context']['status']);
        $this->assertStringContains('"time":"', $line);
    }

    /**
     * 行格式化应压平换行，保证一条记录一行。
     *
     * @return void
     */
    public function testLineFormatterKeepsSingleLine(): void
    {
        $line = (new LineFormatter())->format(Level::Error, "多行\n消息", ['a' => 1], 'app');

        $this->assertFalse(str_contains($line, "\n"));
        $this->assertStringContains('app.ERROR', $line);
    }

    /**
     * 文件处理器应写入 JSON Lines，且按天文件名带日期。
     *
     * @return void
     */
    public function testFileHandlerWritesDailyFile(): void
    {
        $handler = new FileHandler($this->directory, new JsonFormatter(), Level::Debug, 'app', true, 7);
        $handler->handle(Level::Info, 'hello', ['request_id' => 'req-1']);

        $file = $handler->resolveFile();
        $this->assertTrue(is_file($file));
        $this->assertStringContains(date('Y-m-d'), $file);

        $content = (string) file_get_contents($file);
        $this->assertStringContains('"message":"hello"', $content);
        $this->assertStringContains('"request_id":"req-1"', $content);
    }

    /**
     * 单文件模式路径固定。
     *
     * @return void
     */
    public function testFileHandlerSingleMode(): void
    {
        $path = $this->directory . '/app.log';
        $handler = new FileHandler($path, new JsonFormatter(), Level::Debug, 'app', false);

        $handler->handle(Level::Warning, 'warn', []);

        $this->assertSame($path, $handler->resolveFile());
        $this->assertStringContains('"level":"warning"', (string) file_get_contents($path));
    }
}
