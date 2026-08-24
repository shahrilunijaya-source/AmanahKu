<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * `entry_date` has a `date` cast over a NOT NULL column, so it always reads back as
 * a Carbon instance. Without this, static analysis takes the raw column type and
 * reports ->toDateString() as a call on a string.
 *
 * @property Carbon $entry_date
 */
class TimesheetEntry extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'hours' => 'decimal:2',
            'percentage' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Timesheet, $this> */
    public function timesheet(): BelongsTo
    {
        return $this->belongsTo(Timesheet::class);
    }

    /** @return BelongsTo<TimesheetCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TimesheetCategory::class);
    }

    /**
     * The project this entry is booked to. Named projectRef (not project) on purpose:
     * the legacy free-text `project` string column shadows a `project` relation, so
     * Eloquent would return the column, never the model. Use $entry->projectRef.
     */
    public function projectRef(): BelongsTo
    {
        // Explicit FK: the method name (projectRef) would otherwise infer project_ref_id.
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function subPillar(): BelongsTo
    {
        return $this->belongsTo(SubPillar::class, 'sub_pillar_id');
    }
}
