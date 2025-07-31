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

                $this->generateMetaWhereItIsMissing();
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

    private function generateMetaWhereItIsMissing()
    {
        \Statamic\Facades\Asset::query()
            ->where('meta_file_exists', false)
            ->lazy()
            ->each(function ($asset) {
                $meta = $asset->generateMeta();
                $asset->model()->addMetaToCache($asset, $meta);

                $asset->save();
            });
    }

    protected function transform($items, $columns = [])
    {
        return AssetCollection::make($items)->map(function ($model) use ($columns) {
            $asset = Blink::once("asset-{$model->id}", function () use ($model) {
                return $model->makeContract();
            });

            // set cache again here, to ensure we have the latest data
            $model->addMetaToCache($asset, $model->toArray());

            return $asset->selectedQueryColumns($this->selectedQueryColumns ?? $columns);
        });
    }
}
