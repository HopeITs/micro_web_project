<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * @handwritten Login account (table web_accounts, unified schema v1.1). Not overwritten by the model generator.
 */
final class WebAccount extends Authenticatable
{
    protected $table = 'web_accounts';

    protected $guarded = ['*'];

    protected $hidden = ['password_hash', 'remember_token'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'disabled_at' => 'immutable_datetime',
            'password_changed_at' => 'immutable_datetime',
            'credential_legacy_user_id' => 'integer',
        ];
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /** "Remember me" (schema v1.4): cycled at logout and whenever the password changes, which signs out every remembered device. */
    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }

    public function credentialLegacyUser(): BelongsTo
    {
        return $this->belongsTo(LegacyStaffUser::class, 'credential_legacy_user_id');
    }

    public function accountCityRoles(): HasMany
    {
        return $this->hasMany(AccountCityRole::class, 'account_id');
    }

    public function accountCityCapabilities(): HasMany
    {
        return $this->hasMany(AccountCityCapability::class, 'account_id');
    }
}
