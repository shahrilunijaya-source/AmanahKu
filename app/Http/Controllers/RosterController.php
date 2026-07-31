<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Shift;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class RosterController extends Controller
{
    private const PRIVILEGED_ROLES = ['manager', 'management', 'hr'];

    private const STATUSES = ['scheduled', 'confirmed', 'cancelled'];

    /**
     * Build the roster screen data. Tenant scope is automatic via BelongsToTenant.
     *
     * Privileged roles get the full week grid (employees × days) plus the employee
     * list for the assign form. A plain employee only ever sees their own upcoming
     * shifts — team scheduling data is never sent to the template for them.
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $privileged = $this->hasTenantRole($request, self::PRIVILEGED_ROLES);

        [$weekStart, $weekEnd] = $this->currentWeek();
        $days = $this->weekDays($weekStart);

        if (! $privileged) {
            $myShifts = $employee
                ? Shift::where('employee_id', $employee->id)
                    ->whereDate('date', '>=', now()->toDateString())
                    ->where('status', '!=', 'cancelled')
                    ->orderBy('date')->orderBy('start_time')->get()
                : new Collection;

            return [
                'privileged' => false,
                'days' => $days,
                'weekStart' => $weekStart,
                'weekEnd' => $weekEnd,
                'myShifts' => $myShifts,
                'employees' => new Collection,
                'grid' => new Collection,
                'weekCount' => 0,
            ];
        }

        $employees = Employee::active()->orderBy('name')->get(['id', 'name', 'initials', 'avatar_color']);

        $weekShifts = Shift::with('employee')
            ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->orderBy('start_time')
            ->get();

        // grid[employeeId][YYYY-MM-DD] = collection of shifts for that cell.
        $grid = $weekShifts
            ->groupBy('employee_id')
            ->map(fn (Collection $shifts) => $shifts->groupBy(fn (Shift $s) => $s->date->toDateString()));

        return [
            'privileged' => true,
            'days' => $days,
            'weekStart' => $weekStart,
            'weekEnd' => $weekEnd,
            'myShifts' => new Collection,
            'employees' => $employees,
            'grid' => $grid,
            'weekCount' => $weekShifts->where('status', '!=', 'cancelled')->count(),
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePrivileged($request);
        $tenantId = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('tenant_id', $tenantId)],
            'date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'location' => ['required', 'string', 'max:120'],
            'status' => ['required', Rule::in(self::STATUSES)],
        ]);

        Shift::create([
            'tenant_id' => $tenantId,
            'employee_id' => $data['employee_id'],
            'date' => $data['date'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'location' => $data['location'],
            'status' => $data['status'],
        ]);

        $who = Employee::find($data['employee_id'])?->name ?? 'employee';
        AuditLog::record('Scheduled shift', $who.' · '.Carbon::parse($data['date'])->format('D, j M').' · '.$data['location']);

        return back()->with('ok', 'Shift scheduled for '.$who.'.');
    }

    public function cancel(Request $request, Shift $shift): RedirectResponse
    {
        $this->authorizePrivileged($request);
        abort_unless($shift->tenant_id === app(CurrentTenant::class)->id(), 403);
        abort_unless($shift->status !== 'cancelled', 422);

        $shift->update(['status' => 'cancelled']);
        AuditLog::record('Cancelled shift', ($shift->employee?->name ?? 'employee').' · '.$shift->date->format('D, j M'));

        return back()->with('ok', 'Shift cancelled.');
    }

    private function authorizePrivileged(Request $request): void
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);
    }

    /**
     * Current week boundaries (Monday–Sunday).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function currentWeek(): array
    {
        return [now()->startOfWeek(), now()->endOfWeek()];
    }

    /**
     * Seven day descriptors for the week grid header.
     *
     * @return Collection<int, array{date: string, dow: string, dom: string, isToday: bool}>
     */
    private function weekDays(Carbon $weekStart): Collection
    {
        $today = now()->toDateString();

        return new Collection(array_map(function (int $offset) use ($weekStart, $today) {
            $day = $weekStart->copy()->addDays($offset);

            return [
                'date' => $day->toDateString(),
                'dow' => $day->format('D'),
                'dom' => $day->format('j M'),
                'isToday' => $day->toDateString() === $today,
            ];
        }, range(0, 6)));
    }
}
