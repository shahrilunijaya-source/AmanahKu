<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * `session_date` is computed from year/month, and `links` has an array cast whose shape
 * the editor enforces, so both read back richer than the raw columns suggest.
 *
 * `presenter` really is nullable: presenter_employee_id is a nullable column, and a slot
 * exists before anybody is assigned to it.
 *
 * @property Carbon $session_date
 * @property array<int, array{label: string, url: string}>|null $links
 * @property-read Employee|null $presenter
 */
class TotSession extends Model
{
    use BelongsToTenant;

    /**
     * planned  - slot exists, may or may not have a PIC, not yet held
     * confirmed- PIC and title both set, expected to run
     * done     - the session happened (this is what credits the PIC's month)
     * skipped  - no session that month
     * not_tot  - a non-TOT calendar entry kept for fidelity, never credits anybody
     */
    public const STATUSES = ['planned', 'confirmed', 'done', 'skipped', 'not_tot'];

    /** The only reactions the UI offers. Anything else is rejected with a 422. */
    public const EMOJI = ['👍', '👏', '🔥', '💡', '🤔', '❤️'];

    /**
     * The usual hour. starts_at/ends_at are nullable so a slot nobody moved keeps these
     * without anybody retyping them; a value on the row is a month somebody moved.
     */
    public const DEFAULT_START = '10:30';

    public const DEFAULT_END = '11:00';

    /**
     * The two rows the links editor opens with, so a presenter only pastes URLs. They are
     * labels, not links: a row still carrying one of these with no URL is an untouched row
     * and is dropped on save, while a label somebody typed themselves still demands a URL.
     */
    public const DEFAULT_LINK_LABELS = ['Google Meet', 'Slide'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'links' => 'array',
            'held_on' => 'date',
        ];
    }

    /**
     * The session date: the first Saturday of the slot's month. It is computed rather than
     * stored, so a slot can exist before anybody decides anything about it.
     */
    public static function firstSaturday(int $year, int $month): Carbon
    {
        return Carbon::parse(sprintf('first saturday of %04d-%02d', $year, $month));
    }

    /** Computed, never stored, so a slot can exist before anybody decides anything about it. */
    protected function sessionDate(): Attribute
    {
        return Attribute::get(fn () => self::firstSaturday($this->year, $this->month));
    }

    /** Wall-clock start of this slot: the session date at starts_at, or at the usual hour. */
    public function startTime(): Carbon
    {
        return $this->timeOnSessionDate($this->starts_at ?: self::DEFAULT_START);
    }

    public function endTime(): Carbon
    {
        return $this->timeOnSessionDate($this->ends_at ?: self::DEFAULT_END);
    }

    /**
     * Put a stored time onto the computed session date. The column comes back as H:i:s from
     * MySQL and as whatever was written on sqlite, so only the first two parts are read.
     */
    private function timeOnSessionDate(string $time): Carbon
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');

        return $this->session_date->copy()->setTime((int) $hour, (int) $minute);
    }

    /** @return BelongsTo<Employee, $this> */
    public function presenter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'presenter_employee_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TotComment::class, 'session_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(TotReaction::class, 'session_id');
    }

    public function participations(): HasMany
    {
        return $this->hasMany(TotParticipation::class, 'session_id');
    }
}
