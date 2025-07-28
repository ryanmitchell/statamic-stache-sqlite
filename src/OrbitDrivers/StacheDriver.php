<?php

namespace Thoughtco\StatamicStacheSqlite\OrbitDrivers;

use BackedEnum;
use FilesystemIterator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Orbit\Facades\Orbit;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Statamic\Entries\GetSuffixFromPath;
use Statamic\Entries\RemoveSuffixFromPath;
use Statamic\Facades\File;
use Statamic\Facades\Stache;
use Statamic\Support\Str;

class StacheDriver
{
    public function shouldRestoreCache(string $directory): bool
    {
        // if there is no watcher, always use existing cache
        if (! Stache::isWatcherEnabled()) {
            return false;
        }

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
        $path = $this->filepath($directory, $model);

        if ($model->file_path_read_from && ($path != $model->file_path_read_from)) {
            unlink($model->file_path_read_from);
        }

        $fs = new Filesystem;
        $fs->ensureDirectoryExists(dirname($path));

        file_put_contents($path, $model->fileContents());

        return true;
    }

    public function delete(Model $model, string $directory): bool
    {
        unlink($this->filepath($directory, $model));

        return true;
    }

    public function all(Model $model, string $directory): Collection
    {
        // @TODO: fix - part of models/entry line 155
        Stache::refresh();

        $collection = Collection::make();
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                $files = $this->all($model, $file->getRealPath());

                $collection->merge($files);

                continue;
            }

            if ($file->getExtension() !== 'md') {
                continue;
            }

            // let the model determine how to parse the data
            $data = $model->newInstance()->fromPath($file->getPathname());

            if (! $data) {
                continue;
            }

            $row = array_merge(
                $data,
                [
                    'path' => $file->getRealPath(),
                    'file_path_read_from' => $file->getRealPath(),
                ]
            );

            $collection->push($row);
        }

        return $collection;
    }

    public function filepath(string $directory, Model $model): string
    {
        $basePath = $directory.DIRECTORY_SEPARATOR.$model->{$model->getPathKeyName()}.'.'.$model->fileExtension();
        $itemPath = $model->file_path_read_from ?? $basePath;

        $suffixlessPath = (new RemoveSuffixFromPath)($itemPath);

        if ($basePath !== $suffixlessPath) {
            // If the path should change (e.g. a new slug or date) then
            // reset the counter to 1 so the suffix doesn't get maintained.
            $num = 0;
        } else {
            // Otherwise, start from whatever the suffix was.
            $num = (new GetSuffixFromPath)($itemPath) ?? 0;
        }

        while (true) {
            $ext = '.'.$model->fileExtension();
            $filename = Str::beforeLast($basePath, $ext);
            $suffix = $num ? ".$num" : '';
            $path = "{$filename}{$suffix}{$ext}";

            if (! $contents = File::get($path)) {
                break;
            }

            $itemFromDisk = $model->makeItemFromFile($path, $contents);

            if ($model->id == $itemFromDisk->id()) {
                break;
            }

            $num++;
        }

        return $path;
    }

    //    protected function getModelAttributes(Model $model)
    //    {
    //        return collect($model->getAttributes())
    //            ->map(function ($_, $key) use ($model) {
    //                $value = $model->{$key};
    //
    //                if ($value instanceof BackedEnum) {
    //                    return $value->value;
    //                }
    //
    //                if ($value instanceof Carbon) {
    //                    return $value->toIso8601String();
    //                }
    //
    //                return $value;
    //            })
    //            ->toArray();
    //    }
}
