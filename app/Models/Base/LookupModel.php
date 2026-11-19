<?php

namespace App\Models\Base;

use Illuminate\Database\Eloquent\Model;

/**
 * A list value shared by all cities (scope "lookup"): name_ar, name_en, sort_order, is_active.
 * The legacy id of each city resolves through lookup_legacy_map.
 */
abstract class LookupModel extends Model
{
    protected $guarded = ['id'];

    public function scopeActive($query)
    {
        return $query->where($this->qualifyColumn('is_active'), true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy($this->qualifyColumn('sort_order'))->orderBy($this->qualifyColumn('name_ar'));
    }

    /** The name in the current language, falling back to Arabic. */
    public function label(): string
    {
        return app()->getLocale() === 'en' && filled($this->name_en) ? $this->name_en : (string) $this->name_ar;
    }
}
