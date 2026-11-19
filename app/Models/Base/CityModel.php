<?php

namespace App\Models\Base;

use App\Models\City;
use App\Models\Scopes\CityScope;
use App\Support\CityContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * A row that belongs to one city (scope "city" in the unified schema): city_id, legacy_id, soft deletes.
 * Reads are limited to the permitted cities; writing a row of a city outside them is refused.
 */
abstract class CityModel extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CityScope);
        static::saving(function (CityModel $model) {
            if (! app(CityContext::class)->allows((int) $model->city_id)) {
                throw new RuntimeException('This city is not permitted for the current account.');
            }
        });
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function scopeImported($query)
    {
        return $query->whereNotNull($this->qualifyColumn('legacy_id'));
    }
}
