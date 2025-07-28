<?php

namespace Thoughtco\StatamicStacheSqlite\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
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
use Thoughtco\StatamicStacheSqlite\Models\Concerns\Flatfile;

class Entry extends Model
{
    use Flatfile;
    use HasUuids;

    public static $driver = 'stache';

    protected function casts(): array
    {
        return [
            'data' => AsArrayObject::class,
            'date' => 'datetime',
        ];
    }

    public function getKeyName()
    {
        return 'id';
    }

    public static function getOrbitalPath()
    {
        return rtrim(Stache::store('entries')->directory(), '/');
    }

    public function getIncrementing()
    {
        return false;
    }

    public function makeContract()
    {
        $contract = app(EntryContract::class)::make();

        $attributes = collect($this->getAttributes())
            ->map(function ($_, $key) {
                $value = $this->{$key};

                if ($value instanceof \BackedEnum) {
                    return $value->value;
                }

                if ($value instanceof Carbon) {
                    if ($this->getRawOriginal($key) == '') {
                        return null;
                    }

                    return $value;
                }

                return $value;
            })
            ->toArray();

        foreach ($attributes as $key => $value) {
            if (in_array($key, ['created_at', 'updated_at', 'file_path_read_from', 'path'])) {
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

                $contract->$key($value);
            }
        }

        return $contract;
    }

    public function fromPath(string $originalPath)
    {
        return $this->fromPathAndContents($originalPath, File::get($originalPath));
    }

    public function fromPathAndContents(string $originalPath, string $contents)
    {
        $path = Str::after($originalPath, static::getOrbitalPath().DIRECTORY_SEPARATOR);

        $collectionHandle = Str::before($path, DIRECTORY_SEPARATOR);

        if ($collectionHandle == $path) {
            return;
        }

        $data = [
            'collection' => $collectionHandle,
            'site' => Site::default()->handle(),
        ];

        // need to date, site etc
        $slug = Str::of($path)->after($collectionHandle.DIRECTORY_SEPARATOR)->before('.md');

        if (Site::multiEnabled()) {
            $data['site'] = $slug->before(DIRECTORY_SEPARATOR)->value();
            $slug = $slug->after(DIRECTORY_SEPARATOR);
        }

        if ($slug->contains('.')) {
            $data['date'] = $slug->before('.')->value();
            $slug = $slug->after('.');
        }

        $data['slug'] = $slug->afterLast(DIRECTORY_SEPARATOR)->value();

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

        $entry = $this->makeInstanceFromData($data);
        Blink::store('structure-entries')->put($id, $entry);
        Blink::put("entry-{$id}", $entry);
        // Blink::put("origin-Entry-{$id}", $entry); // @TODO: why doensnt this just use entry-{id} ?

        $data['updateAfterInsert'] = function () use ($id) {
            if (! $entry = \Statamic\Facades\Entry::find($id)) {
                return [];
            }

            if (! $uri = $entry->uri()) {
                return [];
            }

            return [
                'uri' => $uri,
            ];
        };

        return $data;
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

        $collection = $entry->collection();

        $model->blueprint = $entry->blueprint()->handle();
        $model->collection = $collection->handle();
        $model->site = $entry->locale();

        foreach (['id', 'data', 'date', 'published', 'slug'] as $key) {
            $model->$key = $entry->{$key}();
        }

        if (! $model->id) {
            $model->id = Str::uuid()->toString();

            if (! $entry->id()) {
                $entry->id($model->id);
            }
        }

        $model->path = Str::of($entry->buildPath())->after(static::getOrbitalPath().DIRECTORY_SEPARATOR)->beforeLast('.'.$this->fileExtension())->value();
        $model->uri = $entry->uri();
        $model->origin = $entry->origin()?->id();

        return $model;
    }

    public function makeItemFromFile($path, $contents)
    {
        $data = $this->fromPathAndContents($path, $contents);

        $data['path'] = $path;

        $entry = $this->makeInstanceFromData($data);

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
        $collection = Collection::findByHandle($collectionHandle);

        $entry = \Statamic\Facades\Entry::make()
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

        $path = Str::of($path)->after($collectionHandle.DIRECTORY_SEPARATOR)->value();

        // handle slugs like xx/yy
        //        $slugDirectory = Str::of($path)->beforeLast(DIRECTORY_SEPARATOR)->value();
        //        if ($slugDirectory == $path) {
        //            $slugDirectory = false;
        //        }

        $slug = (new GetSlugFromPath)(Str::of($path)->after(DIRECTORY_SEPARATOR)->value());

        //        if ($id == 'pages-directors') {
        //            //dd($path);
        //            dd($slug);
        //        }

        if (! $collection->requiresSlugs() && $slug == $id) {
            $entry->slug(null);
        } else {
            $entry->slug($slug);
        }

        if ($collection->dated()) {
            $entry->date((new GetDateFromPath)($path));
        }

        return $entry;
    }

    public static function schema(Blueprint $table)
    {
        $table->string('id')->unique();
        $table->string('file_path_read_from')->nullable();
        $table->string('path');
        $table->string('blueprint')->nullable()->default(null);
        $table->string('collection');
        $table->json('data')->nullable()->default(null);
        $table->datetime('date')->nullable()->default(null);
        $table->boolean('published')->default(true);
        $table->string('site');
        $table->string('slug')->nullable()->default(null);
        $table->string('uri')->nullable()->default(null);
        $table->string('origin')->nullable()->default(null);
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

        return array_merge($array, $data);
    }

    public function fileExtension()
    {
        return 'md';
    }
}
