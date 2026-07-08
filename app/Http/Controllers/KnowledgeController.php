<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\KnowledgeComment;
use App\Models\KnowledgeContribution;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeRead;
use App\Models\KnowledgeSegment;
use App\Models\KnowledgeStar;
use App\Services\FeatureManager;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Knowledge Bank — company-wide lesson sharing with a mandatory monthly habit.
 *
 *  - screenData(): the full /app/knowledge-bank page (segment tree, entry list,
 *    culture stats, and an HR/management compliance grid).
 *  - context(): the always-on data for the global chrome (header button pulse +
 *    unread badge, the slide-over panel feed, and the dashboard reminder). Merged
 *    into every screen render by AppController, so it stays deliberately lean.
 */
class KnowledgeController extends Controller
{
    /** Roles allowed to see the monthly compliance grid. */
    private const PRIVILEGED_ROLES = ['manager', 'management', 'hr'];

    /** Unread / "New" window: entries newer than this count toward the badge. */
    private const NEW_DAYS = 30;

    /** Recent entries surfaced in the slide-over panel feed. */
    private const PANEL_LIMIT = 20;

    /** Entries per page on the full Knowledge Bank screen. */
    private const PAGE_SIZE = 20;

    // ── Full page ───────────────────────────────────────────────────────────

