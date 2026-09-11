<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One ordered slot inside a monthly TOT session (CR-09). A session used to be one title
 * with one presenter; it may now hold several — Pembentangan, Demonstrasi or Sambungan —
 * each with its own presenter team, format, status and discussion thread.
 */
class TotSlot extends Model
{
    use BelongsToTenant;

    public const KINDS = ['pembentangan', 'demonstrasi', 'sambungan'];

    public const FORMATS = ['slide', 'demo'];

    public const STATUSES = ['rasmi', 'ujian'];

    public const PRESENTER_MODES = ['solo', 'team'];

    protected $guarded = [];

    /** @return BelongsTo<TotSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TotSession::class, 'session_id');
    }

    /** @return BelongsToMany<Employee, $this> */
    public function presenters(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'tot_slot_presenter', 'slot_id', 'employee_id')
            ->withPivot('support');
    }

    /** @return HasMany<TotComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(TotComment::class, 'slot_id');
    }

    /** @return HasMany<TotReaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(TotReaction::class, 'slot_id');
    }

    /** @return HasMany<TotAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(TotAction::class, 'slot_id');
    }

    /** True when $employee presents this slot, solo, team lead, or sokongan. */
    public function isPresentedBy(?Employee $employee): bool
    {
        return $employee !== null
            && $this->presenters->contains(fn (Employee $person) => $person->id === $employee->id);
    }

    /** True when this slot is presented by more than one person. */
    public function isTeam(): bool
    {
        return $this->presenter_mode === 'team';
    }

    /**
     * The presenters as one string for a label, main presenter(s) first then sokongan.
     */
    public function presenterLabel(): ?string
    {
        /** @var Collection<int, Employee> $team */
        $team = $this->presenters;
        $lead = $team->filter(fn (Employee $p) => ! ($p->pivot->support ?? false));
        $support = $team->filter(fn (Employee $p) => $p->pivot->support ?? false);

        $names = $lead->map(fn (Employee $p) => $p->display_name)
            ->concat($support->map(fn (Employee $p) => $p->display_name.' (sokongan)'));

        if ($names->isEmpty()) {
            return $this->isTeam() ? TotSession::TEAM_LABEL : null;
        }

        return $names->join(', ');
    }

    /**
     * CR-09 scope item 6, extracted out of the `tot_slots` creation migration so it is
     * unit-testable directly (tests/Feature/TotSessionSlotsTest.php): every session that
     * already has a title and no slot yet gets one slot (position 1, kind pembentangan)
     * carrying that title, description, presenter_mode and presenter list, so a legacy
     * single-topic session reads as a one-slot session instead of silently losing its list.
     */
    public static function backfillLegacySessions(): void
    {
        DB::table('tot_sessions')
            ->whereNotNull('title')
            ->whereNotIn('id', function ($query) {
                $query->select('session_id')->from('tot_slots');
            })
            ->orderBy('id')
            ->chunkById(200, function ($sessions) {
                foreach ($sessions as $session) {
                    $slotId = DB::table('tot_slots')->insertGetId([
                        'tenant_id' => $session->tenant_id,
                        'session_id' => $session->id,
                        'position' => 1,
                        'title' => $session->title,
                        'kind' => 'pembentangan',
                        'format' => null,
                        'status' => null,
                        'presenter_mode' => $session->presenter_mode ?? 'solo',
                        'summary' => $session->description,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $presenterIds = DB::table('tot_session_presenter')
                        ->where('session_id', $session->id)
                        ->pluck('employee_id');

                    if ($presenterIds->isEmpty() && $session->presenter_employee_id !== null) {
                        $presenterIds = collect([$session->presenter_employee_id]);
                    }

                    if ($presenterIds->isNotEmpty()) {
                        DB::table('tot_slot_presenter')->insertOrIgnore(
                            $presenterIds->map(fn ($employeeId) => [
                                'slot_id' => $slotId,
                                'employee_id' => $employeeId,
                                'support' => false,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ])->all()
                        );
                    }
                }
            });
    }
}
