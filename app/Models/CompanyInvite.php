<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CompanyInviteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-use, seven-day signup link a super-admin hands to the person in charge of a
 * new company. Platform-level (not tenant-scoped): it exists before the tenant does.
 * The token is the only credential — 40 random characters, never listed publicly.
 */
class CompanyInvite extends Model
{
    /** @use HasFactory<CompanyInviteFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /** @param  Builder<CompanyInvite>  $query */
    public function scopeForToken(Builder $query, string $token): Builder
    {
        return $query->where('token', $token);
    }

    /** Unused and not yet expired. */
    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    /** 'pending' | 'used' | 'expired' — what the super-admin list shows. */
    public function status(): string
    {
        if ($this->used_at !== null) {
            return 'used';
        }

        return $this->expires_at->isFuture() ? 'pending' : 'expired';
    }

    /** The public signup URL for this invite. */
    public function url(): string
    {
        return route('register', ['invite' => $this->token]);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CompanyCategory::class, 'company_category_id');
    }

    public function usedByTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'used_by_tenant_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
