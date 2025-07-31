<?php

namespace Thoughtco\StatamicStacheSqlite\Contracts;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;

interface Driver
{
    public function shouldRestoreCache(Model $model, array $resolvers): bool;

    public function save(Model $model): bool;

    public function delete(Model $model): bool;

    public function all(Model $model, string $handle, Closure $fileResolver): LazyCollection;
}
