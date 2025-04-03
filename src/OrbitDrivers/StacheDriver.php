<?php

namespace Thoughtco\StatamicStacheSqlite\OrbitDrivers;

use BackedEnum;
use FilesystemIterator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Orbit\Contracts\Driver as DriverContract;
use Orbit\Facades\Orbit;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Statamic\Facades\YAML;

class StacheDriver implements DriverContract
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
        if ($model->wasChanged($model->getKeyName())) {
            unlink($this->filepath($directory, $model->getOriginal($model->getKeyName())));
        }

        $path = $this->filepath($directory, $model->getKey());

        file_put_contents($path, $this->dumpContent($model));

        return true;
    }

    public function delete(Model $model, string $directory): bool
    {
        unlink($this->filepath($directory, $model->getKey()));

        return true;
    }

    public function all(string $directory): Collection
    {
        $collection = Collection::make();
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                $files = $this->all($file->getRealPath());

                $collection->merge($files);

                continue;
            }

            if ($file->getExtension() !== $this->extension()) {
                continue;
            }

            $collection->push(array_merge($this->parseContent($file), [
                'file_path_read_from' => $file->getRealPath(),
            ]));
        }

        return $collection;
    }

    public function filepath(string $directory, string $key): string
    {
        return $directory . DIRECTORY_SEPARATOR . $key . '.' . $this->extension();
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
            return $key !== 'content' && $value !== null;
        }, ARRAY_FILTER_USE_BOTH);

        return YAML::dump($matter);
    }

    protected function parseContent(SplFileInfo $file): array
    {
        return YAML::file($file->getPathname())->parse();
    }

    protected function extension(): string
    {
        return 'md';
    }
}
