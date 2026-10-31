<?php

declare(strict_types=1);

namespace SunsetReverie\Routing\Exception;

use RuntimeException;

/**
 * 路由注册违规：注册期的结构性错误。
 *
 * 和 DuplicateRouteException 的区别：
 * - DuplicateRoute：同一「方法 + 路径」注册两次（内容冲突）
 * - Registration：注册方式本身不合法（结构错，比如 directory 模式下又加了 prefix）
 *
 * 两者都在启动期抛出，宁可起不来也不要跑起来才发现路由错乱。
 */
final class RouteRegistrationException extends RuntimeException
{
}
