<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Timesheet extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    /**
     * Rows whose week_start falls on the given day. Sargable range instead of
     * whereDate(): DATE() around the column defeats the (employee_id, week_start)
     * unique index in MySQL, while sqlite stores date casts with a 00:00:00 time
     * part that a plain where() equality would miss.
     */
    public function scopeForWeek(Builder $query, CarbonInterface|string $weekStart): Builder
    {
        $day = CarbonImmutable::parse($weekStart);

        return $query->where('week_start', '>=', $day->toDateString())
            ->where('week_start', '<', $day->addDay()->toDateString());
    }

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'total_hours' => 'decimal:2',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TimesheetEntry::class);
    }

    /**
     * Re-sum the timesheet's total hours from its entries and persist it. The
     * entries query inherits the active tenant scope via the BelongsToTenant trait.
     */
    public function recomputeTotal(): void
    {
        $this->update(['total_hours' => (float) $this->entries()->sum('hours')]);
    }
}
