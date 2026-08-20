<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $date
 * @property list<string>|null $flags
 * @property-read string|null $photo_url
 * @property-read string|null $clock_out_photo_url
 */
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

    /**
     * The still-open punch: clocked in, not yet out. Bounded to yesterday-or-today
     * because a shift that crosses midnight (in 23:00, out 01:30) has its open record
     * dated *yesterday*, so an onDate() lookup finds nothing after 00:00 and tells an
     * employee mid-shift they never clocked in — while a genuinely forgotten clock-out
     * from days ago must not be attributed to whatever they are doing right now.
     */
    public function scopeOpenPunch(Builder $query, CarbonInterface $now): Builder
    {
        return $query->whereNotNull('clock_in')
            ->whereNull('clock_out')
            ->where('date', '>=', CarbonImmutable::parse($now)->subDay()->toDateString())
            ->orderByDesc('date');
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
