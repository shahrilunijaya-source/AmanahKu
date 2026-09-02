<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Tenancy\CurrentTenant;
use App\Timesheet\WeekReconciler;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * HR "Leave Setup" — set each employee's opening leave balance per leave type.
 *
 * This is the migration path for carry-forward: balances from a previous system are
 * entered here as the starting point. Balances are written to the per-type
 * leave_balances table — the same rows accrual, carry-forward and leave approval
 * read and write — NOT the legacy employees.leave_balance scalar (which the profile
 * and dashboard cards now also read from leave_balances). Privileged (management / HR) only.
 */
class LeaveSetupController extends Controller
{
    private const PRIVILEGED_ROLES = ['management', 'hr'];

    /** Data for the Leave Setup screen: the staff × leave-type opening-balance matrix. */
    public function screenData(Request $request): array
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);

        $types = LeaveType::orderBy('name')->get();
        $staff = Employee::active()->with('leaveBalances')->orderBy('name')->get();

        // Pre-fill grid: [employee_id][leave_type_id] => current balance (missing row => no key).
        $matrix = $staff->mapWithKeys(fn (Employee $e) => [
            $e->id => $e->leaveBalances->keyBy('leave_type_id')->map(fn ($b) => (float) $b->balance),
        ]);

        return [
            'leaveTypes' => $types,
            'setupStaff' => $staff,
            'balanceMatrix' => $matrix,
            'holidays' => PublicHoliday::orderBy('date')->get(),
        ];
    }

    /**
     * Save the whole grid. Each filled cell is an opening balance that OVERWRITES the
     * current per-type balance (upsert on employee_id + leave_type_id). Blank cells are
     * left untouched. Only ids belonging to this tenant's active staff and leave types
     * are honoured; a forged employee/type id in the payload is ignored, so the grid can
     * never write a balance across tenants.
     */
    public function save(Request $request): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);

        $validated = $request->validate([
            'balances' => ['array'],
            'balances.*' => ['array'],
            'balances.*.*' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'applies' => ['array'],
            'applies.*' => ['array'],
            'applies.*.*' => ['nullable', 'boolean'],
        ]);

        // Whitelist writable ids from the tenant's own data (both models are tenant-scoped).
        // A type that spends another type's balance (Emergency off Annual) has no balance
        // of its own — writing one would create a row nothing ever reads or deducts. Nor
        // does a granted type (Replacement): HR books those days outright, so there is no
        // running total to open. Nor does Unpaid leave: it is not an entitlement anyone
        // is allotted, it is salary not paid for a day not worked, so it carries no quota
        // and is open to everyone — see the 2026_09_01 unpaid-carries-no-quota migration.
        $staffIds = Employee::active()->pluck('id')->flip();
        $typeIds = LeaveType::whereNull('deducts_from_leave_type_id')
            ->where('is_hr_granted_only', false)
            ->where('is_unpaid', false)
            ->pluck('id')->flip();

        $updated = 0;
        $removed = 0;

        // Which types each person is entitled to at all. A cleared tick box means the type
        // does not apply to them (an intern gets no annual leave), and that is stored as
        // the absence of a balance row — the same state as "never set up", which is what
        // the Apply form already hides. Every rendered cell posts a 0 or 1 here, so an
        // absent key means the cell was not on the page and nothing is touched.
        $applies = $validated['applies'] ?? [];

        DB::transaction(function () use ($validated, $applies, $staffIds, $typeIds, &$updated, &$removed) {
            foreach ($applies as $employeeId => $byType) {
                if (! is_array($byType) || ! $staffIds->has((int) $employeeId)) {
                    continue;
                }

                foreach ($byType as $typeId => $on) {
                    if ((bool) $on || ! $typeIds->has((int) $typeId)) {
                        continue;
                    }

                    $removed += LeaveBalance::where('employee_id', (int) $employeeId)
                        ->where('leave_type_id', (int) $typeId)
                        ->delete();
                }
            }

            foreach ($validated['balances'] ?? [] as $employeeId => $byType) {
                if (! is_array($byType) || ! $staffIds->has((int) $employeeId)) {
                    continue;
                }

                foreach ($byType as $typeId => $value) {
                    if (! $typeIds->has((int) $typeId)) {
                        continue;
                    }

                    // No tick box posted at all (an API-shaped post, or an older form)
                    // means the cell is simply a balance edit, not an eligibility change.
                    $ticked = (bool) ($applies[$employeeId][$typeId] ?? true);
                    if (! $ticked) {
                        continue;
                    }

                    // Ticked but left blank: the person is entitled to the type and starts
                    // at nothing, so a row still has to exist for the type to be offered.
                    // An existing balance is left exactly as it was.
                    if ($value === null || $value === '') {
                        if (LeaveBalance::where('employee_id', (int) $employeeId)
                            ->where('leave_type_id', (int) $typeId)->exists()) {
                            continue;
                        }
                        $value = 0;
                    }

                    // Stamp the month: an opening balance imported from the old system
                    // already includes that month's grant, so leave:accrue must not
                    // credit it again on the next run.
                    LeaveBalance::updateOrCreate(
                        ['employee_id' => (int) $employeeId, 'leave_type_id' => (int) $typeId],
                        ['balance' => (float) $value, 'last_accrued_on' => now()->startOfDay()],
                    );
                    $updated++;
                }
            }
        });

        AuditLog::record('Set opening leave balances', $updated.' balance(s) updated'
            .($removed > 0 ? ', '.$removed.' type(s) marked not applicable' : ''));

        return back()->with('ok', "Leave balances saved ({$updated} updated"
            .($removed > 0 ? ", {$removed} removed" : '').').');
    }

    // ---- Leave types (the master list balances are set against) ------------

    public function storeLeaveType(Request $request): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);

        $type = LeaveType::create($this->validateType($request) + ['tenant_id' => app(CurrentTenant::class)->id()]);
        AuditLog::record('Added leave type', $type->name);

        return back()->with('ok', $type->name.' leave type added.');
    }

    public function updateLeaveType(Request $request, LeaveType $leaveType): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);
        $this->assertTenant($leaveType);

        $data = $this->validateType($request, $leaveType->id);

        // Unpaid leave carries no quota, so an entitlement typed into the form is ignored
        // rather than written back — that figure is what created the phantom balance rows
        // the 2026_09_01 migration cleared.
        if ($leaveType->is_unpaid) {
            $data['entitlement'] = 0;
        }

        $leaveType->update($data);
        AuditLog::record('Updated leave type', $leaveType->name);

        return back()->with('ok', $leaveType->name.' updated.');
    }

    public function deleteLeaveType(Request $request, LeaveType $leaveType): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);
        $this->assertTenant($leaveType);

        // A type carrying history (requests or opening balances) must not be deleted —
        // that would orphan those records. There is no is_active flag on leave types, so
        // the guard is a hard block rather than a soft deactivate.
        $inUse = LeaveRequest::where('leave_type_id', $leaveType->id)->exists()
            || LeaveBalance::where('leave_type_id', $leaveType->id)->exists();
        if ($inUse) {
            return back()->with('error', $leaveType->name.' is in use (has balances or requests) — cannot delete.');
        }

        // Clear any "deducts from" references pointing at it first (e.g. Emergency → Annual).
        LeaveType::where('deducts_from_leave_type_id', $leaveType->id)->update(['deducts_from_leave_type_id' => null]);

        $name = $leaveType->name;
        $leaveType->delete();
        AuditLog::record('Removed leave type', $name);

        return back()->with('ok', $name.' removed.');
    }

    /**
     * One-click Malaysian starter set (Employment Act 2022 shape). Idempotent — skips any
     * type whose name already exists — so it is safe to run on a partly-populated tenant.
     * Emergency carries no entitlement of its own and spends the Annual balance. Replacement
     * is HR-granted only — its balance is set on this screen, never applied for.
     */
    public function loadStandardTypes(Request $request): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);
        $tid = app(CurrentTenant::class)->id();

        // [name, entitlement, requires_attachment, is_unplanned, min_notice_days]
        $set = [
            ['Annual', 16, false, false, 3],
            ['Medical', 14, true, false, 0],
            ['Hospitalization', 60, true, false, 0],
            ['Maternity', 98, true, false, 0],
            ['Paternity', 7, true, false, 0],
            ['Replacement', 4, false, false, 0],
            ['Emergency', 0, false, true, 0],
            ['Compassionate', 3, false, false, 0],
            ['Marriage', 3, false, false, 0],
            ['Unpaid', 0, false, false, 0],
        ];

        $existing = LeaveType::pluck('name')->map(fn ($n) => strtolower($n))->flip();
        $added = 0;

        foreach ($set as $x) {
            if ($existing->has(strtolower($x[0]))) {
                continue;
            }
            LeaveType::create([
                'tenant_id' => $tid, 'name' => $x[0], 'entitlement' => $x[1],
                'requires_attachment' => $x[2], 'is_unplanned' => $x[3], 'min_notice_days' => $x[4],
                // Payroll's unpaid-leave pull matches this flag, not the name — see the
                // 2026_08_25_210000 migration.
                'is_unpaid' => $x[0] === 'Unpaid',
                // Replacement is granted by HR (opening balance on this screen), not applied for.
                'is_hr_granted_only' => $x[0] === 'Replacement',
            ]);
            $added++;
        }

        // Wire Emergency to spend Annual, if both now exist and it is not already set.
        $annualId = LeaveType::where('name', 'Annual')->value('id');
        if ($annualId) {
            LeaveType::where('name', 'Emergency')->whereNull('deducts_from_leave_type_id')
                ->update(['deducts_from_leave_type_id' => $annualId]);
        }

        AuditLog::record('Loaded standard leave types', $added.' added');

        return back()->with($added > 0 ? 'ok' : 'error',
            $added > 0 ? "$added standard leave types added." : 'Standard leave types already exist.');
    }

    private function assertTenant(LeaveType $type): void
    {
        abort_unless($type->tenant_id === app(CurrentTenant::class)->id(), 403);
    }

    // ---- Public holidays (the calendar leave + attendance work against) ----

    public function storeHoliday(Request $request): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'date' => ['required', 'date'],
            'state' => ['nullable', 'string', 'max:80'],
        ]);
        $data['state'] = ($data['state'] ?? null) ?: null;

        $holiday = PublicHoliday::create($data + ['tenant_id' => app(CurrentTenant::class)->id()]);

        // Holiday->timesheet is otherwise pull-based: LockedDays only materialises the
        // "Public Holiday" row when a week is displayed or saved. A gazetted holiday lands
        // at short notice, so weeks already saved for that week would silently disagree
        // with it until each staffer re-saved. Push it into them here instead.
        $reconciled = app(WeekReconciler::class)->reconcileForHolidayDate($holiday->date);

        AuditLog::record('Added public holiday', $holiday->name.' '.$holiday->date->toDateString().' · '.$reconciled.' timesheet weeks reconciled');

        return back()->with('ok', $holiday->name.' added.');
    }

    public function deleteHoliday(Request $request, PublicHoliday $holiday): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);
        abort_unless($holiday->tenant_id === app(CurrentTenant::class)->id(), 403);

        $name = $holiday->name;
        $date = $holiday->date;
        $holiday->delete();

        // Mirror of storeHoliday: with the row gone, reconciling strips the generated
        // "Public Holiday" rows back out of stored weeks, so a holiday entered by mistake
        // does not leave every timesheet locked at 100% on a normal working day.
        $reconciled = app(WeekReconciler::class)->reconcileForHolidayDate($date);

        AuditLog::record('Removed public holiday', $name.' · '.$reconciled.' timesheet weeks reconciled');

        return back()->with('ok', $name.' removed.');
    }

    /**
     * One-click Malaysian 2026 federal/observed holiday set. Idempotent on (name, date).
     * Islamic + Hindu/Buddhist dates follow the lunar calendar and are best-estimates for
     * 2026 — HR should verify against the official gazette and adjust; hence they are fully
     * editable/deletable here.
     */
    public function loadStandardHolidays(Request $request): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);
        $tid = app(CurrentTenant::class)->id();

        // [name, date] — fixed dates exact; lunar dates (CNY / Raya / Wesak / Deepavali /
        // Muharram / Maulidur Rasul) are 2026 estimates to be confirmed.
        $set = [
            ["New Year's Day", '2026-01-01'],
            ['Thaipusam', '2026-02-01'],
            ['Chinese New Year', '2026-02-17'],
            ['Chinese New Year (Day 2)', '2026-02-18'],
            ['Hari Raya Aidilfitri', '2026-03-20'],
            ['Hari Raya Aidilfitri (Day 2)', '2026-03-21'],
            ['Labour Day', '2026-05-01'],
            ['Hari Raya Aidiladha', '2026-05-27'],
            ['Wesak Day', '2026-05-31'],
            ['Awal Muharram', '2026-06-16'],
            ['Maulidur Rasul', '2026-08-25'],
            ['National Day', '2026-08-31'],
            ['Malaysia Day', '2026-09-16'],
            ['Deepavali', '2026-11-08'],
            ['Christmas Day', '2026-12-25'],
        ];

        $addedDates = [];
        $have = PublicHoliday::get(['name', 'date'])
            ->map(fn ($h) => strtolower($h->name).'|'.$h->date->toDateString())->flip();
        $added = 0;

        foreach ($set as [$name, $date]) {
            if ($have->has(strtolower($name).'|'.$date)) {
                continue;
            }
            PublicHoliday::create(['tenant_id' => $tid, 'name' => $name, 'date' => $date]);
            $addedDates[] = $date;
            $added++;
        }

        // Same back-fill as storeHoliday, one pass per affected week: two holidays in the
        // same week (Raya, CNY) would otherwise rebuild that week twice for no gain.
        $reconciler = app(WeekReconciler::class);
        $weeks = collect($addedDates)->map(fn (string $d) => CarbonImmutable::parse($d)->startOfWeek()->toDateString())->unique();

        foreach ($weeks as $week) {
            $reconciler->reconcileForHolidayDate($week);
        }

        AuditLog::record('Loaded standard public holidays', $added.' added');

        return back()->with($added > 0 ? 'ok' : 'error',
            $added > 0 ? "$added public holidays added (verify the lunar-calendar dates)." : 'Those public holidays already exist.');
    }

    /** @return array<string,mixed> */
    private function validateType(Request $request, ?int $ignoreId = null): array
    {
        $tid = app(CurrentTenant::class)->id();

        // Empty form fields arrive as '' — coerce to null so the nullable numeric / exists
        // rules skip them instead of failing ('' is not null, so `nullable` alone won't).
        foreach (['entitlement', 'min_notice_days', 'monthly_accrual_days', 'max_carry_forward', 'max_balance', 'deducts_from_leave_type_id'] as $f) {
            if ($request->input($f) === '') {
                $request->merge([$f => null]);
            }
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('leave_types', 'name')->where('tenant_id', $tid)->ignore($ignoreId)],
            'entitlement' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'min_notice_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'monthly_accrual_days' => ['nullable', 'numeric', 'min:0', 'max:31'],
            'max_carry_forward' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'max_balance' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'deducts_from_leave_type_id' => ['nullable', Rule::exists('leave_types', 'id')->where('tenant_id', $tid)],
            'requires_attachment' => ['nullable', 'boolean'],
            'is_unplanned' => ['nullable', 'boolean'],
            'is_hr_granted_only' => ['nullable', 'boolean'],
        ]);

        $data['entitlement'] = $data['entitlement'] ?? 0;
        $data['min_notice_days'] = $data['min_notice_days'] ?? 0;
        $data['monthly_accrual_days'] = $data['monthly_accrual_days'] ?? 0;
        $data['requires_attachment'] = $request->boolean('requires_attachment');
        $data['is_unplanned'] = $request->boolean('is_unplanned');
        $data['is_hr_granted_only'] = $request->boolean('is_hr_granted_only');
        $data['deducts_from_leave_type_id'] = $data['deducts_from_leave_type_id'] ?? null;

        return $data;
    }
}
