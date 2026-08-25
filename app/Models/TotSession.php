<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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

    /**
     * A third row the editor auto-opens for HR/management only (TotController::PRIVILEGED_ROLES).
     * Kept out of DEFAULT_LINK_LABELS because that constant also drives the presenter-facing
     * blank row set; the presenter never sees this label, in the editor or its untouched-row
     * drop rule. Same text in both languages — it names a document, not UI copy.
     */
    public const MODERATOR_LINK_LABEL = 'Nota Perbincangan';

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

    /**
     * The legacy single presenter. Still the column three years of imported history was
     * written to, so it stays readable; presenters() is what new writes go through.
     *
     * @return BelongsTo<Employee, $this>
     */
    public function presenter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'presenter_employee_id');
    }

    /**
     * The canonical presenter list. One row is a solo slot, more than one is a team — the
     * mode is the count, never a stored flag.
     *
     * @return BelongsToMany<Employee, $this>
     */
    public function presenters(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'tot_session_presenter', 'session_id', 'employee_id');
    }

    /**
     * Every presenter of this slot, newest storage first and falling back to the legacy
     * column so an imported row that never got a pivot entry still resolves.
     *
     * @return Collection<int, Employee>
     */
    public function presenterList(): Collection
    {
        $team = $this->presenters;

        return $team->isNotEmpty()
            ? $team
            : collect(array_filter([$this->presenter]));
    }

    /** True when $employee presents this slot, solo or as part of the team. */
    public function isPresentedBy(?Employee $employee): bool
    {
        return $employee !== null
            && $this->presenterList()->contains(fn (Employee $person) => $person->id === $employee->id);
    }

    /** The two presenter modes. Stored, not derived — see the presenter_mode migration. */
    public const PRESENTER_MODES = ['solo', 'team'];

    /** The label a team slot carries before anybody has been picked for it. */
    public const TEAM_LABEL = 'Team';

    /** True when this slot is presented by a team rather than one person. */
    public function isTeam(): bool
    {
        return $this->presenter_mode === 'team';
    }

    /**
     * The presenters as one string for a label, a subject line or a sentence.
     *
     * A team nobody has been picked for yet still reads as a team, because that is a
     * decision somebody made about the slot and not an empty one. Falls back to the
     * imported free-text name, which is never written any more but still on old rows.
     */
    public function presenterLabel(): ?string
    {
        $names = $this->presenterList()->map(fn (Employee $person) => $person->display_name);

        if ($names->isEmpty()) {
            return $this->presenter_name ?? ($this->isTeam() ? self::TEAM_LABEL : null);
        }

        if ($names->count() === 1) {
            return $names->first();
        }

        return $names->slice(0, -1)->join(', ').' & '.$names->last();
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
