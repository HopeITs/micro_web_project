<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @handwritten Table `saved_views` (explicit, schema v1.5): a user's named filter set for one dataset.
 * `spec` holds a QuerySpec (field keys, operators, lookup ids, numbers, dates) and never personal data or the
 * free-text search (its `sort` holds the header sort); `columns` is the ordered list of shown column keys (null = the
 * dataset's defaults; smart-search phase 2, see HasDatasetColumns). No city: whoever applies a view only ever sees their own permitted
 * cities, so a shared view shares the filter, not the data. One default per account, dataset and kind (kept by the app).
 */
class SavedView extends Model
{
    public const KINDS = ['view', 'report'];

    protected $table = 'saved_views';

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'spec' => 'array',
            'columns' => 'array',
            'is_shared' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(WebAccount::class, 'account_id');
    }

    /** The account's own views plus those other accounts shared, for one dataset and kind. */
    public function scopeVisibleTo(Builder $query, int $accountId, string $dataset, string $kind = 'view'): Builder
    {
        return $query->where('dataset', $dataset)->where('kind', $kind)
            ->where(fn (Builder $q) => $q->where('account_id', $accountId)->orWhere('is_shared', true));
    }
}
