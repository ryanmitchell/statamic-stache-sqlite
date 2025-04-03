<?php

namespace Thoughtco\StatamicStacheSqlite\OrbitDrivers;

use BackedEnum;
use FilesystemIterator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Orbit\Facades\Orbit;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Statamic\Facades\YAML;

class StacheDriver
{
    public function shouldRestoreCache(string $directory): bool
    {
        return true;

        $databaseLastUpdated = filemtime(Orbit::getDatabasePath());

        foreach (new FilesystemIterator($directory) as $file) {
            if ($file->getMTime() > $databaseLastUpdated) {
                return true;
            }
        }

        return false;
    }

    public function save(Model $model, string $directory): bool
    {
        if ($model->wasChanged($model->getPathKeyName())) {
            unlink($this->filepath($directory, $model->getOriginal($model->getPathKeyName())));
        }

        $path = $this->filepath($directory, $model->{$model->getPathKeyName()});

        file_put_contents($path, $this->dumpContent($model));

        return true;
    }

    public function delete(Model $model, string $directory): bool
    {
        unlink($this->filepath($directory, $model->getKey()));

        return true;
    }

    public function all(Model $model, string $directory): Collection
    {
        $collection = Collection::make();
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        $columns = $model->resolveConnection()->getSchemaBuilder()->getColumnListing($model->getTable());

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                $files = $this->all($file->getRealPath());

                $collection->merge($files);

                continue;
            }

            if ($file->getExtension() !==  'md') {
                continue;
            }

            $collection->push(array_merge($this->parseContent($file, $columns, $model), [
                'file_path_read_from' => $file->getRealPath(),
            ]));
        }

        return $collection;
    }

    public function filepath(string $directory, string $key): string
    {
        return $directory . DIRECTORY_SEPARATOR . $key . '.md';
    }

    protected function getModelAttributes(Model $model)
    {
        return collect($model->getAttributes())
            ->map(function ($_, $key) use ($model) {
                $value = $model->{$key};

                if ($value instanceof BackedEnum) {
                    return $value->value;
                }

                return $value;
            })
            ->toArray();
    }

    protected function dumpContent(Model $model): string
    {
        $matter = array_filter($this->getModelAttributes($model), function ($value, $key) {
            return $value !== null;
        }, ARRAY_FILTER_USE_BOTH);

        if ($data = $matter['data'] ?? false) {
            unset($matter['data']);

            $matter = array_merge($matter, $data);
        }

        return YAML::dumpFrontMatter($matter); // need to handle content
    }

    protected function parseContent(SplFileInfo $file, array $columns = [], Model $model = null): array
    {
        $yamlData = YAML::file($file->getPathname())->parse();

        return array_merge(
            collect($columns)->mapWithKeys(fn ($value) => [$value => ''])->all(),
            $model ? $model->fromPath($file->getPathname()) : [],
            collect($yamlData)->only($columns)->all(),
            ['data' => collect($yamlData)->except($columns)->all()],
        );
    }
}
