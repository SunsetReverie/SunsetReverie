<?php

declare(strict_types=1);

/**
 * 应用入口（单入口多域：按 host / 前缀分发到不同 API 域）。
 */

$basePath = dirname(__DIR__);

// 1) 可选：加载 Composer 自动加载器（负责 App\ 与第三方库）
if (is_file($basePath . '/vendor/autoload.php')) {
    require $basePath . '/vendor/autoload.php';
}

// 2) 框架位置（由 sr new / sr framework:link 维护；相对路径基于项目根）
$frameworkPath = '{{FRAMEWORK_PATH}}';

if (!str_starts_with($frameworkPath, '/') && preg_match('/^[A-Za-z]:/', $frameworkPath) !== 1) {
    $frameworkPath = $basePath . '/' . ltrim($frameworkPath, '/');
}

// 3) 框架自举：注册加载器、构建容器、处理请求
require $frameworkPath . '/core/Foundation/autoload.php';

\SunsetReverie\Foundation\Application::boot($basePath)
    ->handleRequest()
    ->send();
