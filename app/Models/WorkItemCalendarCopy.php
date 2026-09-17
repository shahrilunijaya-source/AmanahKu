<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tagged person's copy of a card in their Google Calendar. Not tenant-scoped via
 * the trait: it is written from queued jobs that run without a tenant context, and
 * always looked up by work_item_id / employee_id.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $work_item_id
 * @property int $employee_id
 * @property string|null $google_event_id
 * @property string|null $calendar_version
 * @property string|null $sync_error
 */
class WorkItemCalendarCopy extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<WorkItem, $this> */
    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withoutGlobalScopes();
    }
}
