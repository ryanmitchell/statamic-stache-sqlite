<?php

namespace Thoughtco\StatamicStacheSqlite\Facades;

use Illuminate\Support\Facades\Facade;
use Thoughtco\StatamicStacheSqlite\Managers\FlatfileManager;

/**
 * @method static \Thoughtco\StatamicStacheSqlite\Contracts\Driver driver(string|null $driver)
 * @method static \Thoughtco\StatamicStacheSqlite\Managers\FlatfileManager extend(string $driver, \Closure $callback)
 * @method static array getDrivers()
 * @method static string getDefaultDriver()
 * @method static string getDatabasePath()
 * @method static \Thoughtco\StatamicStacheSqlite\Managers\FlatfileManager test()
 * @method static bool isTesting()
 *
 * @see \Thoughtco\StatamicStacheSqlite\Managers\FlatfileManager
 */
class Flatfile extends Facade
{
    protected static function getFacadeAccessor()
    {
        return FlatfileManager::class;
    }
}
