<?php

declare(strict_types=1);

namespace SunsetReverie\Console;

use SunsetReverie\Foundation\Application;

/**
 * 代码生成器命令。
 *
 * 从 ConsoleKernel 拆出：这些命令只做"写模板文件"，不碰运行时状态，
 * 与 serve / migrate / lint 等运维命令没有交集，放一起只会让内核膨胀。
 *
 * 统一约定：
 * - 已存在的文件一律拒绝覆盖（不提供 --force，避免误删业务代码）；
 * - 生成后打印"下一步该做什么"（注册路由、加配置），减少回头查文档。
 */
final class MakeCommands
{
    /**
     * @param Application $app 应用实例
     */
    public function __construct(private readonly Application $app)
    {
    }

    /**
     * 执行生成命令。
     *
     * @param string $command 命令名（controller / model / ...）
     * @param list<string> $args 参数
     * @return int 退出码
     */
    public function run(string $command, array $args): int
    {
        return match ($command) {
            'controller' => $this->controller($args),
            'model' => $this->model($args),
            'middleware' => $this->middleware($args),
            'request' => $this->request($args),
            'seeder' => $this->seeder($args),
            'domain' => $this->domain($args),
            default => $this->unknown($command),
        };
    }

    /**
     * make:controller {Domain/Name}
     *
     * @param list<string> $args 参数
     * @return int 退出码
     */
    private function controller(array $args): int
    {
        $name = $this->firstArgument($args) ?? '';

        if ($name === '') {
            $this->error('用法：sr make:controller Admin/PostController');

            return 2;
        }

        $segments = array_values(array_filter(explode('/', str_replace('\\', '/', $name))));

        if (count($segments) !== 2) {
            $this->error('控制器名应形如 {域}/{名称}，例如 Admin/PostController');

            return 2;
        }

        [$domain, $class] = $segments;

        if (!$this->isStudly($domain) || !$this->isStudly($class)) {
            $this->error('域与控制器名只能包含字母数字，且以字母开头');

            return 2;
        }

        $domain = ucfirst($domain);
        $class = ucfirst($class);
        $file = $this->app->basePath('app/Api/' . $domain . '/Controller/' . $class . '.php');

        if (is_file($file)) {
            $this->error('控制器已存在：' . $file);

            return 1;
        }

        $code = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "namespace App\\Api\\{$domain}\\Controller;\n\n"
            . "use SunsetReverie\\Http\\Request;\n\n"
            . "/**\n * {$domain} 域 {$class}。\n */\n"
            . "final class {$class}\n{\n"
            . "    /**\n     * 列表。\n     *\n     * @param Request \$request 当前请求\n     * @return array<string, mixed> 响应数据\n     */\n"
            . "    public function index(Request \$request): array\n    {\n        return ['items' => []];\n    }\n\n"
            . "    /**\n     * 详情。\n     *\n     * @param int \$id 主键\n     * @return array<string, mixed> 响应数据\n     */\n"
            . "    public function show(int \$id): array\n    {\n        return ['id' => \$id];\n    }\n\n"
            . "    /**\n     * 创建。\n     *\n     * @param Request \$request 当前请求\n     * @return array<string, mixed> 响应数据\n     */\n"
            . "    public function store(Request \$request): array\n    {\n        return ['created' => true];\n    }\n}\n";

        return $this->write($file, $code, 'routes/' . strtolower($domain) . '.php');
    }

