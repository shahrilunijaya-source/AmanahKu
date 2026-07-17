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
        ]);

        // Whitelist writable ids from the tenant's own data (both models are tenant-scoped).
        $staffIds = Employee::active()->pluck('id')->flip();
        $typeIds = LeaveType::pluck('id')->flip();

        $updated = 0;

        DB::transaction(function () use ($validated, $staffIds, $typeIds, &$updated) {
            foreach ($validated['balances'] ?? [] as $employeeId => $byType) {
                if (! is_array($byType) || ! $staffIds->has((int) $employeeId)) {
                    continue;
                }

                foreach ($byType as $typeId => $value) {
                    if ($value === null || $value === '' || ! $typeIds->has((int) $typeId)) {
                        continue;
                    }

                    LeaveBalance::updateOrCreate(
                        ['employee_id' => (int) $employeeId, 'leave_type_id' => (int) $typeId],
                        ['balance' => (float) $value],
                    );
                    $updated++;
                }
            }
        });

        AuditLog::record('Set opening leave balances', $updated.' balance(s) updated');

        return back()->with('ok', "Leave balances saved ({$updated} updated).");
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

        $leaveType->update($this->validateType($request, $leaveType->id));
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
     * Emergency carries no entitlement of its own and spends the Annual balance.
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
        AuditLog::record('Added public holiday', $holiday->name.' '.$holiday->date->toDateString());

        return back()->with('ok', $holiday->name.' added.');
    }

    public function deleteHoliday(Request $request, PublicHoliday $holiday): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);
        abort_unless($holiday->tenant_id === app(CurrentTenant::class)->id(), 403);

        $name = $holiday->name;
        $holiday->delete();
        AuditLog::record('Removed public holiday', $name);

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

        $have = PublicHoliday::get(['name', 'date'])
            ->map(fn ($h) => strtolower($h->name).'|'.$h->date->toDateString())->flip();
        $added = 0;

        foreach ($set as [$name, $date]) {
            if ($have->has(strtolower($name).'|'.$date)) {
                continue;
            }
            PublicHoliday::create(['tenant_id' => $tid, 'name' => $name, 'date' => $date]);
            $added++;
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
        ]);

        $data['entitlement'] = $data['entitlement'] ?? 0;
        $data['min_notice_days'] = $data['min_notice_days'] ?? 0;
        $data['monthly_accrual_days'] = $data['monthly_accrual_days'] ?? 0;
        $data['requires_attachment'] = $request->boolean('requires_attachment');
        $data['is_unplanned'] = $request->boolean('is_unplanned');
        $data['deducts_from_leave_type_id'] = $data['deducts_from_leave_type_id'] ?? null;

        return $data;
    }
}
