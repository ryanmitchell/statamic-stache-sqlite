<?php

namespace Thoughtco\StatamicStacheSqlite\Facades;

use Illuminate\Support\Facades\Facade;
use Thoughtco\StatamicStacheSqlite\Managers\FlatfileManager;

/**
 * @method static \Thoughtco\StatamicStacheSqlite\Contracts\Driver driver(string|null $driver)
 * @method static \Thoughtco\StatamicStacheSqlite\Managers\FlatfileManager extend(string $driver, \Closure $callback)
 * @method static string getDefaultDriver()
 * @method static string getDatabaseName()
 * @method static \Illuminate\Database\Connection connection(string|null $connection = null)
 * @method static \Carbon\CarbonInterface databaseUpdatedAt(\Carbon\CarbonInterface|null $datetime = null)
 * @method static void clear()
 * @method static void warm()
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