    /**
     * make:model {Name}
     *
     * @param list<string> $args 参数
     * @return int 退出码
     */
    private function model(array $args): int
    {
        $name = $this->trimmedArgument($args);

        if ($name === null) {
            $this->error('用法：sr make:model Post');

            return 2;
        }

        if (!$this->isStudly($name)) {
            $this->error('模型名只能包含字母数字，且以字母开头');

            return 2;
        }

        $class = ucfirst($name) . 'Service';
        $table = $this->snake($name);
        $file = $this->app->basePath('app/Domain/' . ucfirst($name) . '/' . $class . '.php');

        if (is_file($file)) {
            $this->error('领域服务已存在：' . $file);

            return 1;
        }

        $code = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "namespace App\\Domain\\" . ucfirst($name) . ";\n\n"
            . "use SunsetReverie\\Database\\Connection;\n\n"
            . "/**\n * {$name} 领域服务（四端共用，禁止依赖 App\\Api）。\n */\n"
            . "final class {$class}\n{\n"
            . "    /**\n     * @param Connection \$db 数据库连接\n     */\n"
            . "    public function __construct(private readonly Connection \$db)\n    {\n    }\n\n"
            . "    /**\n     * 分页查询。\n     *\n     * @param int \$page 页码\n     * @param int \$perPage 每页条数\n     * @return array<string, mixed> 分页结果\n     */\n"
            . "    public function paginate(int \$page = 1, int \$perPage = 20): array\n    {\n"
            . "        \$page = max(1, \$page);\n"
            . "        \$perPage = min(100, max(1, \$perPage));\n\n"
            . "        \$total = \$this->db->table('{$table}')->count();\n"
            . "        \$rows = \$this->db->table('{$table}')\n"
            . "            ->orderBy('id', 'desc')\n"
            . "            ->limit(\$perPage)\n"
            . "            ->offset((\$page - 1) * \$perPage)\n"
            . "            ->get();\n\n"
            . "        return ['items' => \$rows, 'total' => \$total, 'page' => \$page, 'per_page' => \$perPage];\n    }\n\n"
            . "    /**\n     * 按主键查询。\n     *\n     * @param int \$id 主键\n     * @return array<string, mixed>|null 记录\n     */\n"
            . "    public function find(int \$id): ?array\n    {\n        return \$this->db->table('{$table}')->where('id', \$id)->first();\n    }\n}\n";

        return $this->write($file, $code, null, '提示：在 Provider 中注册（构造依赖 Connection），或直接用容器自动解析');
    }

    /**
     * make:middleware {Name} {--domain=}
     *
     * @param list<string> $args 参数
     * @return int 退出码
     */
    private function middleware(array $args): int
    {
        $name = $this->trimmedArgument($args);

        if ($name === null) {
            $this->error('用法：sr make:middleware EnsureToken');

            return 2;
        }

        if (!$this->isStudly($name)) {
            $this->error('中间件名只能包含字母数字，且以字母开头');

            return 2;
        }

        $class = ucfirst($name);
        $file = $this->app->basePath('app/Http/Middleware/' . $class . '.php');

        if (is_file($file)) {
            $this->error('中间件已存在：' . $file);

            return 1;
        }

        $code = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "namespace App\\Http\\Middleware;\n\n"
            . "use Closure;\n"
            . "use SunsetReverie\\Http\\Middleware\\MiddlewareInterface;\n"
            . "use SunsetReverie\\Http\\Request;\n"
            . "use SunsetReverie\\Http\\Response;\n\n"
            . "/**\n * {$class} 中间件。\n */\n"
            . "final class {$class} implements MiddlewareInterface\n{\n"
            . "    /**\n     * {@inheritDoc}\n     */\n"
            . "    public function handle(Request \$request, Closure \$next): Response\n    {\n"
            . "        // 在进入控制器前做点什么\n"
            . "        \$response = \$next(\$request);\n\n"
            . "        // 在响应返回前做点什么\n"
            . "        return \$response;\n    }\n}\n";

        return $this->write($file, $code, null, '提示：在路由上用 ->middleware([' . $class . '::class]) 启用');
    }

