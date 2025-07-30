<?php

namespace Thoughtco\StatamicStacheSqlite\Assets;

use Statamic\Assets\Asset as FileAsset;
use ThoughtCo\StatamicStacheSqlite\Models\Asset as AssetModel;

class Asset extends FileAsset
{
    private ?AssetModel $model = null;

    public function model($model = null)
    {
        if (! $model) {
            return $this->model;
        }

        $this->model = $model;

        return $this;
    }
}
