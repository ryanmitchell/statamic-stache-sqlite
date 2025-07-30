<?php

namespace Thoughtco\StatamicStacheSqlite\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use Statamic\Facades\YAML;
use Statamic\Support\Arr;
use Statamic\Support\Str;
use Thoughtco\StatamicStacheSqlite\Models\Concerns\StoreAsFlatfile;

class Asset extends Model
{
    use HasUuids;
    use StoreAsFlatfile;

    public static $driver = 'stache';

    protected function casts(): array
    {
        return [
            'data' => AsArrayObject::class,
        ];
    }

    public function getKeyName()
    {
        return 'id';
    }

    public static function getFlatfilePath()
    {
        return rtrim(Stache::store('assets')->directory(), '/');
    }

    public function makeContract()
    {
        $contract = (new \Thoughtco\StatamicStacheSqlite\Assets\Asset)
            ->path($this->path)
            ->container($this->container)
            ->syncOriginal();

        return $contract;
    }

    protected function extractAttributesFromPath($path)
    {
        $site = Site::default()->handle();
        $collection = pathinfo($path, PATHINFO_DIRNAME);
        $collection = Str::after($collection, $path);

        if (Site::multiEnabled()) {
            [$collection, $site] = explode('/', $collection);
        }

        // Support entries within subdirectories at any level.
        if (Str::contains($collection, '/')) {
            $collection = Str::before($collection, '/');
        }

        return [$collection, $site];
    }

    public function fromPath(string $originalPath)
    {
        return $this->fromPathAndContents($originalPath, File::get($originalPath));
    }

    public function fromPathAndContents(string $originalPath, string $contents)
    {
        dd($contents);
        $columns = $this->getSchemaColumns();

        $yamlData = YAML::parse($contents);

        $data = array_merge(
            collect($columns)->mapWithKeys(fn ($value) => [
                $value => Arr::get(collect(static::$blueprintColumns)->firstWhere('name', $value)?->toArray() ?? [], 'default', ''),
            ])->all(),
            collect($yamlData)->only($columns)->all(),
            $data,
            ['data' => collect($yamlData)->except($columns)->all()]
        );

        $data['path'] = $path;

        // entry uri requires collectionstructure, which requires
        // there to be entries to query, so we first of all insert the entry
        // then deferred update the uri
        $id = $data['id'];

        return $data;
    }

    public function fromContract(AssetContract $asset)
    {
        $model = $this;
        if (($id = $asset->id()) && ! $this->id) {
            $model = $this->newQuery()->find($id);
        }

        if (! $model) {
            $model = $this;
        }

        $model->container = $asset->container();

        foreach (['id', 'path', 'container', 'folder', 'basename', 'filename', 'extension', 'data'] as $key) {
            $model->$key = $asset->{$key}();
        }

        foreach (['duration', 'height', 'last_modified', 'mime_type', 'size', 'width'] as $key) {
            $model->$key = $asset->meta($key);
        }

        // need to figure out how to make this relative to asset container
        $model->path = Str::of($entry->buildPath())->after(static::getFlatfilePath().DIRECTORY_SEPARATOR)->beforeLast('.'.$this->fileExtension())->value();

        return $model;
    }

    public function makeItemFromFile($path, $contents)
    {
        $data = $this->fromPathAndContents($path, $contents);

        $data['path'] = $path;

        $asset = $this->makeInstanceFromData($data);

        $asset->model($this);

        return $asset;
    }

    private function makeInstanceFromData(array $data)
    {
        // if we dont have a cache for this asset's meta, we should store it here to
        // ensure it doesnt need to get regenerated

        $asset = (new \Thoughtco\StatamicStacheSqlite\Assets\Asset)
            ->path($data['path'])
            ->container($data['container'])
            ->syncOriginal();

        return $asset;
    }

    public static function schema(Blueprint $table)
    {
        $table->string('id')->unique()->index();
        $table->string('file_path_read_from')->nullable();
        $table->string('path');
        $table->string('container')->index();
        $table->string('folder')->index();
        $table->string('basename')->index();
        $table->string('filename')->index();
        $table->string('extension')->index();

        // all of this is stored in a cache, so its debateble we need it... left it here so sorting can be done in CP
        $table->integer('duration')->nullable()->default(null);
        $table->integer('height')->nullable()->default(null);
        $table->integer('last_modified')->nullable()->default(null);
        $table->string('mime_type')->nullable()->default(null);
        $table->integer('size')->nullable()->default(null);
        $table->integer('width')->nullable()->default(null);

        $table->json('data')->nullable()->default('[]');
    }

    public function fileData()
    {
        return [
            'data' => $this->data->all(),
            'duration' => $this->duration,
            'height' => $this->height,
            'last_modified' => $this->last_modified,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'width' => $this->width,
        ];
    }

    public function fileExtension()
    {
        return 'yaml';
    }
}
