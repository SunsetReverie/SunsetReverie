<?php

declare(strict_types=1);

namespace Tests\Integration;

use SunsetReverie\Foundation\Application;
use SunsetReverie\Http\Request;
use Tests\TestCase;

/**
 * 多域 API 端到端集成测试。
 *
 * 与单元测试的区别：这里**完整走一遍 Application 引导流程**——
 * 真实的临时项目目录、真实的 config/*.php 与 routes/{domain}.php 文件、
 * 真实的中间件链。它保护的是"四域组合起来还能跑"这件事。
 *
 * 场景来源于示例项目 xi-blog（四端博客），但只保留框架承诺的部分：
 * 域隔离、跨域 Token 拦截、限流通配、域级缓存/上传隔离。
 */
final class MultiDomainApiTest extends TestCase
{
    /** 测试密钥 */
    private const JWT_SECRET = '0123456789abcdef0123456789abcdef';

    /** @var string 临时项目根 */
    private string $project;

    /** @var string 本用例专属的缓存键前缀（ArrayStore 进程内共享，必须隔离） */
    private string $cachePrefix;

    /**
     * 生成一个最小的多域项目。
     *
     * @return void
     */
    protected function setUp(): void
    {
        Application::reset();
        $this->clearEnv();

        $this->project = sys_get_temp_dir() . '/sr-multidomain-' . uniqid('', true);
        $this->cachePrefix = 'sr:' . uniqid('', true) . ':';

        foreach (['config', 'routes', 'storage/logs', 'storage/cache', 'storage/compiled', 'storage/uploads'] as $directory) {
            mkdir($this->project . '/' . $directory, 0755, true);
        }

        $this->writeEnv();
        $this->writeConfig();
        $this->writeRoutes();
    }

    /**
     * 清理临时项目。
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->clearEnv();
        $this->remove($this->project);
    }

    /**
     * 四个域各自可达，跨域路径 404。
     *
     * @return void
     */
    public function testDomainsAreIsolatedByRoute(): void
    {
        $app = Application::boot($this->project);

        foreach (['admin', 'web', 'app', 'miniapp'] as $domain) {
            $response = $app->handleRequest(Request::create('GET', '/' . $domain . '/v1/ping'));
            $payload = (array) json_decode($response->body(), true);

            $this->assertSame(200, $response->status(), $domain . ' 域应可达');
            $this->assertSame($domain, $payload['data']['domain'] ?? null, $domain . ' 域应解析正确');
        }

        // admin 域不存在的路径，不会落到别的域
        $this->assertSame(404, $app->handleRequest(Request::create('GET', '/admin/v1/nope'))->status());
    }

    /**
     * 域前缀自动套用，路由名自动带域名前缀。
     *
     * @return void
     */
    public function testDomainPrefixAndRouteName(): void
    {
        $app = Application::boot($this->project);

        $uris = array_map(static fn ($route): string => $route->uri, $app->router()->routes());
        $names = array_map(static fn ($route): ?string => $route->name, $app->router()->routes());

        $this->assertTrue(in_array('/admin/v1/ping', $uris, true), '域前缀应自动加到 URI');
        $this->assertTrue(in_array('admin.v1.ping', $names, true), '域名前缀应自动加到路由名');
    }

    /**
     * A 域签发的 Token 在 B 域应被拒绝（20010）。
     *
     * @return void
     */
    public function testTokenCannotCrossDomains(): void
    {
        $app = Application::boot($this->project);

        $login = $app->handleRequest(Request::create('POST', '/admin/v1/login'));
        $token = (string) (((array) json_decode($login->body(), true))['data']['token'] ?? '');

        $this->assertTrue($token !== '', '登录应返回 Token');

        // 同一 Token 在 admin 域可用
        $admin = $app->handleRequest(Request::create('GET', '/admin/v1/me', [], '', [
            'Authorization' => 'Bearer ' . $token,
        ]));
        $this->assertSame(200, $admin->status());
        $this->assertSame('42', ((array) json_decode($admin->body(), true))['data']['user'] ?? null);

        // 换到 web 域被拒
        $web = $app->handleRequest(Request::create('GET', '/web/v1/me', [], '', [
            'Authorization' => 'Bearer ' . $token,
        ]));
        $payload = (array) json_decode($web->body(), true);

        $this->assertSame(401, $web->status());
        $this->assertSame(20010, $payload['code'] ?? null, '应返回跨域不匹配错误码');
    }

