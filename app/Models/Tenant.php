<?php

namespace App\Models;

use App\Services\FeatureManager;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Tenant extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'subscription_start' => 'date',
            'subscription_end' => 'date',
            'onboarding_enforced' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Live "N branches · M employees" summary for the workspace picker.
     * Counts active (non-archived) staff only, matching the directory headcount —
     * never the legacy hardcoded "186" seed string. Uses eager-loaded counts when
     * present (see AppController::tenantSelect), else falls back to a query so it
     * is always correct.
     */
    protected function metaLine(): Attribute
    {
        return Attribute::make(get: function (): string {
            $branches = $this->branches_count ?? $this->branches()->count();
            $employees = $this->active_employees_count ?? $this->employees()->active()->count();

            return sprintf(
                '%d %s · %d %s',
                $branches,
                Str::plural('branch', $branches),
                $employees,
                Str::plural('employee', $employees),
            );
        });
    }

    public function companyCategory(): BelongsTo
    {
        return $this->belongsTo(CompanyCategory::class);
    }

    /** Category stage level (1/2/3), or null when no category is assigned. */
    public function categoryLevel(): ?int
    {
        return $this->companyCategory?->level;
    }

    /** Whether the company is active (not suspended). Defaults to active. */
    public function isActive(): bool
    {
        return ($this->status ?? 'active') === 'active';
    }

    /** Whether the subscription window has lapsed (end date in the past). */
    public function subscriptionExpired(): bool
    {
        return $this->subscription_end !== null && $this->subscription_end->isPast();
    }

    /** Resolve a feature value for this tenant (platform default / lock / override aware). */
    public function feature(string $key): mixed
    {
        return app(FeatureManager::class)->value($this, $key);
    }

    /** Boolean view of a feature for this tenant. */
    public function featureEnabled(string $key): bool
    {
        return app(FeatureManager::class)->enabled($this, $key);
    }

    public function features(): HasMany
    {
        return $this->hasMany(TenantFeature::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role', 'data_scope')
            ->withTimestamps();
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }
}
