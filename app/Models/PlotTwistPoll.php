<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * CR-25 This Week's Plot Twist. The poll row itself carries no vote data —
 * counts live in `plot_twist_votes`, identity never touches this model or
 * anything it can be joined to.
 *
 * @property int $id
 * @property string $question
 * @property string $kind
 * @property int|null $named_employee_id
 * @property string $status
 * @property Carbon $opens_on
 * @property Carbon $reveals_at
 * @property Carbon|null $idea_fed_at
 */
class PlotTwistPoll extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['opens_on' => 'date', 'reveals_at' => 'datetime', 'idea_fed_at' => 'datetime'];
    }

    /** @return HasMany<PlotTwistOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(PlotTwistOption::class, 'poll_id')->orderBy('sort_order')->orderBy('id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function namedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'named_employee_id');
    }

    /** Open, and inside the voting window (opens_on's start of day through reveals_at, inclusive). */
    public function isVotable(): bool
    {
        return $this->status === 'open'
            && now()->gte($this->opens_on->copy()->startOfDay())
            && now()->lte($this->reveals_at);
    }

    public function isRevealed(): bool
    {
        return now()->gte($this->reveals_at);
    }

    /** The named person may still withdraw a who-question before it opens. */
    public function optOutAllowed(): bool
    {
        return $this->kind === 'who' && now()->lt($this->opens_on->copy()->startOfDay());
    }
}
