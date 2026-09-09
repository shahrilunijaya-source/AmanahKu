<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CR-24: a Big Deal Alert, raised by PM-and-above from a project or T.A.A. card.
 * Photos (`big_deal_photos`), team members (`big_deal_members`) and reactions
 * (`big_deal_reactions`) stay plain `DB::table()` access from the controller —
 * same split as `AwardResult`/`award_reactions`/`award_comments` — this model
 * exists for tenant scoping, route-model binding and the 3-day window rule.
 */
class BigDeal extends Model
{
    use BelongsToTenant;

    /** CR-24 rule: stays on every dashboard for 3 days, then only on Wins. */
    private const DASHBOARD_DAYS = 3;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['names_approved' => 'boolean', 'published_at' => 'datetime'];
    }

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'raised_by');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(BigDealPhoto::class);
    }

    /** Team credited on the banner, "iLPF just completed UAT" avatars. */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'big_deal_members');
    }

    /** True while it still belongs on the dashboard band; false once it is Wins-only. */
    public function isActive(): bool
    {
        return CarbonImmutable::now()->lessThan($this->published_at->addDays(self::DASHBOARD_DAYS));
    }
}
