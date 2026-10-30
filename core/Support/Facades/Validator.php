<?php

declare(strict_types=1);

namespace SunsetReverie\Support\Facades;

use SunsetReverie\Support\Facade;

/**
 * 校验门面。
 *
 * @method static array<string, mixed> validate(array<string, mixed> $data, array<string, mixed> $rules)
 * @method static array<string, list<string>> errors(array<string, mixed> $data, array<string, mixed> $rules)
 */
final class Validator extends Facade
{
    /**
     * {@inheritDoc}
     */
    protected static function accessor(): string
    {
        return \SunsetReverie\Validation\Validator::class;
    }
}
