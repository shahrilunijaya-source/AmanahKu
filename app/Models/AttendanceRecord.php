<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    /**
     * Rows whose `date` falls on the given day. Sargable range instead of
     * whereDate(): DATE() around the column defeats the (employee_id, date) unique
     * index in MySQL, while sqlite stores date casts with a 00:00:00 time part that
     * a plain where() equality would miss.
     */
    public function scopeOnDate(Builder $query, CarbonInterface|string $day): Builder
    {
        $d = CarbonImmutable::parse($day);

        return $query->where('date', '>=', $d->toDateString())
            ->where('date', '<', $d->addDay()->toDateString());
    }

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'clock_out_latitude' => 'decimal:7',
            'clock_out_longitude' => 'decimal:7',
            'expected_min_hours' => 'decimal:1',
            'in_radius' => 'boolean',
            'out_radius' => 'boolean',
            'worked_minutes' => 'integer',
            'flags' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Auth-gated URL for the stored clock-in selfie, or null when none (AK-SEC-05). */
    protected function photoUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->photo_path
            ? route('attendance.photo', [$this, 'in'])
            : null);
    }

    /** Auth-gated URL for the stored clock-out selfie, or null when none (AK-SEC-05). */
    protected function clockOutPhotoUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->clock_out_photo_path
            ? route('attendance.photo', [$this, 'out'])
            : null);
    }
}
