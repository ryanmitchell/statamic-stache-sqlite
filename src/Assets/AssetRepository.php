<?php

namespace Thoughtco\StatamicStacheSqlite\Assets;

use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Contracts\Assets\QueryBuilder as QueryBuilderContract;
use Statamic\Facades\Blink;
use Thoughtco\StatamicStacheSqlite\Models\Asset as AssetModel;

class AssetRepository extends \Statamic\Assets\AssetRepository
{
    public static function bindings(): array
    {
        return [
            AssetContract::class => Asset::class,
            QueryBuilderContract::class => AssetQueryBuilder::class,
        ];
    }

    public function save($asset)
    {
        $model = $asset->model() ?? AssetModel::find($asset->id()) ?? AssetModel::firstOrNew([
            'container' => $asset->container(),
            'folder' => $asset->folder(),
            'basename' => $asset->basename(),
        ]);

        $model
            ->fromContract($asset)
            ->touch();

        $model->save();

        $asset->model($model);

        Blink::once("asset-{$asset->id()}", fn () => $asset);
    }

    public function delete($asset)
    {
        if ($id = $asset->id()) {
            Blink::forget("asset-{$id}");
        }

        $this->query()
            ->where([
                'container' => $asset->container(),
                'folder' => $asset->folder(),
                'basename' => $asset->basename(),
            ])
            ->delete();
    }
}
