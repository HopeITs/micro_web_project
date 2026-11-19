<?php

namespace App\Models\Scopes;

use App\Support\CityContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Limits city-owned rows to the cities the current request may read (see CityContext). */
final class CityScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(CityContext::class);
        if ($context->active()) {
            $builder->whereIn($model->qualifyColumn('city_id'), $context->cityIds());
        }
    }
}
