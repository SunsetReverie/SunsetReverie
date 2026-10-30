<?php

declare(strict_types=1);

namespace SunsetReverie\Support\Facades;

use SunsetReverie\Auth\AuthManager;
use SunsetReverie\Support\Facade;

/**
 * 认证门面。
 *
 * @method static object guard(?string $name = null)
 * @method static \SunsetReverie\Auth\Guard\JwtGuard jwt(?string $name = null)
 */
final class Auth extends Facade
{
    /**
     * {@inheritDoc}
     */
    protected static function accessor(): string
    {
        return AuthManager::class;
    }
}
