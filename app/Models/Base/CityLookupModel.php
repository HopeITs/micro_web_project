<?php

namespace App\Models\Base;

use App\Models\City;
use App\Models\Scopes\CityScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A list value that belongs to one city (scope "city_lookup"): streets, priests, checklist members. */
abstract class CityLookupModel extends LookupModel
{
    protected static function booted(): void
    {
        static::addGlobalScope(new CityScope);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
