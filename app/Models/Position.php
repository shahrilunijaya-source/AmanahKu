<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A position (rank band) — one cell in the org-chart grid (department x level)
 * with a MAX salary. Charge-out rates are derived from that band, not from any
 * employee's real salary. See config/manday.php for the constants.
 */
class Position extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'max_salary' => 'decimal:2',
            'is_managerial' => 'boolean',
            'is_director' => 'boolean',
        ];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /** Org department this band sits in (rate-card column). */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** Seniority/grade band (rate-card row). */
    public function staffLevel(): BelongsTo
    {
        return $this->belongsTo(StaffLevel::class);
    }

    /** Daily charge-out rate: (max salary * loading) / working days per month. */
    public function mandayRate(): float
    {
        $days = max((int) config('manday.days_per_month'), 1);

        return round((float) $this->max_salary * (float) config('manday.loading_factor') / $days, 2);
    }

    /** Hourly charge-out rate: manday rate / working hours per day. */
    public function manhourRate(): float
    {
        $hours = max((int) config('manday.hours_per_day'), 1);

        return round($this->mandayRate() / $hours, 2);
    }
}
