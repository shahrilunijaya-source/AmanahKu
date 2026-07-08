<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum personal access token, extended with the tenant it is bound to.
 *
 * Tokens are tenant-scoped: the API bearer middleware reads {@see $tenant_id} to
 * activate exactly one tenant for the request, so a token minted for tenant A can
 * never read tenant B's data. The Tenant model deliberately does NOT use
 * BelongsToTenant, and this pivot-like token row sits outside the tenant global
 * scope (its tokenable is a global User), so tenant_id is matched explicitly here.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'json',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
