<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function timesheet(): BelongsTo
    {
        return $this->belongsTo(Timesheet::class);
    }

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
        return $this->belongsTo(ProjectSubPillar::class, 'sub_pillar_id');
    }
}