    /**
     * make:request {Domain/Name}
     *
     * @param list<string> $args 参数
     * @return int 退出码
     */
    private function request(array $args): int
    {
        $name = $this->trimmedArgument($args);

        if ($name === null) {
            $this->error('用法：sr make:request Admin/StorePostRequest');

            return 2;
        }

        $segments = array_values(array_filter(explode('/', str_replace('\\', '/', $name))));

        if (count($segments) !== 2 || !$this->isStudly($segments[0]) || !$this->isStudly($segments[1])) {
            $this->error('请求类名应形如 {域}/{名称}，例如 Admin/StorePostRequest');

            return 2;
        }

        [$domain, $class] = [ucfirst($segments[0]), ucfirst($segments[1])];
        $file = $this->app->basePath('app/Api/' . $domain . '/Request/' . $class . '.php');

        if (is_file($file)) {
            $this->error('请求类已存在：' . $file);

            return 1;
        }

        $code = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "namespace App\\Api\\{$domain}\\Request;\n\n"
            . "use SunsetReverie\\Validation\\FormRequest;\n\n"
            . "/**\n * {$class}：{$domain} 域请求校验。\n *\n"
            . " * 控制器里直接声明参数即可，框架会自动校验：\n"
            . " *   public function store({$class} \$request): array\n"
            . " *   {\n"
            . " *       \$data = \$request->validated();\n"
            . " *   }\n */\n"
            . "final class {$class} extends FormRequest\n{\n"
            . "    /**\n     * 校验规则。\n     *\n     * @return array<string, string> 规则表\n     */\n"
            . "    public function rules(): array\n    {\n"
            . "        return [\n"
            . "            'title' => 'required|string|max:200',\n"
            . "        ];\n    }\n}\n";

        return $this->write($file, $code);
    }

    /**
     * make:seeder {Name}
     *
     * @param list<string> $args 参数
     * @return int 退出码
     */
    private function seeder(array $args): int
    {
        $name = $this->trimmedArgument($args) ?? 'seed';

        if (!$this->isStudly($name)) {
            $this->error('填充器名只能包含字母数字，且以字母开头');

            return 2;
        }

        $file = $this->app->basePath('scripts/' . $name . '.php');

        if (is_file($file)) {
            $this->error('填充脚本已存在：' . $file);

            return 1;
        }

        $code = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "/**\n * 数据填充：php scripts/{$name}.php（或 sr db:seed {$name}）\n */\n\n"
            . "require dirname(__DIR__) . '/sunsetreverie/core/Foundation/autoload.php';\n\n"
            . "use SunsetReverie\\Foundation\\Application;\n\n"
            . "\$app = Application::boot(dirname(__DIR__));\n"
            . "\$db = \$app->db();\n"
            . "\$now = date('Y-m-d H:i:s');\n\n"
            . "// 在这里写入种子数据，例如：\n"
            . "// \$db->table('users')->insert(['name' => 'demo', 'created_at' => \$now]);\n\n"
            . "echo '填充完成', PHP_EOL;\n";

        return $this->write($file, $code, null, '提示：执行 php scripts/' . $name . '.php 或 sr db:seed ' . $name);
    }

