<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Reaction;
use App\Models\WrappedArc;
use App\Models\WrappedStory;
use App\Support\DashboardPrefs;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CR-22 (session S28): Amanahku Wrapped. `/app/wrapped` renders only the acting
 * employee's own story — there is no `?emp=`, and one is never read. Share/unshare/react
 * work on `wrapped_stories`; character arcs (`wrapped_arcs`) are HR/director curated.
 * Route-model binding is not tenant-scoped, so every bound-model action re-checks
 * tenant_id before touching it (`docs/build/RULES.md` binding note).
 */
class WrappedController extends Controller
{
    private const ARC_ROLES = ['hr', 'director'];

    /** Own story only, plus the arc list for HR/director. */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $story = null;
        if ($employee !== null) {
            $query = WrappedStory::where('employee_id', $employee->id);
            $story = $request->filled('month')
                ? $query->whereDate('month', (string) $request->query('month'))->first()
                : $query->orderByDesc('month')->first();
        }

        $isArcCurator = $this->hasTenantRole($request, self::ARC_ROLES);

        return [
            'wrappedStory' => $story,
            'wrappedPlain' => (bool) DashboardPrefs::forUser($request->user()?->dashboard_prefs)['plain'],
            'isWrappedArcCurator' => $isArcCurator,
            'wrappedArcs' => $isArcCurator
                ? WrappedArc::where('active', true)->orderBy('rule')->orderBy('title')->get()
                : collect(),
        ];
    }

    /** Wins page: shared stories only, most recently shared first. */
    public function winsRows(): Collection
    {
        return WrappedStory::whereNotNull('employee_id')->whereNotNull('shared_at')
            ->with('employee')->get()
            ->map(fn (WrappedStory $story) => ['kind' => 'wrapped', 'story' => $story, 'at' => $story->shared_at]);
    }

    public function share(Request $request, WrappedStory $story): RedirectResponse
    {
        $this->assertSameTenant($story);
        abort_if($story->employee_id === null, 404);

        $employee = $request->attributes->get('employee');
        abort_unless($employee && $employee->id === $story->employee_id, 403);

        $story->update(['shared_at' => now()]);
        AuditLog::record('wrapped.shared', "wrapped_story:{$story->id}");

        return back()->with('ok', 'Shared to the Wall.');
    }

    public function unshare(Request $request, WrappedStory $story): RedirectResponse
    {
        $this->assertSameTenant($story);
        abort_if($story->employee_id === null, 404);

        $employee = $request->attributes->get('employee');
        abort_unless($employee && $employee->id === $story->employee_id, 403);

        $story->update(['shared_at' => null]);
        AuditLog::record('wrapped.unshared', "wrapped_story:{$story->id}");

        return back()->with('ok', 'Unshared.');
    }

    /** CR-30 reaction, one per person per story, toggle semantics. */
    public function react(Request $request, WrappedStory $story): JsonResponse
    {
        $this->assertSameTenant($story);

        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403);

        $data = $request->validate([
            'reaction' => ['required', 'string', 'in:'.implode(',', Reaction::activeKeys())],
        ]);

        $had = DB::table('wrapped_reactions')->where('wrapped_story_id', $story->id)
            ->where('employee_id', $employee->id)->pluck('reaction');

        DB::table('wrapped_reactions')->where('wrapped_story_id', $story->id)->where('employee_id', $employee->id)->delete();

        if (! $had->contains($data['reaction'])) {
            try {
                DB::table('wrapped_reactions')->insert([
                    'tenant_id' => app(CurrentTenant::class)->id(),
                    'wrapped_story_id' => $story->id,
                    'employee_id' => $employee->id,
                    'reaction' => $data['reaction'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (QueryException $e) {
                if (! str_starts_with((string) $e->getCode(), '23')) {
                    throw $e;
                }
            }
        }

        return response()->json(['ok' => true, 'html' => $this->reactPartial($story, $employee)]);
    }

    /** The picker + tally region for one story — used by react() and the dashboard moment on first paint. */
    public function reactPartial(WrappedStory $story, ?Employee $viewer): string
    {
        $rows = DB::table('wrapped_reactions')->where('wrapped_story_id', $story->id)->get();

        return view('partials.dash.wrapped-react', [
            'storyId' => $story->id,
            'counts' => $rows->groupBy('reaction')->map->count()->all(),
            'mine' => $viewer ? $rows->where('employee_id', $viewer->id)->pluck('reaction')->values()->all() : [],
        ])->render();
    }

    public function addArc(Request $request): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::ARC_ROLES);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'rule' => ['required', 'string', 'in:'.implode(',', WrappedArc::RULES)],
        ]);

        $arc = WrappedArc::create($data + ['active' => true]);
        AuditLog::record('wrapped.arc_added', "wrapped_arc:{$arc->id}");

        return back()->with('ok', 'Arc added.');
    }

    public function retireArc(Request $request, WrappedArc $arc): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::ARC_ROLES);
        $this->assertSameTenant($arc);

        if ($arc->active) {
            $arc->update(['active' => false]);
            AuditLog::record('wrapped.arc_retired', "wrapped_arc:{$arc->id}");
        }

        return back()->with('ok', 'Arc retired.');
    }

    private function assertSameTenant(WrappedStory|WrappedArc $model): void
    {
        abort_unless($model->tenant_id === app(CurrentTenant::class)->id(), 404);
    }
}
