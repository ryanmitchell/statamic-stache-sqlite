<?php

namespace Thoughtco\StatamicStacheSqlite\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

interface Driver
{
    public function shouldRestoreCache(Model $model, array $directories): bool;

    public function save(Model $model, string $directory): bool;

    public function delete(Model $model, string $directory): bool;

    public function all(Model $model, string $directory): Collection;
}
