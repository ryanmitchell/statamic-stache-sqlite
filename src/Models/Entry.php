<?php

namespace Thoughtco\StatamicStacheSqlite\Models;

use FilesystemIterator;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Entries\GetDateFromPath;
use Statamic\Entries\GetSlugFromPath;
use Statamic\Facades\Blink;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use Statamic\Facades\YAML;
use Statamic\Support\Arr;
use Statamic\Support\Str;
use Thoughtco\StatamicStacheSqlite\Models\Concerns\StoreAsFlatfile;

class Entry extends Model
{
    use HasUuids;
    use StoreAsFlatfile;

    public static $driver = 'stache';

    protected function casts(): array
    {
        return [
            'data' => AsArrayObject::class,
            'date' => 'datetime',
            'values' => AsArrayObject::class,
        ];
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getFlatfileRootDirectory(): string
    {
        return rtrim(Stache::store('entries')->directory(), '/');
    }

    public static function getFlatfileResolvers(): array
    {
        return [
            'collection-entries' => function () {
                $directory = rtrim(Stache::store('entries')->directory(), '/');

                (new Filesystem)->ensureDirectoryExists($directory);

                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

                // $files = [];
                foreach ($iterator as $file) {
                    if ($file->isDir()) {
                        continue;
                    }

                    if ($file->getExtension() !== 'md') {
                        continue;
                    }

                    yield $file->getPathname();

                    // $files[] = $file->getPathname();
                }

                // return $files;
            },
        ];
    }

    public function getIncrementing()
    {
        return false;
    }

    public function makeContract()
    {
        $contract = (new \Thoughtco\StatamicStacheSqlite\Entries\Entry);

        $attributes = collect($this->getAttributes())
            ->map(function ($_, $key) {
                $value = $this->{$key};

                if ($value instanceof Carbon) {
                    if ($this->getRawOriginal($key) == '') {
                        return null;
                    }

                    return $value;
                }

                return $value;
            })
            ->all();

        Blink::store('structure-entries')->put($this->id, $contract);
        Blink::put("entry-{$this->id}", $contract);

        foreach ($attributes as $key => $value) {
            if (in_array($key, ['created_at', 'updated_at', 'file_path_read_from', 'path', 'uri', 'values'])) {
                continue;
            }

            if ($value !== null) {
                if ($key == 'data' && is_string($value)) {
                    $value = json_decode($value, true);
                }

                if ($key == 'date') {
                    if (! $value) {
                        continue;
                    }
                }

                if ($key == 'site') {
                    $key = 'locale'; // we can get site, but we need to set locale()
                }

                $contract->$key($value);
            }
        }

        return $contract;
    }

    protected function extractAttributesFromPath($path)
    {
        $collection = pathinfo($path, PATHINFO_DIRNAME);
        $collection = Str::after($collection, $path);

        if (Site::multiEnabled()) {
            [$collection, $site] = explode('/', $collection);
        } else {
            $site = Site::default()->handle(); // sorry Erin, did it this way to avoid calling default every time
        }

        // Support entries within subdirectories at any level.
        if (str_contains($collection, '/')) {
            $collection = Str::before($collection, '/');
        }

        return [$collection, $site];
    }

    public function fromPath(string $handle, string $originalPath)
    {
        if (! [$data, $entry] = $this->fromPathAndContents($originalPath, File::get($originalPath))) {
            return;
        }

        return $data;
    }

    public function fromPathAndContents(string $originalPath, string $contents)
    {
        $path = Str::after($originalPath, $this->getFlatfileRootDirectory().DIRECTORY_SEPARATOR);

        [$collectionHandle, $site] = $this->extractAttributesFromPath($path);

        if ($collectionHandle == '.') {
            return;
        }

        $data = [
            'collection' => $collectionHandle,
            'site' => $site,
        ];

        $collection = Blink::once("collection-{$collectionHandle}", fn () => Collection::findByHandle($collectionHandle));

        if ($collection->dated()) {
            $data['date'] = (new GetDateFromPath)($path);
        }

        $data['slug'] = (new GetSlugFromPath)($path);

        $columns = Blink::once('entry-columns', fn () => $this->getSchemaColumns());

        $yamlData = collect(YAML::parse($contents));

        $dataValues = $yamlData->except($columns)->all();

        $data = [
            ...collect($columns)->mapWithKeys(fn ($value) => [
                $value => Arr::get(collect(static::$blueprintColumns)->firstWhere('name', $value)?->toArray() ?? [], 'default', ''),
            ])->all(),
            ...$yamlData->only($columns)->all(),
            ...$data,
            ...['data' => $dataValues, 'values' => $dataValues],
        ];

        $data['path'] = $path;

        // entry uri requires collectionstructure, which requires
        // there to be entries to query, so we first of all insert the entry
        // then deferred update the uri
        $id = $data['id'];

        $entry = $this->makeInstanceFromData($data);
        Blink::store('structure-entries')->put($id, $entry);
        Blink::put("entry-{$id}", $entry);
        // Blink::put("origin-Entry-{$id}", $entry); // @TODO: why doensnt this just use entry-{id} ?

        // we dont need run this if we dont have an origin, or we dont have a uri
        if ($collection->route($site) || $data['origin']) {
            $data['updateAfterInsert'] = function ($insertedIds) use ($entry, $data) {
                // if we have an origin, make sure its already been updated
                if ($origin = ($data['origin'] ?? false)) {
                    if (! in_array($origin, $insertedIds)) {
                        return false;
                    }
                }

                $values = $origin ? [
                    'values' => $entry->values()->all(),
                ] : [];

                if (! $uri = $entry->uri()) {
                    return $values;
                }

                return [
                    ...$values,
                    'uri' => $uri,
                ];
            };
        }

        return [$data, $entry];
    }

    public function fromContract(EntryContract $entry)
    {
        $model = $this;
        if (($id = $entry->id()) && ! $this->id) {
            $model = $this->newQuery()->find($id);
        }

        if (! $model) {
            $model = $this;
        }

        $model->blueprint = $entry->blueprint()->handle();
        $model->collection = $entry->collectionHandle();
        $model->site = $entry->locale();

        foreach (['id', 'date', 'published', 'slug'] as $key) {
            $model->$key = $entry->{$key}();
        }

        $model->data = $entry->data();
        $model->values = $entry->values();

        if (! $model->id) {
            $model->id = Str::uuid()->toString();
            $entry->id($model->id);
        }

        $model->path = Str::of($entry->buildPath())->after($model->getFlatfileRootDirectory().DIRECTORY_SEPARATOR)->beforeLast('.'.$this->fileExtension())->value();
        $model->uri = $entry->uri();
        $model->origin = $entry->origin()?->id();

        return $model;
    }

    public function makeItemFromFile($path, $contents)
    {
        [$data, $entry] = $this->fromPathAndContents($path, $contents);

        $entry->model($this);

        return $entry;
    }

    private function makeInstanceFromData(array $data)
    {
        if (! $id = Arr::pull($data, 'id')) {
            $id = app('stache')->generateId();
        }

        $path = Arr::pull($data, 'path');

        $collectionHandle = $data['collection'];
        $collection = Blink::once("collection-{$collectionHandle}", fn () => Collection::findByHandle($collectionHandle));

        $entry = (new \Thoughtco\StatamicStacheSqlite\Entries\Entry)
            ->id($id)
            ->collection($collection);

        if ($origin = Arr::pull($data, 'origin')) {
            $entry->origin($origin);
        }

        $entry
            ->blueprint($data['blueprint'] ?? null)
            ->locale($data['site'])
            ->initialPath($path)
            ->published(Arr::pull($data, 'published', true))
            ->data($data['data']);

        // @TODO: avoid calling getslugfrompath twice
        $slug = $data['slug']; // (new GetSlugFromPath)(Str::of($path)->after($collectionHandle.DIRECTORY_SEPARATOR)->after(DIRECTORY_SEPARATOR)->value());

        if (! $collection->requiresSlugs() && $slug == $id) {
            $entry->slug(null);
        } else {
            $entry->slug($slug);
        }

        // @TODO: avoid calling GetDateFromPath twice
        // if ($collection->dated()) {
        if (isset($data['date'])) {
            // $entry->date((new GetDateFromPath)($path));
            $entry->date($data['date']);
        }

        return $entry;
    }

    public static function schema(Blueprint $table)
    {
        $table->string('id')->unique()->index();
        $table->string('file_path_read_from')->nullable();
        $table->string('path');
        $table->string('blueprint')->nullable()->default(null);
        $table->string('collection')->index();
        $table->json('data')->nullable()->default(null);
        $table->json('values')->nullable()->default(null);
        $table->datetime('date')->nullable()->default(null);
        $table->boolean('published')->default(true)->index();
        $table->string('site')->index();
        $table->string('slug')->nullable()->default(null)->index();
        $table->string('uri')->nullable()->default(null)->index();
        $table->string('origin')->nullable()->default(null)->index();
    }

    public function fileData()
    {
        //        $origin = $this->origin;
        //        $blueprint = $this->blueprint;
        //
        //        if ($origin && $this->blueprint()->handle() === $origin->blueprint()->handle()) {
        //            $blueprint = null;
        //        }

        $array = Arr::removeNullValues([
            'id' => $this->id,
            'origin' => $this->origin,
            'published' => $this->published === false ? false : null,
            'blueprint' => $this->blueprint,
        ]);

        $data = $this->data->all();

        if (! $this->origin) {
            $data = Arr::removeNullValues($data);
        }

        return [...$array, ...$data];
    }

    public function fileExtension()
    {
        return 'md';
    }
}
