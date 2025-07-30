<?php

namespace Thoughtco\StatamicStacheSqlite\Assets;

use Illuminate\Support\Str;
use Statamic\Assets\AssetCollection;
use Statamic\Contracts\Assets\QueryBuilder;
use Statamic\Facades\Blink;
use Statamic\Query\EloquentQueryBuilder;
use Thoughtco\StatamicStacheSqlite\Models\Asset as AssetModel;

class AssetQueryBuilder extends EloquentQueryBuilder implements QueryBuilder
{
    protected function column($column)
    {
        if (! is_string($column)) {
            return $column;
        }

        $table = Str::contains($column, '.') ? Str::before($column, '.') : '';
        $column = Str::after($column, '.');

        $columns = Blink::once('asset-columns', fn () => (new AssetModel)->resolveConnection()->getSchemaBuilder()->getColumnListing((new AssetModel)->getTable()));

        if (! in_array($column, $columns)) {
            if (! Str::startsWith($column, 'data->')) {
                $column = 'data->'.$column;
            }
        }

        return ($table ? $table.'.' : '').$column;
    }

    public function find($id, $columns = ['*'])
    {
        if ($result = Blink::once("asset-{$id}", fn () => parent::find($id))) {
            return $result->selectedQueryColumns($columns);
        }

        Blink::forget("asset-{$id}");

        return false;
    }

    protected function transform($items, $columns = [])
    {
        return AssetCollection::make($items)->map(function ($model) use ($columns) {
            return Blink::once("asset-{$model->id}", function () use ($model) {
                return $model->makeContract();
            })->selectedQueryColumns($this->selectedQueryColumns ?? $columns);
        });
    }
}
