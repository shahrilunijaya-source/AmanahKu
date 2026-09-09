<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\FridayWin;
use App\Support\AuditContext;
use App\Support\DashboardWidgets;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CR-29 Friday Sign-Off: the `friday` dashboard widget (S04 slot). Anonymity
 * is the whole point of the mood (culture-pack-preamble.md, global-clause.md)
 * — `friday_moods` carries no identity, `friday_receipts` carries no mood, the
 * same split as CR-25's `plot_twist_votes`/`plot_twist_receipts`. "My win" is
 * the opposite: never anonymous, only ever posted under the author's name,
 * and only when they tick "share".
 *
 * No screen, no GET route: everything this controller knows is read back
 * through the dashboard widget only (docs/build/OPEN.md "QA / CR-29").
 */
class FridayController extends Controller
{
    /** @var array<string, string> mood key => tile label, same in every mode */
    private const TILE_LABELS = [
        'productive' => 'Productive',
        'chaotic' => 'Chaotic',
        'peaceful' => 'Suspiciously Peaceful',
        'survived' => 'I Survived',
    ];

    /** @var array<string, string> mood key => tile label, "Keep it plain" */
    private const TILE_LABELS_PLAIN = [
        'productive' => 'Productive',
        'chaotic' => 'Chaotic',
        'peaceful' => 'Peaceful',
        'survived' => 'I Survived',
    ];

    /**
     * The results-bar label for `survived` is the only one that differs from
     * its tile: "Refusing to Elaborate" is cheeky flavour for the bar only
     * (docs/build/sessions/S25/mockup/README.md), gone under "Keep it plain".
     */
    private const RESULT_LABEL_SURVIVED = 'Refusing to Elaborate';

    /** One tap, anonymous mood plus an optional one-line win. */
    public function signOff(Request $request): JsonResponse
    {
        $now = CarbonImmutable::now();
        abort_unless(DashboardWidgets::fridaySignOffOpen($now), 422, 'Sign-off is closed right now.');

        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403);

        $data = $request->validate([
            'mood' => ['required', 'string', Rule::in(array_keys(self::TILE_LABELS))],
            'win' => ['nullable', 'string', 'max:160'],
            'share' => ['nullable', 'boolean'],
        ]);

        $tenantId = app(CurrentTenant::class)->id();
        $weekOf = DashboardWidgets::fridayWeekOf($now);
        $receipt = self::receiptFor($employee, $weekOf);

        abort_if(
            DB::table('friday_receipts')->where('tenant_id', $tenantId)->where('week_of', $weekOf)->where('receipt', $receipt)->exists(),
            422,
            'Already signed off this week.'
        );

        $win = null;

        try {
            DB::transaction(function () use ($tenantId, $weekOf, $data, $receipt, $employee, &$win): void {
                DB::table('friday_moods')->insert([
                    'tenant_id' => $tenantId,
                    'week_of' => $weekOf,
                    'mood' => $data['mood'],
                    'created_at' => now(),
                ]);
                // Unique (tenant_id, week_of, receipt) is the real backstop against a
                // concurrent double sign-off: this insert failing rolls the mood back too.
                DB::table('friday_receipts')->insert(['tenant_id' => $tenantId, 'week_of' => $weekOf, 'receipt' => $receipt]);

                $text = trim((string) ($data['win'] ?? ''));
                if ($text !== '') {
                    $win = FridayWin::create([
                        'tenant_id' => $tenantId,
                        'week_of' => $weekOf,
                        'employee_id' => $employee->id,
                        'text' => $text,
                        'shared' => (bool) ($data['share'] ?? false),
                    ]);
                }
            });
        } catch (QueryException $e) {
            if (str_starts_with((string) $e->getCode(), '23')) {
                abort(422, 'Already signed off this week.');
            }
            throw $e;
        }

        // Anonymous by design: a direct create() bypasses AuditLog::record()'s
        // Auth::id() capture, so the row never carries the signer's identity —
        // "a sign-off happened this week", nothing more (global-clause.md).
        AuditLog::create([
            'tenant_id' => $tenantId,
            'user_id' => null,
            'actor_name' => 'Anonymous',
            'action' => 'friday.signed_off',
            'target' => "friday:{$weekOf}",
            'source' => AuditContext::source(),
        ]);

