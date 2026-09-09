<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\AwardResult;
use App\Models\Employee;
use App\Models\Reaction;
use App\Models\WorkItem;
use App\Support\AutoDone;
use App\Support\AwardBoard;
use App\Support\AwardCatalog;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CR-14b: the Awards screen (The Playground), peer nominations, the two director/PM
 * manual picks, reactions/comments on a result, and the Global Clause item 3 override.
 * Publishing itself (tallying nominations, resolving winners) stays in
 * AwardsPublish::publishTenant() (S17's frozen scope, extended for CR-14b) — this
 * controller only ever writes a candidate row (`award_nominations`) or a manual/adjusted
 * `award_results` row directly; it never re-derives a winner.
 */
class AwardController extends Controller
{
    /** The two non-nominated manual awards this controller's select() writes. */
    private const SELECTED_KEYS = ['new_but_dangerous', 'chosen_one'];

    /** @return array<string, mixed> */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $pastMonths = AwardBoard::publishedMonths();
        $requested = $request->query('month');
        $month = (is_string($requested) && in_array($requested, $pastMonths, true))
            ? $requested
            : ($pastMonths[0] ?? null);

        return [
            'month' => $month,
            'pastMonths' => $pastMonths,
            'slides' => $month !== null ? AwardBoard::slidesForMonth($month) : collect(),
            'nominatedKeys' => AwardCatalog::NOMINATED_KEYS,
            'nominationWindowOpen' => self::inNominationWindow(Carbon::now()),
            'canSelectNewButDangerous' => $this->hasTenantRole($request, ['manager', 'management', 'director']),
            'canSelectChosenOne' => $this->hasTenantRole($request, ['director']),
            // QA S18 F3: the Global Clause override needs a form on the page, not only a route.
            'canAdjust' => $this->hasTenantRole($request, ['director']),
            'colleagues' => Employee::active()->where('id', '!=', $employee?->id)->orderBy('name')->get(['id', 'name', 'nickname']),
            'activeReactionKeys' => Reaction::activeKeys(),
        ];
    }

    /** CR-14: peer nomination for main_character/office_yoda, last 7 days of the month only. */
    public function nominate(Request $request): JsonResponse|RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403);

        $data = $request->validate([
            'award_key' => ['required', 'string', 'in:'.implode(',', AwardCatalog::NOMINATED_KEYS)],
            'employee_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        if ((int) $data['employee_id'] === $employee->id) {
            throw ValidationException::withMessages(['employee_id' => 'You cannot nominate yourself.']);
        }

        $today = Carbon::now();
        if (! self::inNominationWindow($today)) {
            throw ValidationException::withMessages(['award_key' => 'Nominations are only open in the last week of the month.']);
        }

        $nominee = Employee::findOrFail($data['employee_id']);
        $month = $today->copy()->startOfMonth();

        try {
            DB::table('award_nominations')->insert([
                'tenant_id' => app(CurrentTenant::class)->id(),
                'month' => $month->toDateString(),
                'award_key' => $data['award_key'],
                'nominator_employee_id' => $employee->id,
                'nominee_employee_id' => $nominee->id,
                'reason' => $data['reason'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            if (! str_starts_with((string) $e->getCode(), '23')) {
                throw $e;
            }

            throw ValidationException::withMessages(['award_key' => 'You already nominated for this award this month.']);
        }

        AuditLog::record('award.nominated', "{$data['award_key']}:{$nominee->id}");
        $this->closeCard($employee->id, $month->format('Y-m').'-nominate', 'Nomination submitted');

        // QA S18 F2: the screen's forms are plain posts, so a browser lands back on the
        // tab with a flash; only fetch callers get the JSON.
        return $request->expectsJson()
            ? response()->json(['ok' => true])
            : back()->with('ok', 'Nomination sent.')->with('tab', 'nominate');
    }

    /**
     * CR-14: the two director/PM manual picks (main_character/office_yoda take peer
     * nominations instead, via nominate() above). Writes straight into award_results,
     * replacing an earlier pick of the same award and month by anyone (rule: one winner
     * lives per award per month until the Director overrides it via adjust()).
     */
    public function select(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'award_key' => ['required', 'string', 'in:'.implode(',', self::SELECTED_KEYS)],
            'employee_id' => ['required', 'integer'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403);

        $roles = $data['award_key'] === 'chosen_one' ? ['director'] : ['manager', 'management', 'director'];
        $this->authorizeTenantRole($request, $roles);

        $reason = trim((string) ($data['reason'] ?? ''));
        if ($data['award_key'] === 'chosen_one' && $reason === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required for the Chosen One award.']);
        }

        $pick = Employee::findOrFail($data['employee_id']);
        $month = $this->selectionMonth();

        if ($data['award_key'] === 'new_but_dangerous') {
            $cutoff = $month->copy()->subMonths(6);
            if ($pick->joined_at === null || $pick->joined_at->lt($cutoff) || $pick->joined_at->gt($month->copy()->endOfMonth())) {
                throw ValidationException::withMessages(['employee_id' => 'Must have joined within the 6 months before this award month.']);
            }
        }

        $tenantId = app(CurrentTenant::class)->id();
        DB::table('award_results')->where('tenant_id', $tenantId)
            ->whereDate('month', $month->toDateString())->where('award_key', $data['award_key'])->delete();

        $now = now();
        DB::table('award_results')->insert([
            'tenant_id' => $tenantId,
            'month' => $month->toDateString(),
            'award_key' => $data['award_key'],
            'employee_id' => $pick->id,
            'value' => 1.0,
            'label' => AwardCatalog::copy($data['award_key'])['en']['name'],
            'source' => 'manual',
            'reason' => $reason !== '' ? $reason : null,
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        AuditLog::record('award.selected', "{$data['award_key']}:{$pick->id}");
        $this->closeCard($employee->id, $month->format('Y-m').'-select', 'Pick recorded');

        return $request->expectsJson()
            ? response()->json(['ok' => true])
            : back()->with('ok', 'Pick saved.')->with('tab', 'select');
    }

    /** CR-30 reaction, one per person per result, toggle semantics (same as TotController::react). */
    public function react(Request $request, AwardResult $result): JsonResponse
    {
        $this->assertSameTenant($result);

        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403);

        $data = $request->validate([
            'reaction' => ['required', 'string', 'in:'.implode(',', Reaction::activeKeys())],
        ]);

        $had = DB::table('award_reactions')->where('award_result_id', $result->id)
            ->where('employee_id', $employee->id)->pluck('emoji');

        DB::table('award_reactions')->where('award_result_id', $result->id)->where('employee_id', $employee->id)->delete();

        if (! $had->contains($data['reaction'])) {
            try {
                DB::table('award_reactions')->insert([
                    'tenant_id' => app(CurrentTenant::class)->id(),
                    'award_result_id' => $result->id,
                    'employee_id' => $employee->id,
                    'emoji' => $data['reaction'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (QueryException $e) {
                if (! str_starts_with((string) $e->getCode(), '23')) {
                    throw $e;
                }
            }
        }

        return response()->json(['ok' => true, 'html' => $this->engagementPartial($result)]);
    }

    /** A comment on a result. Global Clause: every state change here writes an audit row. */
    public function comment(Request $request, AwardResult $result): JsonResponse
    {
        $this->assertSameTenant($result);

        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        DB::table('award_comments')->insert([
            'tenant_id' => app(CurrentTenant::class)->id(),
            'award_result_id' => $result->id,
            'employee_id' => $employee->id,
            'body' => $data['body'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AuditLog::record('award.commented', (string) $result->id);

        return response()->json(['ok' => true, 'html' => $this->engagementPartial($result)]);
    }

    /**
     * Global Clause item 3: the Director overrides a published result. Updates the row
     * in place (never a second row for the same award/month — the unique index on
     * award_results already guarantees that) and writes the field-level audit entry
     * every Global Clause state change requires.
     */
    public function adjust(Request $request, AwardResult $result): JsonResponse|RedirectResponse
    {
        $this->assertSameTenant($result);
        $this->authorizeTenantRole($request, ['director']);

        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $newWinner = Employee::findOrFail($data['employee_id']);
        $oldEmployeeId = $result->employee_id;

        $result->employee_id = $newWinner->id;
        $result->source = 'adjusted';
        $result->reason = $data['reason'];
        $result->save();

        AuditLog::change($result, 'employee_id', $oldEmployeeId, $newWinner->id, $data['reason']);

        return $request->expectsJson()
            ? response()->json(['ok' => true])
            : back()->with('ok', 'Result adjusted.');
    }

    /** The engagement region (reactions + comments) for one result, re-rendered for a fetch-and-swap response. */
    private function engagementPartial(AwardResult $result): string
    {
        $reactionCount = DB::table('award_reactions')->where('award_result_id', $result->id)->count();
        $comments = DB::table('award_comments')->join('employees', 'employees.id', '=', 'award_comments.employee_id')
            ->where('award_result_id', $result->id)->orderBy('award_comments.created_at')
            ->get(['body', 'employees.name as name']);

        return view('partials.awards.engagement', [
            'resultId' => $result->id,
            'reactionCount' => $reactionCount,
            'comments' => $comments,
            'activeKeys' => Reaction::activeKeys(),
        ])->render();
    }

    /**
     * Marks the caller's own Nominate/Select card done — never anyone else's (route-model
     * binding is not tenant-safe by itself, but employee_id here is always the acting
     * employee's own id). CR-19: closed one at a time through App\Support\AutoDone (never
     * a bulk query-builder update) so the model's own save fires AuditsChanges and leaves
     * the Auto marker + activity-line trail every automatic close must carry.
     */
    private function closeCard(int $employeeId, string $sourceRef, string $reason): void
    {
        WorkItem::where('employee_id', $employeeId)->where('source', 'awards')->where('source_ref', $sourceRef)
            ->where('status', '!=', 'done')
            ->get()
            ->each(fn (WorkItem $card) => AutoDone::done($card, $reason));
    }

    /** Nominations are only accepted in the last 7 calendar days of the month (24 to 30 Sep, for a 30-day month). */
    private static function inNominationWindow(Carbon $today): bool
    {
        $lastDay = $today->copy()->endOfMonth()->day;

        return $today->day >= $lastDay - 6;
    }

    /** The month currently open for a manual pick: last month once its awards are published, else this month. */
    /**
     * QA S18 F5: the month a pick is for. The Select window opens with the Select task
     * on the month's last Monday and runs to the task's due date on the next month's
     * first working day, so from the last Monday onward a pick is for the current
     * month and before that it is for the previous one. Keying on "previous month
     * already published" sent a 29 Sep pick to August.
     */
    private function selectionMonth(): Carbon
    {
        $today = Carbon::now()->startOfDay();
        $lastMonday = $today->copy()->endOfMonth()->startOfDay();
        while (! $lastMonday->isMonday()) {
            $lastMonday->subDay();
        }

        return $today->gte($lastMonday)
            ? $today->copy()->startOfMonth()
            : $today->copy()->subMonthNoOverflow()->startOfMonth();
    }

    private function assertSameTenant(AwardResult $result): void
    {
        abort_unless($result->tenant_id === app(CurrentTenant::class)->id(), 404);
    }
}
