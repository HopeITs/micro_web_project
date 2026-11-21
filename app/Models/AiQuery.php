<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @handwritten Table `ai_queries` (explicit, schema v1.5): append-only log of AI search requests, one row per request,
 * read for the per-user daily limit (index account_id, created_at). `prompt` is stored only after local redaction of
 * national numbers and names; `spec` is the QuerySpec the model proposed. No UI yet (smart-search plan, phase 4).
 */
class AiQuery extends Model
{
    public const STATUSES = ['ok', 'rejected', 'error'];

    public const UPDATED_AT = null;

    protected $table = 'ai_queries';

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'spec' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(WebAccount::class, 'account_id');
    }
}