    /**
     * Data for the dedicated Knowledge Bank screen.
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $segments = KnowledgeSegment::whereNull('parent_id')
            ->with('children')
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        $seg = $request->query('seg');
        $subseg = $request->query('subseg');
        $q = trim((string) $request->query('q', ''));

        $entries = KnowledgeEntry::with([
            'employee:id,name,initials,avatar_color,position',
            'segment:id,label',
            'subSegment:id,label',
            'comments' => fn ($c) => $c->with('employee:id,name,initials,avatar_color')->orderBy('id'),
        ])
            ->withCount(['stars', 'comments'])
            ->when($seg, fn ($b) => $b->where('seg_id', $seg))
            ->when($subseg, fn ($b) => $b->where('subseg_id', $subseg))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('title', 'like', "%$q%")
                ->orWhere('body', 'like', "%$q%")))
            ->orderByDesc('id')
            ->paginate(self::PAGE_SIZE)
            ->withQueryString();

        // Read / starred state is only needed for the entries actually on this page, so
        // scope both lookups to the page's ids (AK-DB-05) — the old pluck-all loaded a
        // per-employee id list that grows for the life of the account on every render.
        $entryIds = $entries->pluck('id');

        $readIds = $employee
            ? KnowledgeRead::where('employee_id', $employee->id)->whereIn('entry_id', $entryIds)->pluck('entry_id')->all()
            : [];

        // Entry ids the current employee has starred — drives the toggled star state.
        $starredIds = $employee
            ? KnowledgeStar::where('employee_id', $employee->id)->whereIn('entry_id', $entryIds)->pluck('entry_id')->all()
            : [];

        $role = $request->attributes->get('tenantRole', 'employee');
        $privileged = in_array($role, self::PRIVILEGED_ROLES, true);

        return array_merge([
            'segments' => $segments,
            'entries' => $entries,
            'readIds' => $readIds,
            'starredIds' => $starredIds,
            'newCutoff' => now()->subDays(self::NEW_DAYS),
            'filters' => ['seg' => $seg, 'subseg' => $subseg, 'q' => $q],
            'canSubmit' => (bool) $employee,
            'privileged' => $privileged,
            'compliance' => $privileged ? $this->compliance() : null,
        ], $this->stats(), $this->monthLabels());
    }

    // ── Global chrome context (merged into every screen) ─────────────────────

    /**
     * Always-on data for the header button, slide-over panel and dashboard
     * reminder. Returns kbEnabled=false (and nothing else heavy) when the module
     * is off for the tenant or the user has no employee record here.
     *
     * @return array<string, mixed>
     */
    public function context(?Employee $employee): array
    {
        $tenant = app(CurrentTenant::class)->get();

        if (! $tenant || ! app(FeatureManager::class)->screenAllowed($tenant, 'knowledge-bank')) {
            return ['kbEnabled' => false];
        }

        // Unread = recent entries this employee hasn't opened yet (drives the badge
        // and the per-segment "New" counts on the panel chips).
        $cutoff = now()->subDays(self::NEW_DAYS);
        $unread = collect();
        if ($employee) {
            // Subquery instead of pluck()->all(): the per-employee read-receipt list
            // grows monotonically and would otherwise be loaded into PHP on every render.
            $unread = KnowledgeEntry::where('created_at', '>=', $cutoff)
                ->whereNotIn('id', KnowledgeRead::where('employee_id', $employee->id)->select('entry_id'))
                ->get(['id', 'seg_id']);
        }
        $unreadBySeg = $unread->groupBy('seg_id')->map->count();

        // Load the one-level tree once; derive both the chip list (with per-segment
        // unread counts) and the Add-lesson form tree (top level + sub-segments).
        // Cached per tenant — segments change only via storeSegment(), which busts it.
        // Cache plain arrays, never live models: the file store serialize()s the
        // payload, and unserializing model objects breaks (__PHP_Incomplete_Class)
        // when the entry outlives a code/autoload change.
        $tree = Cache::remember(
            'kb:tree:'.$tenant->id,
            now()->addMinutes(10),
            fn () => KnowledgeSegment::whereNull('parent_id')
                ->with('children:id,parent_id,label')
                ->orderBy('sort_order')->orderBy('id')
                ->get(['id', 'label'])
                ->map(fn (KnowledgeSegment $s) => [
                    'id' => $s->id,
                    'label' => $s->label,
                    'children' => $s->children
                        ->map(fn (KnowledgeSegment $c) => ['id' => $c->id, 'label' => $c->label])
                        ->values()->all(),
                ])->values()->all(),
        );

        $segments = array_map(fn (array $s) => [
            'id' => $s['id'],
            'label' => $s['label'],
            'unread' => (int) ($unreadBySeg[$s['id']] ?? 0),
        ], $tree);

        // Already the ['id','label','children'] shape the form needs.
        $segmentsForm = $tree;

        $unreadIds = $unread->pluck('id')->all();

        // The recent-entry list is tenant-wide (identical for every viewer), so cache it per
        // tenant like kb:tree — it was an uncached 20-row query with 2 eager relations + 2
        // withCounts on EVERY screen render (AK-PERF-01). isNew is per-employee, so it is
        // layered on after the cache read. Busted by store()/storeSegment via forgetStatsCache.
        $recentRaw = Cache::remember(
            'kb:recent:'.$tenant->id,
            now()->addMinutes(10),
            fn () => KnowledgeEntry::with(['employee:id,name,initials,avatar_color,position', 'segment:id,label'])
                ->withCount(['stars', 'comments'])
                ->orderByDesc('id')
                ->limit(self::PANEL_LIMIT)
                ->get()
                ->map(fn (KnowledgeEntry $e) => [
                    'id' => $e->id,
                    'seg_id' => $e->seg_id,
                    'seg' => $e->segment?->label,
                    'title' => $e->title,
                    'body' => $e->body,
                    'author' => $e->employee?->name ?? 'Unknown',
                    'initials' => $e->employee?->initials ?? '–',
                    'color' => $e->employee?->avatar_color ?? config('amanahku.avatar_color'),
                    'dept' => $e->employee?->position,
                    'date' => $e->created_at?->format('d M'),
                    'stars' => (int) $e->stars_count,
                    'comments' => (int) $e->comments_count,
                ])->values()->all(),
        );

        $recent = array_map(
            fn (array $e) => $e + ['isNew' => in_array($e['id'], $unreadIds, true)],
            $recentRaw,
        );

        $owes = $employee ? $this->owesLesson($employee) : false;

        return array_merge([
            'kbEnabled' => true,
            'kbCanSubmit' => (bool) $employee,
            'kbOwes' => $owes,
            'kbUnread' => $unread->count(),
            'kbDaysLeft' => max(0, now()->daysInMonth - now()->day),
            'kbSegmentsNav' => $segments,
            'kbSegmentsForm' => $segmentsForm,
            'kbEntries' => $recent,
        ], $this->stats('kb'), $this->monthLabels('kb'));
    }

    // ── Writes ───────────────────────────────────────────────────────────────

    /** Any employee in the workspace may share a lesson. */
    public function store(Request $request): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $segIds = $this->segmentIds();
        $data = $request->validate([
            'seg_id' => ['required', Rule::in($segIds)],
            'subseg_id' => ['nullable', Rule::in($segIds)],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'tags' => ['nullable', 'string', 'max:200'],
        ]);

        $tags = collect(explode(',', (string) ($data['tags'] ?? '')))
            ->map(fn ($t) => trim($t))->filter()->values()->all();

        $entry = KnowledgeEntry::create([
            'seg_id' => $data['seg_id'],
            'subseg_id' => $data['subseg_id'] ?? null,
            'employee_id' => $employee->id,
            'title' => $data['title'],
            'body' => $data['body'],
            'tags' => $tags ?: null,
        ]);

