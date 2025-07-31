<?php

namespace Thoughtco\StatamicStacheSqlite\Assets;

use Statamic\Assets\Asset as FileAsset;
use Statamic\Facades\AssetContainer as AssetContainerAPI;
use Thoughtco\StatamicStacheSqlite\Models\Asset as AssetModel;

class Asset extends FileAsset
{
    private ?AssetModel $model = null;

    public function model($model = null)
    {
        if (! $model) {
            return $this->model ?? ($this->model = AssetModel::find($this->id()));
        }

        $this->model = $model;

        return $this;
    }

    public function writeMeta($meta)
    {
        parent::writeMeta($meta);

        $model = $this->model() ?? AssetModel::find($this->id()) ?? AssetModel::make();
        $model->fromContract($this, $meta);
        $model->data = $meta;

        $this->model($model); // @TODO: we dont actually save until the asset is saved, not sure why, but its how files are done
    }

    public function container($container = null)
    {
        return $this
            ->fluentlyGetOrSet('container')
            ->setter(function ($container) {
                return is_string($container) ? AssetContainerAPI::findByHandle($container) : $container; // @TODO: literally done this to avoid needing to add find() to a bunch of mockery calls, long term we update the tests
            })
            ->args(func_get_args());
    }
}