        // The win is the opposite of anonymous once shared: audited under the
        // author, same as any other named state change (global-clause.md).
        if ($win !== null && $win->shared) {
            AuditLog::record('friday.win_shared', "friday_win:{$win->id}");
        }

        return response()->json(['ok' => true]);
    }

    /**
     * The `friday` widget's payload: whether the viewer has answered, the
     * company mood once it may show, and the wins list.
     *
     * @return array<string, mixed>
     */
    public function widgetData(Request $request, ?Employee $employee): array
    {
        $now = CarbonImmutable::now();
        $weekOf = DashboardWidgets::fridayWeekOf($now);
        $tenantId = app(CurrentTenant::class)->id();

        $voted = $employee !== null && DB::table('friday_receipts')
            ->where('tenant_id', $tenantId)->where('week_of', $weekOf)
            ->where('receipt', self::receiptFor($employee, $weekOf))
            ->exists();

        $counts = DB::table('friday_moods')->where('tenant_id', $tenantId)->where('week_of', $weekOf)
            ->select('mood', DB::raw('count(*) as c'))->groupBy('mood')->pluck('c', 'mood');
        $total = (int) $counts->sum();

        $moodOpensAt = Carbon::parse($weekOf)->setTime(17, 0);
        // Wins are gated on time alone (spec: "for everyone from 17:00"); the mood
        // percentages additionally need the 5-response floor — two separate gates
        // on the same widget, not one.
        $afterFivePm = $now->greaterThanOrEqualTo($moodOpensAt);
        $showMood = $afterFivePm && $total >= 5;

        $byKey = [];
        foreach (array_keys(self::TILE_LABELS) as $key) {
            $byKey[$key] = (int) ($counts[$key] ?? 0);
        }

        // whereDate(), not where(): FridayWin::week_of is Eloquent-cast ('date'), so a
        // saved row is stored with a full datetime format (fromDateTime() ignores the
        // cast type for storage), the same trap PlotTwistController::currentPoll()
        // avoids on `opens_on` with the same fix.
        $wins = FridayWin::with('employee:id,name')->whereDate('week_of', $weekOf)->get();
        $myWin = $employee !== null ? $wins->firstWhere('employee_id', $employee->id) : null;
        $sharedWins = $wins->filter(fn (FridayWin $w) => $w->shared && $w->id !== $myWin?->id)->values();

        return [
            'weekOf' => $weekOf,
            'voted' => $voted,
            'afterFivePm' => $afterFivePm,
            'showMood' => $showMood,
            'total' => $total,
            'percentages' => $showMood ? $this->percentages($byKey, $total) : null,
            'myWin' => $myWin,
            'sharedWins' => $sharedWins,
            'tileLabels' => self::TILE_LABELS,
            'tileLabelsPlain' => self::TILE_LABELS_PLAIN,
            'resultLabelSurvived' => self::RESULT_LABEL_SURVIVED,
        ];
    }

    /**
     * Largest-remainder rounding: floor each share, then hand the leftover
     * percentage points to the moods with the biggest fractional remainder,
     * so the percentages always sum to 100.
     *
     * @param  array<string, int>  $counts  mood key => count
     * @return array<string, int> mood key => integer percentage
     */
    private function percentages(array $counts, int $total): array
    {
        if ($total === 0) {
            return array_fill_keys(array_keys($counts), 0);
        }

        $floors = [];
        $remainders = [];
        foreach ($counts as $key => $count) {
            $share = $count * 100 / $total;
            $floors[$key] = (int) floor($share);
            $remainders[$key] = $share - floor($share);
        }

        $leftover = 100 - array_sum($floors);
        arsort($remainders);
        foreach (array_keys($remainders) as $key) {
            if ($leftover <= 0) {
                break;
            }
            $floors[$key]++;
            $leftover--;
        }

        return $floors;
    }

    private static function receiptFor(Employee $employee, string $weekOf): string
    {
        return hash_hmac('sha256', "{$employee->user_id}:{$weekOf}", config('app.key'));
    }
}