        // Mark this calendar month's contribution as fulfilled (clears the reminder
        // and turns the panel banner green). Author never "owes" on entries they
        // just wrote — mark the entry as read for them too.
        $this->markContributed($employee);
        $this->forgetStatsCache();
        KnowledgeRead::firstOrCreate(
            ['employee_id' => $employee->id, 'entry_id' => $entry->id],
            ['read_at' => now()],
        );

        AuditLog::record('Shared a lesson', $entry->title);

        return back()->with('ok', 'Lesson shared with the company — "'.$entry->title.'".');
    }

    /** Create a segment (top-level by default; sub-segment when a parent is given). */
    public function storeSegment(Request $request): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $data = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'parent_id' => ['nullable', Rule::in($this->segmentIds(topLevelOnly: true))],
        ]);

        $segment = KnowledgeSegment::create([
            'label' => $data['label'],
            'parent_id' => $data['parent_id'] ?? null,
            'created_by' => $employee->id,
            'sort_order' => (int) (KnowledgeSegment::max('sort_order') ?? 0) + 1,
        ]);

        AuditLog::record('Created knowledge segment', $segment->label);
        $this->forgetStatsCache();

        return back()->with('ok', 'Segment "'.$segment->label.'" created.');
    }

    /** Toggle the current employee's star on an entry (one per person). */
    public function toggleStar(Request $request, KnowledgeEntry $entry): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');
        abort_unless($entry->tenant_id === app(CurrentTenant::class)->id(), 403);

        $existing = KnowledgeStar::where('entry_id', $entry->id)
            ->where('employee_id', $employee->id)
            ->first();

        if ($existing) {
            $existing->delete();

            return back()->with('ok', 'Star removed.');
        }

        try {
            KnowledgeStar::create(['entry_id' => $entry->id, 'employee_id' => $employee->id]);
        } catch (QueryException $e) {
            // 23xxx = the unique (entry_id, employee_id) duplicate-star guard. Anything else
            // is a real DB failure — don't mask it behind a friendly message.
            if (! str_starts_with((string) $e->getCode(), '23')) {
                throw $e;
            }

            return back()->with('ok', 'You already starred this.');
        }

        return back()->with('ok', 'Starred.');
    }

    /** Add a comment to an entry. Any employee in the workspace may comment. */
    public function comment(Request $request, KnowledgeEntry $entry): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');
        abort_unless($entry->tenant_id === app(CurrentTenant::class)->id(), 403);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        KnowledgeComment::create([
            'entry_id' => $entry->id,
            'employee_id' => $employee->id,
            'body' => $data['body'],
        ]);

        return back()->with('ok', 'Comment posted.');
    }

    /** Delete a comment — the author, or a privileged role, only. */
    public function deleteComment(Request $request, KnowledgeComment $comment): RedirectResponse
    {
        abort_unless($comment->tenant_id === app(CurrentTenant::class)->id(), 403);

        $employee = $request->attributes->get('employee');
        $privileged = $this->hasTenantRole($request, self::PRIVILEGED_ROLES);
        abort_unless($privileged || ($employee && $comment->employee_id === $employee->id), 403);

        $comment->delete();

        return back()->with('ok', 'Comment deleted.');
    }

    /**
     * Clear the unread badge: record a read receipt for every unread recent entry
     * for the current employee. Called by the panel on open (fetch); returns JSON.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $employee = $request->attributes->get('employee');
        if (! $employee) {
            return response()->json(['ok' => false, 'unread' => 0]);
        }

        $tenantId = app(CurrentTenant::class)->id();
        $readIds = KnowledgeRead::where('employee_id', $employee->id)->pluck('entry_id')->all();
        $unread = KnowledgeEntry::where('created_at', '>=', now()->subDays(self::NEW_DAYS))
            ->whereNotIn('id', $readIds ?: [0])
            ->pluck('id');

        $now = now();
        $rows = $unread->map(fn ($id) => [
            'tenant_id' => $tenantId,
            'employee_id' => $employee->id,
            'entry_id' => $id,
            'read_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($rows !== []) {
            KnowledgeRead::insertOrIgnore($rows);
        }

        return response()->json(['ok' => true, 'unread' => 0]);
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /** True when the employee has not logged a contribution this calendar month. */
    private function owesLesson(Employee $employee): bool
    {
        return ! KnowledgeContribution::where('employee_id', $employee->id)
            ->where('year', (int) now()->year)
            ->where('month', (int) now()->month)
            ->where('submitted', true)
            ->exists();
    }

    private function markContributed(Employee $employee): void
    {
        KnowledgeContribution::updateOrCreate(
            ['employee_id' => $employee->id, 'year' => (int) now()->year, 'month' => (int) now()->month],
            ['submitted' => true],
        );
    }

    /**
     * Company-wide culture stats. $prefix '' keys the full-page view
     * (total/segCount/contribPct/teamStreak); $prefix 'kb' keys the global chrome.
     *
     * @return array<string, mixed>
     */
    private function stats(string $prefix = ''): array
    {
        // Tenant-wide aggregates rendered into every screen's chrome — cached per
        // tenant and busted by the writes that change them (store/storeSegment).
        $raw = Cache::remember('kb:stats:'.app(CurrentTenant::class)->id(), now()->addMinutes(10), function (): array {
            $headcount = max(Employee::active()->count(), 1);
            $contributed = KnowledgeContribution::where('year', (int) now()->year)
                ->where('month', (int) now()->month)
                ->where('submitted', true)
                ->distinct('employee_id')->count('employee_id');

            return [
                'total' => KnowledgeEntry::count(),
                'segCount' => KnowledgeSegment::count(),
                'contribPct' => (int) round($contributed / $headcount * 100),
                'teamStreak' => $this->teamStreak(),
            ];
        });

        $key = fn (string $base) => $prefix === '' ? $base : $prefix.ucfirst($base);

        return collect($raw)->mapWithKeys(fn ($v, $k) => [$key($k) => $v])->all();
    }

    /** Bust the cached chrome aggregates after a write that changes them. */
    private function forgetStatsCache(): void
    {
        $tenantId = app(CurrentTenant::class)->id();
        Cache::forget('kb:stats:'.$tenantId);
        Cache::forget('kb:tree:'.$tenantId);
        Cache::forget('kb:recent:'.$tenantId);
    }

    /**
     * Consecutive months (counting back from the current month) in which the
     * company logged at least one contribution. A light "habit alive" signal.
     */
    private function teamStreak(): int
    {
        // Bounded + DISTINCT in SQL: the streak loop only looks back 36 months, so
        // never load every contribution row ever written (headcount × months).
        $months = KnowledgeContribution::where('submitted', true)
            ->where('year', '>=', (int) now()->year - 4)
            ->distinct()
            ->get(['year', 'month'])
            ->map(fn ($c) => sprintf('%04d-%02d', $c->year, $c->month))
            ->unique()->flip();

        $streak = 0;
        $cursor = now()->startOfMonth();
        for ($i = 0; $i < 36; $i++) {
            if (! $months->has($cursor->format('Y-m'))) {
                break;
            }
            $streak++;
            $cursor = $cursor->subMonth();
        }

        return $streak;
    }

    /**
     * Per-employee submitted/not-yet status for the current month — the HR/management
     * compliance grid.
     *
     * @return array{progressPct:int, members:Collection}
     */
    private function compliance(): array
    {
        $submittedIds = KnowledgeContribution::where('year', (int) now()->year)
            ->where('month', (int) now()->month)
            ->where('submitted', true)
            ->pluck('employee_id')->all();

        $members = Employee::active()->orderBy('name')->get(['id', 'name', 'initials', 'avatar_color'])
            ->map(fn ($e) => [
                'name' => $e->name,
                'initials' => $e->initials,
                'color' => $e->avatar_color,
                'submitted' => in_array($e->id, $submittedIds, true),
            ]);

        $headcount = max($members->count(), 1);

        return [
            'progressPct' => (int) round(count($submittedIds) / $headcount * 100),
            'members' => $members,
        ];
    }

    /** Current month name in EN + BM (Carbon has no bundled BM locale here). */
    private function monthLabels(string $prefix = ''): array
    {
        $ms = [1 => 'Januari', 'Februari', 'Mac', 'April', 'Mei', 'Jun', 'Julai', 'Ogos', 'September', 'Oktober', 'November', 'Disember'];
        $en = now()->format('F');
        $key = fn (string $base) => $prefix === '' ? $base : $prefix.ucfirst($base);

        return [
            $key('monthEn') => $en,
            $key('monthMs') => $ms[(int) now()->month] ?? $en,
        ];
    }

    /**
     * Segment ids in the active tenant (the global scope handles tenant isolation;
     * we list them so validation rejects ids from other tenants).
     *
     * @return array<int>
     */
    private function segmentIds(bool $topLevelOnly = false): array
    {
        return KnowledgeSegment::when($topLevelOnly, fn ($b) => $b->whereNull('parent_id'))
            ->pluck('id')->all();
    }
}
