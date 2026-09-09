<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Reaction;
use App\Models\VictoryBell;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CR-28 Victory Bell: reactions on a rung bell (CR-30, same toggle semantics as
 * BigDealController::react()) and the Wins-page archive data. Ringing itself is
 * WorkItemController::ring() — a bell belongs to a card, so it is created from
 * the board, not here. Route-model binding is not tenant-scoped, so every
 * action re-checks tenant_id before touching a bound VictoryBell.
 */
class VictoryBellController extends Controller
{
    /** CR-30 reaction, one per person per bell, toggle semantics. */
    public function react(Request $request, VictoryBell $bell): JsonResponse
    {
        $this->assertSameTenant($bell);

        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403);

        $data = $request->validate([
            'reaction' => ['required', 'string', 'in:'.implode(',', Reaction::activeKeys())],
        ]);

        $had = DB::table('victory_bell_reactions')->where('victory_bell_id', $bell->id)
            ->where('employee_id', $employee->id)->pluck('reaction');

        DB::table('victory_bell_reactions')->where('victory_bell_id', $bell->id)->where('employee_id', $employee->id)->delete();

        if (! $had->contains($data['reaction'])) {
            try {
                DB::table('victory_bell_reactions')->insert([
                    'tenant_id' => app(CurrentTenant::class)->id(),
                    'victory_bell_id' => $bell->id,
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

        return response()->json(['ok' => true, 'html' => $this->reactPartial($bell, $employee)]);
    }

    /**
     * The picker + tally region for one bell — used both by react() and by the
     * dashboard/Wins moment builders on first paint (same pattern as
     * BigDealController::reactPartial()).
     */
    public function reactPartial(VictoryBell $bell, ?Employee $viewer): string
    {
        $rows = DB::table('victory_bell_reactions')->where('victory_bell_id', $bell->id)->get();

        return view('partials.dash.victory-bell-react', [
            'bellId' => $bell->id,
            'counts' => $rows->groupBy('reaction')->map->count()->all(),
            'mine' => $viewer ? $rows->where('employee_id', $viewer->id)->pluck('reaction')->values()->all() : [],
        ])->render();
    }

    /** Wins page (The Playground): every Victory Bell ever rung, newest first — an archive, not a window. */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $bells = VictoryBell::with(['workItem.participants', 'workItem.employee', 'project', 'rungBy'])
            ->orderByDesc('rung_at')->get();

        return [
            'bells' => $bells->map(fn (VictoryBell $bell) => [
                'bell' => $bell,
                'reactHtml' => $this->reactPartial($bell, $employee),
            ]),
        ];
    }

    private function assertSameTenant(VictoryBell $bell): void
    {
        abort_unless($bell->tenant_id === app(CurrentTenant::class)->id(), 404);
    }
}
