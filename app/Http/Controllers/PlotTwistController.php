<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PlotTwistOption;
use App\Models\PlotTwistPoll;
use App\Models\PlotTwistQuestion;
use App\Models\WorkItem;
use App\Models\WorkItemComment;
use App\Support\AuditContext;
use App\Support\Permissions;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * CR-25 This Week's Plot Twist: one anonymous poll a week. HR/director publish
 * it, anyone votes once, results reveal on the dashboard Notice board and this
 * screen from Friday 15:00. Anonymity is the whole point (culture-pack-preamble.md,
 * global-clause.md): `plot_twist_votes` carries no identity, `plot_twist_receipts`
 * carries no choice — see the migration and docs/build/OPEN.md ("QA / CR-25").
 *
 * Route-model binding is not tenant-scoped (SubstituteBindings runs before
 * ResolveTenant — same trap as BigDeal/VictoryBell), so every action re-checks
 * tenant_id before touching a bound poll.
 */
class PlotTwistController extends Controller
{
    private const PUBLISH_ROLES = ['hr', 'director'];

    /** HR/director only: publish this week's (or a future week's) poll. */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PUBLISH_ROLES);

        // The publish form always renders 6 option inputs (2 required, 4 optional);
        // drop the blanks so the plain HTML form doesn't fail "options.* required"
        // just because a viewer left the last few fields empty.
        $request->merge([
            'options' => array_values(array_filter(
                (array) $request->input('options', []),
                fn (mixed $v): bool => trim((string) $v) !== ''
            )),
        ]);

        $data = $request->validate([
            'question' => ['required', 'string', 'max:255'],
            'kind' => ['required', 'string', Rule::in(['fun', 'who', 'social'])],
            'options' => ['required', 'array', 'min:2', 'max:6'],
            'options.*' => ['required', 'string', 'max:120'],
            'opens_on' => ['required', 'date_format:Y-m-d', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! Carbon::parse($value)->isMonday()) {
                    $fail('The poll must open on a Monday.');
                }
            }],
            'named_employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('tenant_id', app(CurrentTenant::class)->id())],
        ]);

        if ($data['kind'] === 'who') {
            if (empty($data['named_employee_id'])) {
                throw ValidationException::withMessages(['named_employee_id' => 'A who-question needs a named person.']);
            }

            $isApprovedTemplate = PlotTwistQuestion::where('kind', 'who')
                ->where('template', true)->where('approved', true)
                ->where('text', $data['question'])->exists();

            if (! $isApprovedTemplate) {
                throw ValidationException::withMessages(['question' => 'A who-question must come from the approved template bank.']);
            }
        }

        $employee = $request->attributes->get('employee');
        $opensOn = Carbon::parse($data['opens_on'])->startOfDay();

        $poll = PlotTwistPoll::create([
            'question' => $data['question'],
            'kind' => $data['kind'],
            'named_employee_id' => $data['named_employee_id'] ?? null,
            'status' => 'open',
            'opens_on' => $opensOn,
            'reveals_at' => $opensOn->copy()->addDays(4)->setTime(15, 0),
            'created_by' => $employee?->id,
        ]);

        foreach (array_values($data['options']) as $i => $label) {
            PlotTwistOption::create(['poll_id' => $poll->id, 'label' => $label, 'sort_order' => $i]);
        }

        AuditLog::record('plot_twist.published', "plot_twist_poll:{$poll->id}");

        return back()->with('ok', 'Poll published.');
    }

    /** Anyone suggests a question; it lands unapproved in the bank, HR-visible only. */
    public function suggest(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:255'],
            'kind' => ['required', 'string', Rule::in(['fun', 'who', 'social'])],
        ]);

        $employee = $request->attributes->get('employee');

        $question = PlotTwistQuestion::create([
            'text' => $data['text'],
            'kind' => $data['kind'],
            'template' => false,
            'approved' => false,
            'suggested_by' => $employee?->id,
        ]);

        // Not anonymous (suggested_by is recorded, HR sees the name in the bank),
        // so the normal actor-capturing AuditLog::record() is safe to use here.
        AuditLog::record('plot_twist.suggested', "plot_twist_question:{$question->id}");

        return back()->with('ok', 'Sent to HR. Thanks.');
    }

    /** One vote per person per poll, anonymous. */
    public function vote(Request $request, PlotTwistPoll $poll): JsonResponse
    {
        $this->assertSameTenant($poll);

        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403);

        $data = $request->validate([
            'option_id' => ['required', 'integer', Rule::exists('plot_twist_options', 'id')->where('poll_id', $poll->id)],
        ]);

        abort_unless($poll->isVotable(), 422, 'This poll is not open for voting right now.');

        $receipt = self::receiptFor($employee, $poll);
        abort_if(DB::table('plot_twist_receipts')->where('poll_id', $poll->id)->where('receipt', $receipt)->exists(), 422, 'Already voted.');

        try {
            DB::transaction(function () use ($poll, $data, $receipt): void {
                DB::table('plot_twist_votes')->insert([
                    'poll_id' => $poll->id,
                    'option_id' => $data['option_id'],
                    'created_at' => now(),
                ]);
                // Unique (poll_id, receipt) is the real backstop against a concurrent
                // double vote: this insert failing rolls the vote insert back too.
                DB::table('plot_twist_receipts')->insert(['poll_id' => $poll->id, 'receipt' => $receipt, 'created_at' => now()]);
            });
        } catch (QueryException $e) {
            if (str_starts_with((string) $e->getCode(), '23')) {
                abort(422, 'Already voted.');
            }
            throw $e;
        }

        // Anonymous by design: a direct create() bypasses AuditLog::record()'s
        // Auth::id() capture, so the row never carries the voter's identity —
        // "a vote was cast on poll N", nothing more (global-clause.md).
        AuditLog::create([
            'tenant_id' => app(CurrentTenant::class)->id(),
            'user_id' => null,
            'actor_name' => 'Anonymous',
            'action' => 'plot_twist.voted',
            'target' => "plot_twist_poll:{$poll->id}",
            'source' => AuditContext::source(),
        ]);

        return response()->json(['ok' => true]);
    }

    /** The named person on a who-question may withdraw it before it opens. */
    public function optOut(Request $request, PlotTwistPoll $poll): RedirectResponse
    {
        $this->assertSameTenant($poll);

        $employee = $request->attributes->get('employee');
        abort_unless($employee && (int) $poll->named_employee_id === $employee->id, 403);
        abort_unless($poll->optOutAllowed(), 422, 'Too late to opt out — the poll has already opened.');

        $poll->update(['status' => 'withdrawn']);
        AuditLog::record('plot_twist.opted_out', "plot_twist_poll:{$poll->id}");

        return back()->with('ok', 'Opted out.');
    }

    /** Results are 403 for everyone until reveals_at, then open to everyone — never a voter list. */
    public function results(Request $request, PlotTwistPoll $poll): JsonResponse
    {
        $this->assertSameTenant($poll);
        abort_unless($poll->isRevealed(), 403);

        $employee = $request->attributes->get('employee');
        $result = $this->renderResults($poll);

        return response()->json([
            'poll_id' => $poll->id,
            'question' => $poll->question,
            'total' => $result['total'],
            'options' => $result['options']->map(fn (array $row) => [
                'option_id' => $row['option']->id,
                'label' => $row['option']->label,
                'votes' => $row['votes'],
                'pct' => $row['pct'],
            ])->values(),
        ]);
    }

    /** @return array<string, mixed> */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $tenant = app(CurrentTenant::class)->get();
        $role = Permissions::effectiveRole($request->attributes->get('tenantRole', 'employee'));

        $poll = $this->currentPoll();
        $voted = $poll && $employee && DB::table('plot_twist_receipts')
            ->where('poll_id', $poll->id)->where('receipt', self::receiptFor($employee, $poll))->exists();

        $results = $poll && $poll->isRevealed() ? $this->renderResults($poll) : null;

        $canPublish = in_array($role, self::PUBLISH_ROLES, true);

        return [
            'poll' => $poll,
            'options' => $poll ? $poll->options : Collection::make(),
            'voted' => $voted,
            'results' => $results,
            'canOptOut' => $poll && $employee && (int) $poll->named_employee_id === $employee->id && $poll->optOutAllowed(),
            'canPublish' => $canPublish,
            'suggestions' => $canPublish
                ? PlotTwistQuestion::where('approved', false)->latest()->take(10)->get()
                : Collection::make(),
            'templates' => $canPublish
                ? PlotTwistQuestion::where('template', true)->where('approved', true)->get()
                : Collection::make(),
        ];
    }

    /**
     * The Notice board row for the dashboard's `notices` widget (contracts/dashboard-slots.md),
     * null unless a poll has actually revealed. Rendering this is also what "renders the
     * results" for the CR-18 idea feed — called from here and from screenData()/results()
     * so whichever surface is opened first does the (idempotent) write.
     *
     * @return array<string, mixed>|null
     */
    public function noticeRow(bool $plain): ?array
    {
        $poll = $this->currentPoll();
        if (! $poll || ! $poll->isRevealed()) {
            return null;
        }

        $result = $this->renderResults($poll);

        return [
            'kind' => 'plot_twist',
            'poll_id' => $poll->id,
            'question' => $poll->question,
            'total' => $result['total'],
            'options' => $result['options']->map(fn (array $row) => [
                'option_id' => $row['option']->id,
                'label' => $row['option']->label,
                'pct' => $row['pct'],
                'win' => $row['win'],
            ])->values()->all(),
            'plain' => $plain,
        ];
    }

    /**
     * The poll to show right now: the latest-opening `open` poll, revealed or not.
     * Once a later week's poll reveals, it takes over this slot — an earlier
     * revealed poll is simply superseded (mockup: "until the next week's poll opens").
     */
    public function currentPoll(): ?PlotTwistPoll
    {
        return PlotTwistPoll::where('status', 'open')->orderByDesc('opens_on')->orderByDesc('id')->first();
    }

    /**
     * Vote tallies + percentages (largest-remainder rounding so they sum to 100),
     * and — for a `social` poll seeing its first revealed render — the CR-18 feed.
     *
     * @return array{total: int, options: Collection<int, array{option: PlotTwistOption, votes: int, pct: int, win: bool}>}
     */
    private function renderResults(PlotTwistPoll $poll): array
    {
        $options = $poll->options;
        $counts = DB::table('plot_twist_votes')->where('poll_id', $poll->id)
            ->select('option_id', DB::raw('count(*) as c'))->groupBy('option_id')->pluck('c', 'option_id');
        $total = (int) $counts->sum();

        $byId = $options->mapWithKeys(fn (PlotTwistOption $o) => [$o->id => (int) ($counts[$o->id] ?? 0)])->all();
        $pcts = $this->percentages($byId, $total);
        $topVotes = $total > 0 ? max($byId) : null;

        $rows = $options->map(fn (PlotTwistOption $o) => [
            'option' => $o,
            'votes' => $byId[$o->id],
            'pct' => $pcts[$o->id],
            'win' => $topVotes !== null && $byId[$o->id] === $topVotes,
        ]);

        if ($poll->kind === 'social') {
            $this->feedSocialIdea($poll, $rows, $total);
        }

        return ['total' => $total, 'options' => $rows];
    }

    /**
     * Largest-remainder rounding: floor each share, then hand the leftover
     * percentage points to the options with the biggest fractional remainder,
     * so the percentages always sum to 100 (or all 0 when nobody voted).
     *
     * @param  array<int, int>  $counts  option id => vote count
     * @return array<int, int> option id => integer percentage
     */
    private function percentages(array $counts, int $total): array
    {
        if ($total === 0) {
            return array_fill_keys(array_keys($counts), 0);
        }

        $floors = [];
        $remainders = [];
        foreach ($counts as $id => $count) {
            $share = $count * 100 / $total;
            $floors[$id] = (int) floor($share);
            $remainders[$id] = $share - floor($share);
        }

        $leftover = 100 - array_sum($floors);
        arsort($remainders);
        foreach (array_keys($remainders) as $id) {
            if ($leftover <= 0) {
                break;
            }
            $floors[$id]++;
            $leftover--;
        }

        return $floors;
    }

    /**
     * CR-18: a social poll's winner feeds the newest open `recurring`-labelled
     * card whose title contains "social activity", once. Claimed atomically via
     * `idea_fed_at` so however many viewers render the results at once, only one
     * comment is ever written; if no such card exists yet, nothing is claimed,
     * so a card created later can still receive it.
     *
     * @param  Collection<int, array{option: PlotTwistOption, votes: int, pct: int, win: bool}>  $rows
     */
    private function feedSocialIdea(PlotTwistPoll $poll, Collection $rows, int $total): void
    {
        if ($total === 0 || $poll->idea_fed_at !== null) {
            return;
        }

        $winner = $rows->sortByDesc('votes')->first();
        if (! $winner) {
            return;
        }

        // labels is a JSON column; whereJsonContains disagrees between sqlite (tests)
        // and MySQL (dev/prod), so the label check happens in PHP over a narrow
        // candidate set (same rule CompanyEvent::taggedIds() and BuildsNav follow).
        $card = WorkItem::where('title', 'like', '%social activity%')
            ->whereNull('archived_at')->whereNull('cancelled_at')
            ->where('status', '!=', 'done')
            ->orderByDesc('id')->get()
            ->first(fn (WorkItem $w) => in_array('recurring', $w->labels ?? [], true));

        if (! $card) {
            return;
        }

        $claimed = PlotTwistPoll::whereKey($poll->id)->whereNull('idea_fed_at')->update(['idea_fed_at' => now()]);
        if ($claimed !== 1) {
            return;
        }
        $poll->idea_fed_at = now();

        WorkItemComment::create([
            'work_item_id' => $card->id,
            'employee_id' => null,
            'body' => "Plot Twist result: {$winner['option']->label} ({$winner['pct']}%)",
        ]);
    }

    private static function receiptFor(Employee $employee, PlotTwistPoll $poll): string
    {
        return hash_hmac('sha256', "{$employee->user_id}:{$poll->id}", config('app.key'));
    }

    private function assertSameTenant(PlotTwistPoll $poll): void
    {
        abort_unless($poll->tenant_id === app(CurrentTenant::class)->id(), 404);
    }
}