    /**
     * make:domain {name}
     *
     * @param list<string> $args 参数
     * @return int 退出码
     */
    private function domain(array $args): int
    {
        $name = $this->trimmedArgument($args);

        if ($name === null) {
            $this->error('用法：sr make:domain report');

            return 2;
        }

        $slug = strtolower($name);

        if (preg_match('/^[a-z][a-z0-9-]{1,30}$/', $slug) !== 1) {
            $this->error('域名只能包含小写字母、数字与短横线，且以字母开头');

            return 2;
        }

        $studly = ucfirst(str_replace('-', '', $slug));
        $routeFile = $this->app->basePath('routes/' . $slug . '.php');
        $controllerFile = $this->app->basePath('app/Api/' . $studly . '/Controller/HomeController.php');

        if (is_file($routeFile) || is_file($controllerFile)) {
            $this->error('该域已存在（routes/' . $slug . '.php 或 app/Api/' . $studly . '/）');

            return 1;
        }

        $controller = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "namespace App\\Api\\{$studly}\\Controller;\n\n"
            . "use SunsetReverie\\Http\\Request;\n\n"
            . "/**\n * {$slug} 域示例控制器。\n */\n"
            . "final class HomeController\n{\n"
            . "    /**\n     * 示例接口。\n     *\n     * @param Request \$request 当前请求\n     * @return array<string, mixed> 响应数据\n     */\n"
            . "    public function index(Request \$request): array\n    {\n"
            . "        return ['domain' => \$request->attribute('api.domain')];\n    }\n}\n";

        $routes = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "use App\\Api\\{$studly}\\Controller\\HomeController;\n"
            . "use SunsetReverie\\Support\\Facades\\Route;\n\n"
            . "/**\n * {$slug} 域路由入口（域前缀 /{$slug} 与域名前缀 {$slug}. 由框架自动套用）。\n */\n"
            . "return function (): void {\n"
            . "    Route::group(['prefix' => 'v1', 'as' => 'v1.'], static function (): void {\n"
            . "        Route::get('/hello', [HomeController::class, 'index'], 'hello');\n"
            . "    });\n};\n";

        $code = $this->write($controllerFile, $controller, null, null);

        if ($code !== 0) {
            return $code;
        }

        $code = $this->write($routeFile, $routes, null, null);

        if ($code !== 0) {
            return $code;
        }

        $this->line('');
        $this->line('还需手动在 config/api.php 的 domains 中加入：');
        $this->line('');
        $this->line("    '{$slug}' => [");
        $this->line("        'prefix' => '{$slug}',");
        $this->line("        'versions' => ['v1'],");
        $this->line('    ],');
        $this->line('');
        $this->line('（不自动改配置：改配置文件容易破坏注释与格式，交给人确认更安全）');

        return 0;
    }

    /**
     * 未知生成命令。
     *
     * @param string $command 命令名
     * @return int 退出码
     */
    private function unknown(string $command): int
    {
        $this->error(sprintf('未知生成命令：make:%s', $command));

        return 2;
    }

    /**
     * 写入文件并输出结果。
     *
     * @param string $file 绝对路径
     * @param string $code 文件内容
     * @param string|null $routeHint 路由文件提示
     * @param string|null $extraHint 额外提示（null 表示用默认）
     * @return int 退出码
     */
    private function write(string $file, string $code, ?string $routeHint = null, ?string $extraHint = null): int
    {
        $directory = dirname($file);

        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            $this->error('无法创建目录：' . $directory);

            return 1;
        }

        if (file_put_contents($file, $code) === false) {
            $this->error('写入失败：' . $file);

            return 1;
        }

        @chmod($file, 0644);
        $this->line('已生成：' . $file);

        if ($routeHint !== null) {
            $this->line('提示：记得在 ' . $routeHint . ' 中注册路由');
        } elseif ($extraHint !== null) {
            $this->line($extraHint);
        }

        return 0;
    }

    /**
     * 取第一个非选项参数。
     *
     * @param list<string> $args 参数
     * @return string|null 参数值
     */
    private function firstArgument(array $args): ?string
    {
        foreach ($args as $arg) {
            if (!str_starts_with($arg, '-')) {
                return $arg;
            }
        }

        return null;
    }

    /**
     * 取第一个非选项参数并去空白（空串视为未提供）。
     *
     * @param list<string> $args 参数
     * @return string|null 参数值
     */
    private function trimmedArgument(array $args): ?string
    {
        $value = $this->firstArgument($args);

        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * 是否为合法的类名片段。
     *
     * @param string $value 值
     * @return bool 合法返回 true
     */
    private function isStudly(string $value): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $value) === 1;
    }

    /**
     * 驼峰转下划线复数（Post → posts，CategoryItem → category_items）。
     *
     * @param string $name 类名
     * @return string 表名
     */
    private function snake(string $name): string
    {
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));

        return str_ends_with($snake, 's') ? $snake : $snake . 's';
    }

    /**
     * 输出一行。
     *
     * @param string $message 内容
     * @return void
     */
    private function line(string $message): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }

    /**
     * 输出错误。
     *
     * @param string $message 内容
     * @return void
     */
    private function error(string $message): void
    {
        fwrite(STDERR, $message . PHP_EOL);
    }
}
