<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * CR-28: a Victory Bell ring on a Milestone card's Done transition. Shown on
 * every dashboard in the tenant for 24 hours, then only on the Wins page.
 * Reactions (`victory_bell_reactions`) stay plain `DB::table()` access from
 * the controller — same split as `BigDeal`/`big_deal_reactions`.
 *
 * @property Carbon|null $rung_at
 */
class VictoryBell extends Model
{
    use BelongsToTenant;

    private const DASHBOARD_HOURS = 24;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['rung_at' => 'datetime'];
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function rungBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'rung_by');
    }

    /** True while it still belongs on the dashboard band; false once it is Wins-only. */
    public function isActive(): bool
    {
        return CarbonImmutable::now()->lessThan($this->rung_at->addHours(self::DASHBOARD_HOURS));
    }
}
