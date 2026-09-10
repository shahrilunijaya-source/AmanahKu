<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * CR-24: a Big Deal Alert, raised by PM-and-above from a project or T.A.A. card.
 * Photos (`big_deal_photos`), team members (`big_deal_members`) and reactions
 * (`big_deal_reactions`) stay plain `DB::table()` access from the controller —
 * same split as `AwardResult`/`award_reactions`/`award_comments` — this model
 * exists for tenant scoping, route-model binding and the 3-day window rule.
 *
 * @property Carbon|null $published_at
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
    /**
     * Split the story into the one-liner shown under the title and the lines
     * shown in the "What it took" box. The first line is the one-liner only when
     * more lines follow; a single-paragraph story is the story itself.
     *
     * @return array{0: string, 1: list<string>}
     */
    public function storyParts(): array
    {
        $lines = preg_split('/\r?\n/', trim((string) $this->story)) ?: [];
        $oneLiner = trim(array_shift($lines) ?? '');
        $storyLines = array_values(array_filter(array_map('trim', $lines), fn (string $l) => $l !== ''));

        if ($storyLines === [] && $oneLiner !== '') {
            return ['', [$oneLiner]];
        }

        return [$oneLiner, $storyLines];
    }

    public function isActive(): bool
    {
        return CarbonImmutable::now()->lessThan($this->published_at->addDays(self::DASHBOARD_DAYS));
    }
}
