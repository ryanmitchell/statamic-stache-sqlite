<?php

namespace Thoughtco\StatamicStacheSqlite\Terms;

use Statamic\Taxonomies\Term as FileTerm;
use ThoughtCo\StatamicStacheSqlite\Models\Term as TermModel;

class Term extends FileTerm
{
    private ?TermModel $model = null;

    public function model($model = null)
    {
        if (! $model) {
            return $this->model ?? ($this->model = TermModel::find($this->id()));
        }

        $this->model = $model;

        return $this;
    }
}
