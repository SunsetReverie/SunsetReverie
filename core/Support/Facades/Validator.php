<?php

declare(strict_types=1);

namespace SunsetReverie\Support\Facades;

use SunsetReverie\Support\Facade;

/**
 * 校验门面。
 *
 * @method static array validate(array $data, array $rules)
 * @method static array errors(array $data, array $rules)
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
