<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One period of a recurring schedule: either the card it produced, or the reason it was
 * skipped. The unique (schedule, period) pair is what makes a double run harmless.
 *
 * @property Carbon $period
 */
class RecurringTaskOccurrence extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['period' => 'date:Y-m-d'];
    }

    /** @return BelongsTo<RecurringTask, $this> */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(RecurringTask::class, 'recurring_task_id');
    }

    /** @return BelongsTo<WorkItem, $this> */
    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class)->withoutGlobalScopes();
    }
}
