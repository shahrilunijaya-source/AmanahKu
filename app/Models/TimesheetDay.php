<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-day submission state for a timesheet week (CR-03). One row per (timesheet_id,
 * entry_date). `status` moves draft -> submitted -> approved, or submitted -> returned
 * -> submitted (resubmitted = true) again.
 *
 * @property Carbon $entry_date
 * @property ?Carbon $submitted_at
 * @property ?Carbon $unlocked_at
 */
class TimesheetDay extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_RETURNED = 'returned';

    protected function casts(): array
    {
        return [
            'entry_date' => 'date:Y-m-d',
            'submitted_at' => 'datetime',
            'unlocked_at' => 'datetime',
            'late' => 'boolean',
            'resubmitted' => 'boolean',
        ];
    }

    /** @return BelongsTo<Timesheet, $this> */
    public function timesheet(): BelongsTo
    {
        return $this->belongsTo(Timesheet::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function unlockedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'unlocked_by_id');
    }

    /** Submitted or approved: read-only for the staff member who owns the sheet. */
    public function isLockedForStaff(): bool
    {
        return in_array($this->status, [self::STATUS_SUBMITTED, self::STATUS_APPROVED], true);
    }
}
