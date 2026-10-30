<?php

declare(strict_types=1);

/*
 * 分页配置。
 *
 * 为什么需要全局上限：分页大小原来由各领域服务自己夹取（`min(50, ...)`），
 * 各写各的数字——改上限要翻遍代码，漏改一处就能被拉出 10 万条。
 *
 * 控制器里统一用：
 *   $perPage = $request->perPage();          // 读这里的上限
 *   $page    = $request->page();
 */
return [
    // 单页最大条数（客户端传更大的值会被夹到这个数）
    'max_per_page' => (int) env('PAGINATION_MAX', 50),

    // 客户端未指定时的默认条数
    'default_per_page' => (int) env('PAGINATION_DEFAULT', 20),
];