    /**
     * 限流通配规则应生效（admin.v1.* → 2 次/分钟）。
     *
     * @return void
     */
    public function testWildcardRateLimitAcrossDomains(): void
    {
        $app = Application::boot($this->project);

        $this->assertSame(200, $app->handleRequest(Request::create('GET', '/admin/v1/ping'))->status());
        $this->assertSame(200, $app->handleRequest(Request::create('GET', '/admin/v1/ping'))->status());

        $third = $app->handleRequest(Request::create('GET', '/admin/v1/ping'));

        $this->assertSame(429, $third->status(), 'admin 域应命中 2 次/分钟的通配规则');
        $this->assertTrue((int) ($third->headers()['Retry-After'] ?? 0) > 0);

        // web 域不受 admin 规则影响（走 default 60 次）
        $this->assertSame(200, $app->handleRequest(Request::create('GET', '/web/v1/ping'))->status());
    }

    /**
     * 缓存与业务错误码按域隔离。
     *
     * @return void
     */
    public function testCacheAndErrorCodeIsolation(): void
    {
        $app = Application::boot($this->project);

        // admin 域写入缓存
        $app->handleRequest(Request::create('GET', '/admin/v1/cache-write'));

        // web 域读同一个键，应该读不到
        $web = $app->handleRequest(Request::create('GET', '/web/v1/cache-read'));
        $payload = (array) json_decode($web->body(), true);
        $this->assertNull($payload['data']['value'] ?? null, '不同域不应共享缓存键');

        // admin 域读回自己的值
        $admin = $app->handleRequest(Request::create('GET', '/admin/v1/cache-read'));
        $this->assertSame('admin-value', ((array) json_decode($admin->body(), true))['data']['value'] ?? null);
    }

    /**
     * 业务错误码段位：admin(51) 的 7 → 51007。
     *
     * @return void
     */
    public function testBusinessCodeSegment(): void
    {
        $app = Application::boot($this->project);

        $admin = $app->handleRequest(Request::create('GET', '/admin/v1/code'));
        $web = $app->handleRequest(Request::create('GET', '/web/v1/code'));

        $this->assertSame(51007, ((array) json_decode($admin->body(), true))['data']['code'] ?? null);
        $this->assertSame(52007, ((array) json_decode($web->body(), true))['data']['code'] ?? null);
    }

    /**
     * 写 .env。
     *
     * @return void
     */
    private function writeEnv(): void
    {
        file_put_contents($this->project . '/.env', implode("\n", [
            'APP_ENV=local',
            'APP_DEBUG=false',
            'APP_KEY=base64:' . base64_encode(str_repeat('k', 32)),
            'JWT_SECRET=' . self::JWT_SECRET,
            'LOG_CHANNEL=null',
            '',
        ]));
    }

    /**
     * 写 config/*.php。
     *
     * @return void
     */
    private function writeConfig(): void
    {
        file_put_contents($this->project . '/config/api.php', <<<'PHP'
<?php

return [
    'default' => 'web',
    'allow_header_detection' => false,
    'routing_mode' => 'single',
    'domains' => [
        'admin' => ['prefix' => 'admin', 'guard' => 'jwt', 'error_segment' => 51],
        'web' => ['prefix' => 'web', 'error_segment' => 52],
        'app' => ['prefix' => 'app'],
        'miniapp' => ['prefix' => 'miniapp'],
    ],
];
PHP);

        file_put_contents($this->project . '/config/auth.php', <<<'PHP'
<?php

return [
    'default' => 'jwt',
    'guards' => [
        'jwt' => ['driver' => 'jwt', 'secret' => env('JWT_SECRET'), 'ttl' => 3600],
    ],
];
PHP);

        file_put_contents($this->project . '/config/cache.php', sprintf(
            "<?php\n\nreturn [\n    'default' => 'array',\n    'prefix' => %s,\n    'stores' => ['array' => ['driver' => 'array']],\n];\n",
            var_export($this->cachePrefix, true)
        ));

        file_put_contents($this->project . '/config/ratelimit.php', <<<'PHP'
<?php

return [
    'enabled' => true,
    'by' => 'route',
    'default' => ['max_attempts' => 60, 'decay_seconds' => 60],
    'routes' => [
        'admin.v1.*' => ['max_attempts' => 2, 'decay_seconds' => 60],
    ],
];
PHP);

        file_put_contents($this->project . '/config/log.php', <<<'PHP'
<?php

return [
    'default' => 'null',
    'access_log' => false,
    'channels' => ['null' => ['driver' => 'null']],
];
PHP);
    }

