<?php

namespace Thoughtco\StatamicStacheSqlite\Terms;

use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Statamic\Contracts\Taxonomies\Term as TermContract;
use Statamic\Facades\Blink;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Query\EloquentQueryBuilder;
use Statamic\Taxonomies\TermCollection;
use Thoughtco\StatamicStacheSqlite\Models\Entry as EntryModel;
use Thoughtco\StatamicStacheSqlite\Models\Term as TermModel;

class TermQueryBuilder extends EloquentQueryBuilder
{
    protected $collections = [];

    protected $site = null;

    protected $taxonomies = [];

    protected function transform($items, $columns = [])
    {
        $site = $this->site;
        if (! $site) {
            $site = Site::default()->handle();
        }

        return TermCollection::make($items)->map(function ($model) use ($site) {
            return Blink::once("term-{$model->id}", fn () => $model->makeContract())->in($site);
        });
    }

    protected function column($column)
    {
        if (! is_string($column)) {
            return $column;
        }

        $columns = Blink::once('term-columns', fn () => (new TermModel)->resolveConnection()->getSchemaBuilder()->getColumnListing((new TermModel)->getTable()));

        if (! in_array($column, $columns)) {
            if (! Str::startsWith($column, 'data->')) {
                $column = 'data->'.$column;
            }
        }

        return $column;
    }

    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        if ($column === 'site') {
            $this->site = $operator;

            return $this;
        }

        if (func_num_args() === 2) {
            [$value, $operator] = [$operator, '='];
        }

        if (in_array($column, ['taxonomy', 'taxonomies'])) {
            if (! $value) {
                return $this;
            }

            if (! is_array($value)) {
                $value = [$value];
            }

            $this->taxonomies = array_merge($this->taxonomies, $value);

            return $this;
        }

        if (in_array($column, ['collection', 'collections'])) {
            if (! $value) {
                return $this;
            }

            if (! is_array($value)) {
                $value = [$value];
            }

            $this->collections = array_merge($this->collections, $value);

            return $this;
        }

        if (in_array($column, ['id', 'slug'])) {
            $column = 'slug';

            if (str_contains($value, '::')) {
                $taxonomy = Str::before($value.'', '::');

                if ($taxonomy) {
                    $this->taxonomies[] = $taxonomy;
                }

                $value = Str::after($value, '::');
            }
        }

        parent::where($column, $operator, $value, $boolean);

        return $this;
    }

    public function whereIn($column, $values, $boolean = 'and')
    {
        if (in_array($column, ['taxonomy', 'taxonomies'])) {
            if (! $values) {
                return $this;
            }

            $this->taxonomies = array_merge($this->taxonomies, collect($values)->all());

            return $this;
        }

        if (in_array($column, ['collection', 'collections'])) {
            if (! $values) {
                return $this;
            }

            $this->collections = array_merge($this->collections, collect($values)->all());

            return $this;
        }

        if (in_array($column, ['id', 'slug'])) {
            $column = 'slug';
            $values = collect($values)
                ->map(function ($value) {
                    $taxonomy = Str::before($value.'', '::');
                    if ($taxonomy) {
                        $this->taxonomies[] = $taxonomy;
                    }

                    return Str::after($value, '::');
                })
                ->all();
        }

        parent::whereIn($column, $values, $boolean);

        return $this;
    }

    public function find($id, $columns = ['*'])
    {
        $model = parent::find($id, $columns);

        if ($model) {
            if (! $site = $this->site) {
                $site = Site::default()->handle();
            }

            dd($model); // this isnt a model surely?

            return app(TermContract::class)::fromModel($model)
                ->in($site)
                ->selectedQueryColumns($columns);
        }
    }

    public function get($columns = ['*'])
    {
        $this->applyCollectionAndTaxonomyWheres();

        $items = parent::get($columns);

        // If a single collection has been queried, we'll supply it to the terms so
        // things like URLs will be scoped to the collection. We can't do it when
        // multiple collections are queried because it would be ambiguous.
        if ($this->collections && count($this->collections) == 1) {
            $items->each->collection(Collection::findByHandle($this->collections[0]));
        }

        $items = Term::applySubstitutions($items);

        return $items->map(function ($term) {
            if ($this->site) {
                return $term->in($this->site);
            }

            return $term->inDefaultLocale();
        });
    }

    public function pluck($column, $key = null)
    {
        $this->applyCollectionAndTaxonomyWheres();

        return parent::pluck($column, $key);
    }

    public function count()
    {
        $this->applyCollectionAndTaxonomyWheres();

        return parent::count();
    }

    public function paginate($perPage = null, $columns = [], $pageName = 'page', $page = null)
    {
        $this->applyCollectionAndTaxonomyWheres();

        return parent::paginate($perPage, $columns, $pageName, $page);
    }

    private function applyCollectionAndTaxonomyWheres()
    {
        if (! empty($this->collections)) {
            $this->builder->where(function ($query) {
                $taxonomies = empty($this->taxonomies)
                    ? Taxonomy::handles()->all()
                    : $this->taxonomies;

                collect($taxonomies)->each(function ($taxonomy) use ($query) {
                    $collectionTaxonomyHash = md5(collect($this->collections)->merge([$taxonomy])->sort()->join('-'));

                    $terms = Blink::once("collection-taxonomy-hash-{$collectionTaxonomyHash}", function () use ($taxonomy) {
                        if (! $taxonomy = Taxonomy::find($taxonomy)) {
                            return [];
                        }

                        // workaround to handle potential n+1 queries in the database
                        // @TODO: lets think of a better way of handling relations in the database, maybe a relations pivot table?
                        $entriesTable = (new EntryModel)->getTable();
                        $termsTable = (new TermModel)->getTable();

                        return TermModel::where('taxonomy', $taxonomy)
                            ->whereExists(function ($query) use ($entriesTable, $taxonomy, $termsTable) {
                                $query->select(DB::raw(1))
                                    ->from($entriesTable)
                                    ->whereIn('collection', $this->collections)
                                    ->whereJsonContains(Entry::query()->column($taxonomy->handle()), new Expression($query->getGrammar()->wrap("{$termsTable}.slug")));
                            })
                            ->pluck('slug');
                    });

                    if ($terms->isNotEmpty()) {
                        $query->orWhere(function ($query) use ($terms, $taxonomy) {
                            $query->where('taxonomy', $taxonomy)
                                ->whereIn('slug', $terms->all());
                        });
                    }
                });
            });
        }

        if (! empty($this->taxonomies)) {
            $queryTaxonomies = collect($this->taxonomies)
                ->filter()
                ->unique();

            if ($queryTaxonomies->count() > 0) {
                $this->builder->whereIn('taxonomy', $queryTaxonomies->all());
            }
        }
    }
}
