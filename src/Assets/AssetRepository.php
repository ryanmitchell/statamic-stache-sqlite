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
            ->fromContract($asset);

        $cache = $asset->container()->contents();

        $cache->add($asset->path());

        if ($asset->path() !== ($originalPath = $asset->getOriginal('path'))) {
            $cache->forget($originalPath);
        }

        $cache->save();

        // @TODO: not sure why we need this, but it seems to be necessary for the right data to be in tests
        // some tests dont want this data, and some do, so this workaround solves it
        foreach (['duration', 'height', 'last_modified', 'mime_type', 'size', 'width'] as $key) {
            if (! $model->key) {
                $model->$key = $asset->meta($key) ?? null;
            }
        }

        $model->data = $asset->data()->all();
        // END @TODO

        $model->saveQuietly();
        $model->writeFlatFile();

        $asset->model($model);

        Blink::put("asset-{$asset->id()}", $asset);
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
            ->get()
            ->each(fn ($asset) => $asset->model()?->delete());

        $asset->container()->contents()->forget($asset->path())->save();
    }
}
