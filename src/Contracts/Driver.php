<?php

namespace Thoughtco\StatamicStacheSqlite\Contracts;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

interface Driver
{
    public function shouldRestoreCache(Model $model, array $resolvers): bool;

    public function save(Model $model, string $directory): bool;

    public function delete(Model $model, string $directory): bool;

    public function all(Model $model, Closure $fileResolver): Collection;
}