    /**
     * 写四个域的路由文件。
     *
     * @return void
     */
    private function writeRoutes(): void
    {
        // admin：ping / me（需登录）/ 缓存读写 / 错误码 / 限流
        file_put_contents($this->project . '/routes/admin.php', <<<'PHP'
<?php

declare(strict_types=1);

use SunsetReverie\Auth\Middleware\Authenticate;
use SunsetReverie\Foundation\Application;
use SunsetReverie\Http\Request;
use SunsetReverie\RateLimit\Middleware\ThrottleRequests;
use SunsetReverie\Routing\Router;

return function (Router $router): void {
    $router->group(['prefix' => 'v1', 'as' => 'v1.'], static function (Router $r): void {
        $r->get('/ping', static fn (Request $req): array => ['domain' => $req->attribute('api.domain')], 'ping')
            ->middleware([ThrottleRequests::class]);

        // 登录：真实签发路径（请求内签发，Token 才会带 aud）
        $r->post('/login', static fn (): array => [
            'token' => Application::getInstance()->auth()->jwt()->issue('42'),
        ], 'login');

        $r->get('/me', static fn (Request $req): array => [
            'domain' => $req->attribute('api.domain'),
            'user' => $req->attribute(Authenticate::ATTRIBUTE)->id,
        ], 'me')->middleware([Authenticate::class]);

        $r->get('/cache-write', static function (): array {
            Application::getInstance()->cache()->put('probe', 'admin-value', 60);

            return ['ok' => true];
        }, 'cache.write');

        $r->get('/cache-read', static fn (): array => [
            'value' => Application::getInstance()->cache()->get('probe'),
        ], 'cache.read');

        $r->get('/code', static fn (): array => [
            'code' => Application::getInstance()->domain()->businessCode(7),
        ], 'code');
    });
};
PHP);

        // web：ping / me（需登录，用于验证跨域拦截）/ 缓存读 / 错误码
        file_put_contents($this->project . '/routes/web.php', <<<'PHP'
<?php

declare(strict_types=1);

use SunsetReverie\Auth\Middleware\Authenticate;
use SunsetReverie\Foundation\Application;
use SunsetReverie\Http\Request;
use SunsetReverie\RateLimit\Middleware\ThrottleRequests;
use SunsetReverie\Routing\Router;

return function (Router $router): void {
    $router->group(['prefix' => 'v1', 'as' => 'v1.'], static function (Router $r): void {
        $r->get('/ping', static fn (Request $req): array => ['domain' => $req->attribute('api.domain')], 'ping')
            ->middleware([ThrottleRequests::class]);

        $r->get('/me', static fn (Request $req): array => [
            'domain' => $req->attribute('api.domain'),
            'user' => $req->attribute(Authenticate::ATTRIBUTE)->id,
        ], 'me')->middleware([Authenticate::class]);

        $r->get('/cache-read', static fn (): array => [
            'value' => Application::getInstance()->cache()->get('probe'),
        ], 'cache.read');

        $r->get('/code', static fn (): array => [
            'code' => Application::getInstance()->domain()->businessCode(7),
        ], 'code');
    });
};
PHP);

        // app / miniapp：只有 ping
        foreach (['app', 'miniapp'] as $domain) {
            file_put_contents($this->project . '/routes/' . $domain . '.php', <<<'PHP'
<?php

declare(strict_types=1);

use SunsetReverie\Http\Request;
use SunsetReverie\Routing\Router;

return function (Router $router): void {
    $router->group(['prefix' => 'v1', 'as' => 'v1.'], static function (Router $r): void {
        $r->get('/ping', static fn (Request $req): array => ['domain' => $req->attribute('api.domain')], 'ping');
    });
};
PHP);
        }
    }

    /**
     * 清空测试涉及的环境变量（EnvLoader 不覆盖已存在的值）。
     *
     * @return void
     */
    private function clearEnv(): void
    {
        foreach (['APP_ENV', 'APP_DEBUG', 'APP_KEY', 'JWT_SECRET', 'LOG_CHANNEL'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    /**
     * 递归删除目录。
     *
     * @param string $directory 目录
     * @return void
     */
    private function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (glob($directory . '/*') ?: [] as $file) {
            is_dir($file) ? $this->remove($file) : @unlink($file);
        }

        foreach (glob($directory . '/.*') ?: [] as $file) {
            $name = basename($file);

            if ($name !== '.' && $name !== '..' && is_dir($file)) {
                $this->remove($file);
            }
        }

        @rmdir($directory);
    }
}
