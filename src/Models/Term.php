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
use Statamic\Contracts\Taxonomies\Term as TermContract;
use Statamic\Entries\GetSlugFromPath;
use Statamic\Facades\Blink;
use Statamic\Facades\Stache;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\YAML;
use Statamic\Support\Arr;
use Statamic\Support\Str;
use Thoughtco\StatamicStacheSqlite\Models\Concerns\StoreAsFlatfile;

class Term extends Model
{
    use HasUuids;
    use StoreAsFlatfile;

    public static $driver = 'stache';

    protected function casts(): array
    {
        return [
            'data' => AsArrayObject::class,
            // 'values' => AsArrayObject::class,
        ];
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getFlatfileRootDirectory(): string
    {
        return rtrim(Stache::store('terms')->directory(), '/');
    }

    public static function getFlatfileResolvers(): array
    {
        return [
            'taxonomy-terms' => function () {
                $directory = rtrim(Stache::store('terms')->directory(), '/');

                (new Filesystem)->ensureDirectoryExists($directory);

                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

                // $files = [];
                foreach ($iterator as $file) {
                    if ($file->isDir()) {
                        continue;
                    }

                    if ($file->getExtension() !== 'yaml') {
                        continue;
                    }

                    yield $file->getPathname();
                }
            },
        ];
    }

    public function getIncrementing()
    {
        return false;
    }

    public function makeContract()
    {
        $contract = (new \Thoughtco\StatamicStacheSqlite\Terms\Term);

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

        foreach ($attributes as $key => $value) {
            if (in_array($key, ['created_at', 'updated_at', 'file_path_read_from', 'path', 'uri', 'values'])) {
                continue;
            }

            if ($value !== null) {
                if ($key == 'data' && is_string($value)) {
                    $value = json_decode($value, true);
                }

                $contract->$key($value);
            }
        }

        foreach (Arr::pull($this->data, 'localizations', []) as $locale => $localeData) {
            $contract->dataForLocale($locale, $localeData);
        }

        Blink::put("term-{$this->id}", $contract);

        return $contract;
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

        $taxonomyHandle = pathinfo($path, PATHINFO_DIRNAME);

        $data = [
            'taxonomy' => $taxonomyHandle,
        ];

        $data['slug'] = (new GetSlugFromPath)($path);

        $columns = Blink::once('term-columns', fn () => $this->getSchemaColumns());

        $yamlData = collect(YAML::parse($contents));

        $data = [
            ...collect($columns)->mapWithKeys(fn ($value) => [
                $value => Arr::get(collect(static::$blueprintColumns)->firstWhere('name', $value)?->toArray() ?? [], 'default', ''),
            ])->all(),
            ...$yamlData->only($columns)->all(),
            ...$data,
            ...['data' => $yamlData->except($columns)->all()],
        ];

        $data['path'] = $path;
        if (! $data['id']) {
            $data['id'] = $data['taxonomy'].'::'.$data['slug'];
        }

        $id = $data['id'];

        if (! $term = $this->makeInstanceFromData($data)) {
            return [null, null];
        }

        if ($term->collection()) {
            $data['updateAfterInsert'] = function ($insertedIds) use ($term) {
                if (! $uri = $term->uri()) {
                    return;
                }

                return [
                    'uri' => $uri,
                ];
            };
        }

        return [$data, $term];
    }

    public function fromContract(TermContract $term)
    {
        $model = $this;
        if (($id = $term->id()) && ! $this->id) {
            $model = $this->newQuery()->find($id);
        }

        if (! $model) {
            $model = $this;
        }

        $taxonomyHandle = $term->taxonomyHandle();
        $model->taxonomy = $taxonomyHandle;

        $taxonomy = Blink::once("taxonomy-{$taxonomyHandle}", fn () => Taxonomy::findByHandle($taxonomyHandle));
        $model->blueprint = $taxonomy->termBlueprint()->handle() != $term->blueprint() ? $term->blueprint()->handle() : null;

        foreach (['id', 'slug'] as $key) {
            $model->$key = $term->{$key}();
        }

        $defaultLocale = $taxonomy->sites()->first();
        $localizations = $term->localizations();

        $data = $localizations->pull($defaultLocale)->data()->all();
        $data['localizations'] = [];
        foreach ($localizations as $handle => $localization) {
            $data['localizations'][$handle] = $localization->data()->all();
        }

        $model->data = collect($data);
        $model->path = Str::of($term->buildPath())->after($model->getFlatfileRootDirectory().DIRECTORY_SEPARATOR)->beforeLast('.'.$this->fileExtension())->value();
        $model->uri = $term->uri();

        return $model;
    }

    public function makeItemFromFile($path, $contents)
    {
        [$data, $term] = $this->fromPathAndContents($path, $contents);

        $term->model($this);

        return $term;
    }

    private function makeInstanceFromData(array $data)
    {
        $path = Arr::pull($data, 'path');

        $taxonomyHandle = $data['taxonomy'];

        if ($taxonomyHandle == '.') {
            return;
        }

        $taxonomy = Blink::once("taxonomy-{$taxonomyHandle}", fn () => Taxonomy::findByHandle($taxonomyHandle));

        if (! $taxonomy) {
            return;
        }

        if ($data['blueprint'] == $taxonomy->termBlueprint()->handle()) {
            unset($data['blueprint']);
        }

        $term = (new \Thoughtco\StatamicStacheSqlite\Terms\Term)
            ->taxonomy($taxonomyHandle)
            ->slug($data['slug'])
            ->initialPath($path)
            ->blueprint($data['blueprint'] ?? null);

        foreach (Arr::pull($data, 'localizations', []) as $locale => $localeData) {
            $term->dataForLocale($locale, $localeData);
        }

        $term->dataForLocale($term->defaultLocale(), $data);

        return $term;
    }

    public static function schema(Blueprint $table)
    {
        $table->string('id')->unique()->index();
        $table->string('file_path_read_from')->nullable();
        $table->string('path');
        $table->string('blueprint')->nullable()->default(null);
        $table->string('taxonomy')->index();
        $table->json('data')->nullable()->default(null);
        // $table->json('values')->nullable()->default(null);
        $table->string('slug')->nullable()->default(null)->index();
        $table->string('uri')->nullable()->default(null)->index();
    }

    public function fileData()
    {
        $array = Arr::removeNullValues(
            $this->data->all(),
        );

        // todo: add published bool (for each locale?)

        if ($this->blueprint) {
            $array['blueprint'] = $this->blueprint;
        }

        return $array;
    }

    public function fileExtension()
    {
        return 'yaml';
    }
}
