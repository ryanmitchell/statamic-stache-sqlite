<?php

namespace Thoughtco\StatamicStacheSqlite\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Storage;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blink;
use Statamic\Facades\YAML;
use Statamic\Support\Arr;
use Statamic\Support\Str;
use Thoughtco\StatamicStacheSqlite\Contracts\Driver;
use Thoughtco\StatamicStacheSqlite\Models\Concerns\StoreAsFlatfile;

class Asset extends Model
{
    use HasUuids;
    use StoreAsFlatfile;

    public static $driver = 'stache';

    protected $fillable = ['container', 'folder', 'basename'];

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

    public static function getFlatfileResolvers()
    {
        return AssetContainer::all()
            ->mapWithKeys(fn ($container) => [$container->handle => fn () => $container->files()]) // map files to be prefixed with the disk_handle:: so we can get the container in fromPath below
            ->all();
    }

    public function getIncrementing()
    {
        return false;
    }

    public function makeContract()
    {
        $contract = (new \Thoughtco\StatamicStacheSqlite\Assets\Asset)
            ->path($this->path)
            ->container($this->container);

        // add meta to the cache store so it gets resolved by pending meta
        $meta = Arr::except($this->toArray(), ['id', 'file_path_read_from', 'container', 'path', 'folder', 'basename', 'filename', 'extension', 'created_at', 'updated_at']);
        $contract->cacheStore()->forever($contract->metaCacheKey(), $meta);

        $contract->model($this);
        $contract->hydrate();

        return $contract;
    }

    public function fromPath(string $handle, string $originalPath)
    {
        $disk = Storage::disk(AssetContainer::findByHandle($handle)->disk); // yup, all this to allow us to Storage::fake(), yuck

        if (! $meta = $disk->get($this->metaPath($originalPath))) {
            if ($disk->get($originalPath) === null) {
                return null;
            }

            $meta = '';
        }

        return $this->fromPathAndContents($handle.'::'.$originalPath, $meta ?? '');
    }

    public function fromPathAndContents(string $originalPath, string $contents)
    {
        $columns = Blink::once('asset-columns', fn () => $this->getSchemaColumns());

        $yamlData = YAML::parse($contents);

        [$container, $originalPath] = explode('::', $originalPath, 2);

        $pathinfo = pathinfo($originalPath);

        $pathinfo['folder'] = $pathinfo['dirname'] == '.' ? '/' : $pathinfo['dirname'];
        unset($pathinfo['dirname']);

        $data = array_merge(
            collect($columns)->mapWithKeys(fn ($value) => [
                $value => Arr::get(collect(static::$blueprintColumns)->firstWhere('name', $value)?->toArray() ?? [], 'default', ''),
            ])->all(),
            collect($yamlData)->only($columns)->all(),
            $pathinfo,
            ['data' => collect($yamlData)->except($columns)->all()]
        );

        $data['container'] = $container;
        $data['path'] = $originalPath;

        if (! $data['id']) {
            $data['id'] = $data['container'].'::'.$data['path'];
        }

        $this->makeInstanceFromData($data);

        return $data;
    }

    public function fromContract(AssetContract $asset, ?array $meta = [])
    {
        $model = $this;
        if (($id = $asset->id()) && ! $this->id) {
            $model = $this->newQuery()->find($id);
        }

        if (! $model) {
            $model = $this;
        }

        $model->container = $asset->container()->handle();

        foreach (['id', 'path', 'folder', 'basename', 'filename', 'extension'] as $key) {
            $model->$key = $asset->{$key}();
        }

        $meta = $meta ?? $asset->meta();
        foreach (['duration', 'height', 'last_modified', 'mime_type', 'size', 'width'] as $key) {
            $model->$key = $meta[$key] ?? null;
        }

        $model->data = $asset->data()->all();
        $model->path = $asset->path();

        return $model;
    }

    public function makeItemFromFile($path, $contents)
    {
        $data = $this->fromPathAndContents($path, $contents);

        $data['path'] = $path;

        $asset = $this->makeInstanceFromData($data);
        $asset->model($this);

        $asset->hydrate();

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

        // add meta to the cache store so it gets resolved by pending meta
        $meta = Arr::except($data, ['id', 'file_path_read_from', 'container', 'path', 'folder', 'basename', 'filename', 'extension', 'created_at', 'updated_at']);
        $asset->cacheStore()->forever($asset->metaCacheKey, $meta);

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

        // all of this is stored in the meta cache, so its debatable that we need to store it...
        // but I've left it here so sorting can be done in CP
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
            'data' => $this->data?->toArray() ?? [],
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

    private function metaPath(string $path)
    {
        $path = dirname($path).'/.meta/'.basename($path).'.yaml';

        return (string) Str::of($path)->replaceFirst('./', '')->ltrim('/');
    }

    public function writeFlatfile(Driver $driver)
    {
        Storage::disk(AssetContainer::findByHandle($this->container)->disk)->put($this->metaPath($this->path), $this->fileContents());

        return true;
    }

    public function deleteFlatfile(Driver $driver)
    {
        Storage::disk(AssetContainer::findByHandle($this->container)->disk)->delete($this->metaPath($this->path));

        return true;
    }

    protected function shouldRemoveNullsFromFileData()
    {
        return false;
    }
}
