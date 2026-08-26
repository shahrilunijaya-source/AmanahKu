<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Claim;
use App\Models\Employee;
use App\Models\FixedTransaction;
use App\Models\IndividualTransaction;
use App\Models\LeaveRequest;
use App\Models\OffboardingCase;
use App\Models\OvertimeRequest;
use App\Models\PayrollItem;
use App\Models\PayrollOpeningFigure;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipLine;
use App\Models\SalaryStructure;
use App\Services\FeatureManager;
use App\Services\Payroll\EpfCalculator;
use App\Services\Payroll\PayrollCalculator;
use App\Services\Payroll\PayslipComputation;
use App\Services\Payroll\PcbCalculator;
use App\Services\Payroll\PcbInputs;
use App\Services\Payroll\PcbYearToDate;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PayrollController extends Controller
{
    /** Payroll administration is restricted to senior management + HR. */
    private const ADMIN_ROLES = ['management', 'hr'];

    /**
     * Payroll Item codes a Fixed Transaction or Individual Transaction must never target —
     * each already has its own automatic source (basic salary from the salary structure,
     * overtime from approved OvertimeRequests, unpaid-leave from approved unpaid
     * LeaveRequests, claim reimbursement from approved claims). Allowing a Fixed or
     * Individual Transaction against one of these would double up or fight with that
     * automatic line.
     */
    private const FT_FORBIDDEN_ITEM_CODES = ['basic-salary', 'overtime', 'unpaid-leave-deduction', 'claim-reimbursement'];

    public function __construct(
        private readonly PayrollCalculator $calculator,
        private readonly PcbCalculator $pcb,
        private readonly EpfCalculator $epf,
        private readonly PcbYearToDate $pcbYtd,
    ) {}

    // ── Salary structures ─────────────────────────────────────────

    public function storeSalary(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $tid = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'employee_id' => ['required', Rule::exists('employees', 'id')->where('tenant_id', $tid)],
            'basic_salary' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'effective_from' => ['nullable', 'date'],
            'bank_name' => ['nullable', 'string', 'max:60'],
            'bank_account_no' => ['nullable', 'string', 'max:40'],
            'epf_no' => ['nullable', 'string', 'max:40'],
            'socso_no' => ['nullable', 'string', 'max:40'],
            'nationality' => ['nullable', Rule::in(['citizen', 'pr', 'foreign'])],
            'epf_opt_in_60plus' => ['boolean'],
            'epf_employee_rate_override' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_no' => ['nullable', 'string', 'max:40'],
            'spouse_working' => ['boolean'],
            'children_relief_count' => ['nullable', 'integer', 'min:0', 'max:20'],
            'disabled_self' => ['boolean'],
            'disabled_spouse' => ['boolean'],
            'zakat_monthly' => ['nullable', 'numeric', 'min:0'],
            'cp38_monthly' => ['nullable', 'numeric', 'min:0'],
            'skbbk_opt_in' => ['boolean'],
        ]);

        SalaryStructure::updateOrCreate(
            ['tenant_id' => $tid, 'employee_id' => $data['employee_id']],
            [
                'basic_salary' => $data['basic_salary'],
                // 'allowances' is deliberately no longer written here — Fixed Transactions
                // (storeFixedTransaction et al., below) are the single source for recurring
                // earnings now. The column itself is left alone (see the migration
                // 2026_08_25_200200): a finalized payslip's history and any rollback still
                // want it there, just nothing writes or reads it going forward.
                'effective_from' => $data['effective_from'] ?? now()->toDateString(),
                'bank_name' => $data['bank_name'] ?? null,
                'bank_account_no' => $data['bank_account_no'] ?? null,
                'epf_no' => $data['epf_no'] ?? null,
                'socso_no' => $data['socso_no'] ?? null,
                'nationality' => $data['nationality'] ?? 'citizen',
                'epf_opt_in_60plus' => $request->boolean('epf_opt_in_60plus'),
                'epf_employee_rate_override' => $data['epf_employee_rate_override'] ?? null,
                'tax_no' => $data['tax_no'] ?? null,
                // marital_status/nric live on the Employee record now — see the reconcile
                // migration 2026_08_25_200300 and PayrollController::buildPcbInputs().
                'spouse_working' => $request->boolean('spouse_working'),
                'children_relief_count' => $data['children_relief_count'] ?? 0,
                'disabled_self' => $request->boolean('disabled_self'),
                'disabled_spouse' => $request->boolean('disabled_spouse'),
                'zakat_monthly' => $data['zakat_monthly'] ?? 0,
                'cp38_monthly' => $data['cp38_monthly'] ?? 0,
                'skbbk_opt_in' => $request->boolean('skbbk_opt_in'),
            ],
        );

        $name = Employee::find($data['employee_id'])?->name;
        AuditLog::record('Updated salary structure', $name.' · basic RM '.number_format((float) $data['basic_salary'], 2));

        return back()->with('ok', 'Salary structure saved for '.$name.'.');
    }

    // ── Opening figures (mid-year "take on") ───────────────────────

    /**
     * What a previous employer/system already paid an employee earlier in a calendar
     * year — see PayrollOpeningFigure. Without this a mid-year joiner (or a company
     * switching to this app mid-year) gets a wrong PCB and a wrong EA form for the
     * rest of that year.
     */
    public function storeOpening(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $tid = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'employee_id' => ['required', Rule::exists('employees', 'id')->where('tenant_id', $tid)],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'gross' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'epf' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'pcb_paid' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'zakat_paid' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'additional_gross' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'additional_epf' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'socso' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'eis' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'optional_deductions' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'exempt_allowances' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'previous_employer' => ['nullable', 'string', 'max:120'],
            'previous_employer_tin' => ['nullable', 'string', 'max:40'],
        ]);

        PayrollOpeningFigure::updateOrCreate(
            ['tenant_id' => $tid, 'employee_id' => $data['employee_id'], 'year' => $data['year']],
            [
                'gross' => $data['gross'] ?? 0,
                'epf' => $data['epf'] ?? 0,
                'pcb_paid' => $data['pcb_paid'] ?? 0,
                'zakat_paid' => $data['zakat_paid'] ?? 0,
                'additional_gross' => $data['additional_gross'] ?? 0,
                'additional_epf' => $data['additional_epf'] ?? 0,
                'socso' => $data['socso'] ?? 0,
                'eis' => $data['eis'] ?? 0,
                'optional_deductions' => $data['optional_deductions'] ?? 0,
                'exempt_allowances' => $data['exempt_allowances'] ?? 0,
                'previous_employer' => $data['previous_employer'] ?? null,
                'previous_employer_tin' => $data['previous_employer_tin'] ?? null,
            ],
        );

        $name = Employee::find($data['employee_id'])?->name;
        AuditLog::record('Updated payroll opening figures', $name.' · '.$data['year']);

        return back()->with('ok', 'Opening figures saved for '.$name.' ('.$data['year'].').');
    }

    // ── Fixed Transactions (recurring per-employee pay/deduction lines) ────────────

    public function storeFixedTransaction(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $tid = app(CurrentTenant::class)->id();

        $data = $this->validateFixedTransaction($request, $tid);

        $tx = FixedTransaction::create($data + ['created_by_id' => Auth::id()]);

        $name = $tx->employee?->name;
        AuditLog::record('Added fixed transaction', $name.' · '.$tx->payrollItem?->name.' · RM '.number_format($tx->amount, 2));

        return back()->with('ok', 'Fixed transaction added for '.$name.'.');
    }

    public function updateFixedTransaction(Request $request, FixedTransaction $fixedTransaction): RedirectResponse
    {
        $this->authorizeAdmin($request);
        abort_unless($fixedTransaction->tenant_id === app(CurrentTenant::class)->id(), 403);

        $data = $this->validateFixedTransaction($request, $fixedTransaction->tenant_id, $fixedTransaction->employee_id);
        $fixedTransaction->update($data);

        AuditLog::record('Updated fixed transaction', $fixedTransaction->employee?->name.' · '.$fixedTransaction->payrollItem?->name);

        return back()->with('ok', 'Fixed transaction updated for '.$fixedTransaction->employee?->name.'.');
    }

    /**
     * Ends a Fixed Transaction — sets end_period, never deletes the row, so any payslip
     * already generated from it stays explicable. Takes effect from the following run:
     * a period equal to or before end_period still matches scopeActiveDuring().
     */
    public function endFixedTransaction(Request $request, FixedTransaction $fixedTransaction): RedirectResponse
    {
        $this->authorizeAdmin($request);
        abort_unless($fixedTransaction->tenant_id === app(CurrentTenant::class)->id(), 403);

        $data = $request->validate([
            'end_period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        $fixedTransaction->update(['end_period' => $data['end_period']]);
        AuditLog::record('Ended fixed transaction', $fixedTransaction->employee?->name.' · '.$fixedTransaction->payrollItem?->name.' · last period '.$data['end_period']);

        return back()->with('ok', 'Fixed transaction ended after '.$data['end_period'].'.');
    }

    /**
     * @return array{employee_id: int, payroll_item_id: int, amount: float, start_period: string, end_period: ?string, last_amount: ?float, prorate: bool, remarks: ?string}
     */
    private function validateFixedTransaction(Request $request, int $tid, ?int $lockEmployeeId = null): array
    {
        $data = $request->validate([
            'employee_id' => $lockEmployeeId !== null
                ? ['prohibited']   // editing/ending never reassigns the employee
                : ['required', Rule::exists('employees', 'id')->where('tenant_id', $tid)],
            // Basic salary, overtime, unpaid-leave and claim reimbursement already have
            // their own automatic source — a Fixed Transaction must not double them up.
            'payroll_item_id' => [
                'required',
                Rule::exists('payroll_items', 'id')->where('tenant_id', $tid)->where('active', true)
                    ->whereNotIn('code', self::FT_FORBIDDEN_ITEM_CODES),
            ],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:10000000'],
            'start_period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'end_period' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/', 'after_or_equal:start_period'],
            'last_amount' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'prorate' => ['boolean'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);

        return [
            'employee_id' => $lockEmployeeId ?? $data['employee_id'],
            'payroll_item_id' => $data['payroll_item_id'],
            'amount' => $data['amount'],
            'start_period' => $data['start_period'],
            'end_period' => $data['end_period'] ?? null,
            'last_amount' => $data['last_amount'] ?? null,
            'prorate' => $request->boolean('prorate'),
            'remarks' => $data['remarks'] ?? null,
        ];
    }

    // ── Individual Transactions (one-off per-employee pay/deduction lines) ─────────

    public function storeIndividualTransaction(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $tid = app(CurrentTenant::class)->id();

        $data = $this->validateIndividualTransaction($request, $tid);
        $this->assertPeriodEditable($tid, $data['period']);

        $tx = IndividualTransaction::create($data + ['created_by_id' => Auth::id()]);

        $name = $tx->employee?->name;
        AuditLog::record('Added individual transaction', $name.' · '.$tx->payrollItem?->name.' · '.$data['period'].' · RM '.number_format($tx->amount, 2));

        return back()->with('ok', 'Individual transaction added for '.$name.'.');
    }

    public function updateIndividualTransaction(Request $request, IndividualTransaction $individualTransaction): RedirectResponse
    {
        $this->authorizeAdmin($request);
        abort_unless($individualTransaction->tenant_id === app(CurrentTenant::class)->id(), 403);
        $this->assertPeriodEditable($individualTransaction->tenant_id, $individualTransaction->period);

        $data = $this->validateIndividualTransaction($request, $individualTransaction->tenant_id, $individualTransaction->employee_id, $individualTransaction->period);
        $individualTransaction->update($data);

        AuditLog::record('Updated individual transaction', $individualTransaction->employee?->name.' · '.$individualTransaction->payrollItem?->name);

        return back()->with('ok', 'Individual transaction updated for '.$individualTransaction->employee?->name.'.');
    }

    public function destroyIndividualTransaction(Request $request, IndividualTransaction $individualTransaction): RedirectResponse
    {
        $this->authorizeAdmin($request);
        abort_unless($individualTransaction->tenant_id === app(CurrentTenant::class)->id(), 403);
        $this->assertPeriodEditable($individualTransaction->tenant_id, $individualTransaction->period);

        $name = $individualTransaction->employee?->name;
        $itemName = $individualTransaction->payrollItem?->name;
        $individualTransaction->delete();

        AuditLog::record('Deleted individual transaction', $name.' · '.$itemName);

        return back()->with('ok', 'Individual transaction deleted for '.$name.'.');
    }

    /**
     * @return array{employee_id: int, payroll_item_id: int, period: string, amount: float, remarks: ?string}
     */
    private function validateIndividualTransaction(Request $request, int $tid, ?int $lockEmployeeId = null, ?string $lockPeriod = null): array
    {
        $data = $request->validate([
            'employee_id' => $lockEmployeeId !== null
                ? ['prohibited']   // editing never reassigns the employee — same as a Fixed Transaction
                : ['required', Rule::exists('employees', 'id')->where('tenant_id', $tid)],
            // Same forbidden list as a Fixed Transaction — basic salary, overtime,
            // unpaid-leave and claim reimbursement already have their own automatic source.
            'payroll_item_id' => [
                'required',
                Rule::exists('payroll_items', 'id')->where('tenant_id', $tid)->where('active', true)
                    ->whereNotIn('code', self::FT_FORBIDDEN_ITEM_CODES),
            ],
            'period' => $lockPeriod !== null
                ? ['prohibited']   // editing never moves a one-off to a different month
                : ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:10000000'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);

        return [
            'employee_id' => $lockEmployeeId ?? $data['employee_id'],
            'payroll_item_id' => $data['payroll_item_id'],
            'period' => $lockPeriod ?? $data['period'],
            'amount' => $data['amount'],
            'remarks' => $data['remarks'] ?? null,
        ];
    }

    /**
     * A period whose run has been finalized is locked — that payslip has been issued and
     * an Individual Transaction must not add, edit or delete anything against it. A
     * period with a draft run, or no run at all yet, is freely editable.
     */
    private function assertPeriodEditable(int $tid, string $period): void
    {
        $finalized = PayrollRun::where('tenant_id', $tid)->where('period', $period)->where('status', 'finalized')->exists();
        abort_if($finalized, 422, 'Payroll for '.$period.' has already been finalized and can no longer be changed.');
    }

    /**
     * This employee's Individual Transactions queued for $period, resolved to
     * {item: PayrollItem, amount: float, remark: ?string} — the single read path
     * createRun() and updatePayslip() both use, so a one-off queued directly and one
     * added while editing a draft payslip are built from the exact same rows.
     *
     * Deliberately no generic @return annotation (Collection<int, array{...}>): a
     * nullable string key ('remark') in an array-shaped Collection generic here trips a
     * PHPStan/Larastan TValue-invariance false positive — confirmed unrelated to this
     * method's own logic by isolating it to a bare 3-key array{item: object, amount:
     * float, x: ?string} shape returned as a Collection<int, that-shape>, which fails
     * the exact same way regardless of construction method (filter+map chain, plain
     * foreach, an explicit @var cast). Swapping the nullable key for a non-nullable one
     * (e.g. int) makes it pass, isolating the trigger to nullability specifically. No
     * fix short of a baseline entry/ignore comment, which CLAUDE.md rules out for this
     * pass — see individualLineAttrs()/refreshVariableLines() below for the real shape.
     */
    private function individualTransactionLinesForPeriod(Employee $employee, string $period): Collection
    {
        $rows = IndividualTransaction::with('payrollItem')
            ->where('employee_id', $employee->id)
            ->forPeriod($period)
            ->get();

        $lines = [];
        foreach ($rows as $row) {
            $item = $row->payrollItem;
            if ($item === null) {
                continue;
            }
            $lines[] = ['item' => $item, 'amount' => (float) $row->amount, 'remark' => $row->remarks];
        }

        return new Collection($lines);
    }

    /**
     * Merge the posted tx_id[]/tx_item_id[]/tx_amount[]/tx_remark[] Individual
     * Transaction rows from a payslip edit into the individual_transactions table — the
     * unification point: editing a draft payslip writes into the same table a queued
     * one-off does, rather than staying a parallel mechanism.
     *
     * Deliberately id-aware, NOT delete-then-recreate: the edit form is a snapshot from
     * whenever the page was rendered, and between then and this save someone else (a
     * concurrent tab, another person, or the standalone Individual Transactions screen)
     * may have added a row this form never saw. $knownIds is every row id the form WAS
     * rendered with —
     *   - a posted row with a tx_id updates that existing row;
     *   - a posted row with a blank tx_id creates a new one;
     *   - a $knownIds id that no row came back for was removed by HR and is deleted;
     *   - any table row NOT in $knownIds is left completely alone — it didn't exist when
     *     the form was rendered, so this save has no opinion about it.
     * Every id in $knownIds/tx_id is re-scoped to this employee+period+tenant here rather
     * than trusted verbatim — a stale or tampered id belonging to someone else's row (or
     * a different period) is silently ignored, never acted on.
     *
     * @param  array<int, mixed>  $ids
     * @param  array<int, mixed>  $itemIds
     * @param  array<int, mixed>  $amounts
     * @param  array<int, mixed>  $remarks
     * @param  array<int, mixed>  $knownIds
     */
    private function syncIndividualTransactions(Employee $employee, string $period, array $ids, array $itemIds, array $amounts, array $remarks, array $knownIds): void
    {
        $knownIds = collect($knownIds)->filter(fn ($v) => $v !== null && $v !== '')->map(fn ($v) => (int) $v)->unique()->values()->all();

        // Re-derive from the DB rather than trusting the posted ids: a row that doesn't
        // actually belong to this employee/period/tenant is dropped here, so it can never
        // be updated or deleted by this form no matter what id was posted for it.
        $knownRows = IndividualTransaction::where('employee_id', $employee->id)
            ->forPeriod($period)
            ->whereIn('id', $knownIds)
            ->get()->keyBy('id');

        $touchedIds = [];

        foreach ($itemIds as $i => $itemId) {
            $rawId = $ids[$i] ?? null;
            $rowId = ($rawId !== null && $rawId !== '') ? (int) $rawId : null;
            $knownRow = $rowId !== null ? $knownRows->get($rowId) : null;

            if ($itemId === null || $itemId === '') {
                // Blank row. If it maps to a known row, HR cleared that line — leave it
                // untouched here and let it be picked up by the deletion pass below
                // (it's simply never added to $touchedIds).
                continue;
            }
            $amount = $amounts[$i] ?? null;
            if ($amount === null || $amount === '' || (float) $amount <= 0) {
                continue;
            }
            $remark = trim((string) ($remarks[$i] ?? ''));
            $attrs = [
                'payroll_item_id' => (int) $itemId,
                'amount' => round((float) $amount, 2),
                'remarks' => $remark !== '' ? $remark : null,
            ];

            if ($knownRow !== null) {
                $knownRow->update($attrs);
                $touchedIds[] = $knownRow->id;
            } else {
                // A blank tx_id, or an id this form didn't actually know about (ignored
                // above) — either way, a brand new row.
                $tx = IndividualTransaction::create($attrs + [
                    'employee_id' => $employee->id,
                    'period' => $period,
                    'created_by_id' => Auth::id(),
                ]);
                $touchedIds[] = $tx->id;
            }
        }

        $toDelete = $knownRows->keys()->diff($touchedIds);
        if ($toDelete->isNotEmpty()) {
            IndividualTransaction::whereIn('id', $toDelete)->delete();
        }
    }

    /**
     * This employee's Fixed Transactions active for $period, each resolved to this
     * month's amount: last_amount when the run period is exactly the transaction's
     * end_period (the client's HRMS lets the final month differ from every other month),
     * otherwise the standing amount — then, when prorate is on, multiplied by the
     * calendar-day proration factor. That factor is 1.0 for a full-month employee, so
     * applying it unconditionally is always safe.
     *
     * @return Collection<int, array{item: PayrollItem, amount: float, fixed_transaction_id: int}>
     */
    private function fixedTransactionLines(Employee $employee, string $period): Collection
    {
        return FixedTransaction::with('payrollItem')
            ->where('employee_id', $employee->id)
            ->activeDuring($period)
            ->get()
            ->filter(fn (FixedTransaction $ft) => $ft->payrollItem !== null)
            ->map(function (FixedTransaction $ft) use ($employee, $period) {
                $amount = ($ft->end_period === $period && $ft->last_amount !== null)
                    ? $ft->last_amount
                    : $ft->amount;

                if ($ft->prorate) {
                    $amount = round($amount * $this->prorationFactor($employee, $period), 2);
                }

                return ['item' => $ft->payrollItem, 'amount' => $amount, 'fixed_transaction_id' => $ft->id];
            })
            ->values();
    }

    /**
     * Calendar-day proration for a Fixed Transaction with prorate on — confirmed with
     * the client: amount × days employed in the month ÷ days in that month, using the
     * REAL number of days in the month (28/29/30/31), not a fixed figure.
     *
     * Deliberately NOT the same basis as unpaid-leave/overtime proration elsewhere in
     * PayrollCalculator (Employment Act ordinary rate: a fixed 26 days/month, 8
     * hours/day) — two different divisors for two different rules is correct here. Do
     * not "simplify" this by unifying them with that one.
     *
     * Joining mid-month: Employee::joined_at. Leaving mid-month: the best available
     * signal is an in-progress or completed OffboardingCase whose last_day (a real,
     * HR-entered leaving date — see OffboardingService/ArchiveDepartedStaff) falls
     * inside this period. There is no other reliable leaving-date column today — an
     * employee archived without going through the offboarding flow (e.g. the manual
     * "Archive staff" action) has no such signal and is treated as employed the full
     * month here.
     */
    private function prorationFactor(Employee $employee, string $period): float
    {
        $periodStart = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        $periodEnd = Carbon::createFromFormat('Y-m', $period)->endOfMonth();
        $daysInMonth = $periodEnd->day;

        $employedFrom = $periodStart;
        if ($employee->joined_at !== null && $employee->joined_at->gt($periodStart)) {
            $employedFrom = $employee->joined_at->copy();
        }

        $employedTo = $periodEnd;
        $lastDay = OffboardingCase::where('employee_id', $employee->id)
            ->whereIn('status', ['in_progress', 'completed'])
            ->whereBetween('last_day', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->orderByDesc('last_day')
            ->value('last_day');
        if ($lastDay !== null) {
            $employedTo = Carbon::parse($lastDay);
        }

        if ($employedFrom->gt($employedTo)) {
            return 0.0;
        }

        // Both dates fall within the same calendar month (employedFrom/employedTo are
        // clamped to [periodStart, periodEnd] above) — day-of-month subtraction avoids
        // diffInDays' float rounding on a periodEnd that carries a 23:59:59 time part.
        $daysEmployed = $employedTo->day - $employedFrom->day + 1;

        return min(1.0, max(0.0, $daysEmployed / $daysInMonth));
    }

    // ── Overtime / unpaid-leave pull (approved requests → payslip) ─────────────────

    /**
     * OvertimeRequest ids already reserved by some OTHER payslip — never pulled twice
     * across concurrent or sequential runs, mirroring createRun()'s $usedClaimIds. Pass
     * the current payslip's own id (updatePayslip's recompute) so it doesn't compete
     * against its own prior pull.
     *
     * @return array<int, int>
     */
    private function usedOvertimeIds(?int $excludePayslipId): array
    {
        return Payslip::whereNotNull('overtime_request_ids')
            ->when($excludePayslipId, fn ($q) => $q->whereKeyNot($excludePayslipId))
            ->pluck('overtime_request_ids')->flatten()->filter()->unique()->all();
    }

    /** @return array<int, int> */
    private function usedUnpaidLeaveIds(?int $excludePayslipId): array
    {
        return Payslip::whereNotNull('unpaid_leave_request_ids')
            ->when($excludePayslipId, fn ($q) => $q->whereKeyNot($excludePayslipId))
            ->pluck('unpaid_leave_request_ids')->flatten()->filter()->unique()->all();
    }

    /**
     * Approved overtime for $employee whose ot_date falls inside $period, not yet paid
     * and not already reserved by another payslip. A public-holiday (3x) request and an
     * ordinary (1.5x) one are never worth the same per hour — see overtimeGroups(),
     * which splits these by rate before they reach PayrollCalculator.
     *
     * @return Collection<int, OvertimeRequest>
     */
    private function pullableOvertimeFor(Employee $employee, string $period, array $usedIds): Collection
    {
        $periodStart = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        $periodEnd = Carbon::createFromFormat('Y-m', $period)->endOfMonth();

        return OvertimeRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')->whereNull('paid_at')
            ->whereBetween('ot_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->whereNotIn('id', $usedIds)
            ->lockForUpdate()->get();
    }

    /**
     * Group approved overtime requests by their rate_multiplier — a public-holiday (3x)
     * request and an ordinary (1.5x) one must never be summed into one "equivalent
     * hours" figure (that flattening is exactly the ambiguity that let HR's raw-hours
     * override double-multiply an already-multiplied pulled figure). Each group carries
     * its own raw hours total, ready for PayrollCalculator to multiply exactly once.
     *
     * @param  Collection<int, OvertimeRequest>  $overtimeRequests
     * @return array<int, array{hours: float, multiplier: float}>
     */
    private function overtimeGroups(Collection $overtimeRequests): array
    {
        return $overtimeRequests
            ->groupBy(fn (OvertimeRequest $o) => (string) round((float) $o->rate_multiplier, 2))
            ->map(fn (Collection $group) => [
                'hours' => round($group->sum(fn (OvertimeRequest $o) => (float) $o->hours), 2),
                'multiplier' => round((float) $group->first()->rate_multiplier, 2),
            ])
            ->sortBy('multiplier')->values()->all();
    }

    /**
     * Approved unpaid leave for $employee overlapping $period, not yet paid and not
     * already reserved by another payslip. "Unpaid" is whichever LeaveType has is_unpaid
     * set (leave_types.is_unpaid) — never matched by name, which breaks the moment a
     * company renames the type or seeds it in Malay (see the 2026_08_25_210000 migration).
     *
     * Not period-scoped down to the day (a request spanning two payroll periods is pulled
     * whole into whichever run gets to it first, same as claims aren't day-scoped either)
     * — a multi-month unpaid leave request needs HR to split the days by hand across the
     * two runs via the override.
     *
     * @return Collection<int, LeaveRequest>
     */
    private function pullableUnpaidLeaveFor(Employee $employee, string $period, array $usedIds): Collection
    {
        $periodStart = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        $periodEnd = Carbon::createFromFormat('Y-m', $period)->endOfMonth();

        return LeaveRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')->whereNull('paid_at')
            ->whereHas('leaveType', fn ($q) => $q->where('is_unpaid', true))
            ->where('date_from', '<=', $periodEnd->toDateString())
            ->where('date_to', '>=', $periodStart->toDateString())
            ->whereNotIn('id', $usedIds)
            ->lockForUpdate()->get();
    }

    // ── Payroll run lifecycle ─────────────────────────────────────

    public function createRun(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $tid = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        if (PayrollRun::where('tenant_id', $tid)->where('period', $data['period'])->exists()) {
            return back()->withErrors(['period' => 'A payroll run already exists for '.$data['period'].'.'])->withInput();
        }

        $employees = Employee::active()->with('salaryStructure')
            ->whereHas('salaryStructure')
            ->whereIn('status', ['active', 'probation', 'on_leave'])   // everyone currently employed (allowlist)
            ->orderBy('name')->get();

        if ($employees->isEmpty()) {
            return back()->withErrors(['period' => 'No employees have a salary structure yet. Add salary structures first.'])->withInput();
        }

        // Contribution category is assessed at the pay period's end.
        $periodEnd = Carbon::createFromFormat('Y-m', $data['period'])->endOfMonth();
        $missingDob = $employees->whereNull('date_of_birth')->count();

        $catalog = PayrollItem::where('tenant_id', $tid)->get()->keyBy('code');

        DB::transaction(function () use ($data, $employees, $periodEnd, $catalog) {
            $run = new PayrollRun([
                'period' => $data['period'],
                'label' => Carbon::createFromFormat('Y-m', $data['period'])->format('F Y'),
                'run_by_id' => Auth::id(),
            ]);
            // status is a lifecycle column excluded from $fillable — set it directly.
            $run->status = 'draft';
            $run->save();

            // Claims already attached to any run must never be pulled again — prevents
            // double reimbursement across concurrent or sequential draft runs.
            $usedClaimIds = Payslip::whereNotNull('claim_ids')
                ->pluck('claim_ids')->flatten()->filter()->unique()->all();
            // Same double-pull protection for approved overtime and unpaid leave.
            $usedOvertimeIds = $this->usedOvertimeIds(null);
            $usedLeaveIds = $this->usedUnpaidLeaveIds(null);

            foreach ($employees as $employee) {
                $structure = $employee->salaryStructure;
                $claims = $employee->claims()
                    ->where('status', 'approved')->whereNull('paid_at')
                    ->whereNotIn('id', $usedClaimIds)
                    ->lockForUpdate()->get();

                $overtimeRequests = $this->pullableOvertimeFor($employee, $data['period'], $usedOvertimeIds);
                $pulledOvertimeGroups = $this->overtimeGroups($overtimeRequests);
                $pulledOvertimeHours = round($overtimeRequests->sum(fn (OvertimeRequest $o) => (float) $o->hours), 2);
                $unpaidLeaveRequests = $this->pullableUnpaidLeaveFor($employee, $data['period'], $usedLeaveIds);
                $pulledUnpaidDays = round($unpaidLeaveRequests->sum(fn (LeaveRequest $l) => (float) $l->days), 2);

                $age = $employee->date_of_birth === null ? null : (int) $employee->date_of_birth->diffInYears($periodEnd);
                // electedBefore1998 has no column — no tenant has data going back that far,
                // so every non-citizen falls under mandatory Part F.
                $epfPart = $this->epf->part($structure->nationality ?? 'citizen', $age, false);

                // Fixed Transactions replace salary_structures.allowances as the source of
                // recurring earnings/deductions (see migration 2026_08_25_200200) — split
                // by the transaction's own Payroll Item type.
                $ftLines = $this->fixedTransactionLines($employee, $data['period']);
                $fixedEarnings = $ftLines->filter(fn (array $l) => $l['item']->type === 'earning');
                $fixedDeductions = $ftLines->filter(fn (array $l) => $l['item']->type === 'deduction');

                // Individual Transactions queued for this period — see
                // individualTransactionLinesForPeriod(), the same read path updatePayslip
                // uses, so a one-off queued before the run existed appears here exactly as
                // it would if it had been typed into the payslip edit form instead.
                $itLines = $this->individualTransactionLinesForPeriod($employee, $data['period']);
                $individualEarnings = $itLines->filter(fn (array $l) => $l['item']->type === 'earning');
                $individualDeductions = $itLines->filter(fn (array $l) => $l['item']->type === 'deduction');

                $inputs = [
                    'basic' => $structure->basic_salary,
                    'allowances_total' => round($fixedEarnings->sum('amount'), 2),
                    'fixed_deductions_total' => round($fixedDeductions->sum('amount'), 2),
                    'fixed_earning_lines' => $fixedEarnings->map(fn (array $l) => [
                        'amount' => $l['amount'],
                        'epf_liable' => (bool) $l['item']->epf_liable,
                        'perkeso_liable' => (bool) $l['item']->perkeso_liable,
                    ])->values()->all(),
                    'individual_earnings_total' => round($individualEarnings->sum('amount'), 2),
                    'individual_deductions_total' => round($individualDeductions->sum('amount'), 2),
                    'individual_earning_lines' => $individualEarnings->map(fn (array $l) => [
                        'amount' => $l['amount'],
                        'epf_liable' => (bool) $l['item']->epf_liable,
                        'perkeso_liable' => (bool) $l['item']->perkeso_liable,
                    ])->values()->all(),
                    'claims_reimbursement' => $claims->sum('amount'),
                    // Approved overtime/unpaid-leave populate the draft automatically — see
                    // pullableOvertimeFor()/pullableUnpaidLeaveFor()/overtimeGroups(). One
                    // group per distinct rate_multiplier found (3x public holiday, 1.5x
                    // ordinary, ...) so the calculator multiplies each rate's raw hours
                    // exactly once — never flattened into one ambiguous "equivalent hours"
                    // figure that could be double-multiplied later.
                    'overtime_groups' => $pulledOvertimeGroups,
                    'unpaid_days' => $pulledUnpaidDays,
                    'statutory_category' => $employee->statutoryCategory($periodEnd),
                    'epf_part' => $epfPart,
                    'skbbk_opt_in' => (bool) $structure->skbbk_opt_in,
                ];
                $inputs = $this->withWageBaseFlags($inputs, $catalog);
                $comp = $this->calculator->compute($inputs);

                // PCB: the real LHDN computerised MTD calculation, year-to-date-aware —
                // see buildPcbInputs(). Two-pass like EPF/SOCSO above: compute gross/EPF
                // first, feed those into PCB, then recompute so the deduction flows into net.
                $result = $this->pcb->calculate($this->buildPcbInputs($employee, $data['period'], $comp, $structure, $epfPart));
                $inputs['pcb'] = $result->netNormalMtd;
                $inputs['pcb_additional'] = $result->additionalMtd;
                $inputs['zakat'] = (float) ($structure->zakat_monthly ?? 0);
                $inputs['cp38'] = (float) ($structure->cp38_monthly ?? 0);
                $comp = $this->calculator->compute($inputs);

                // Computed amount columns are excluded from $fillable — forceFill them.
                // employee_id + claim_ids are fillable; payroll_run_id is set by the relation;
                // tenant_id is auto-filled by BelongsToTenant on save.
                $payslip = $run->payslips()->make([
                    'employee_id' => $employee->id,
                    'claim_ids' => $claims->pluck('id')->all() ?: null,
                ]);
                $payslip->forceFill($comp->toPayslipAttributes() + [
                    'overtime_request_ids' => $overtimeRequests->pluck('id')->all() ?: null,
                    'pulled_overtime_hours' => $pulledOvertimeHours,
                    'unpaid_leave_request_ids' => $unpaidLeaveRequests->pluck('id')->all() ?: null,
                    'pulled_unpaid_days' => $pulledUnpaidDays,
                ])->save();
                $this->writePayslipLines($payslip, $comp, $ftLines, $itLines, $catalog);
            }

            $this->recalcTotals($run);
            AuditLog::record('Created payroll run', $run->label.' · '.$employees->count().' payslips');
        });

        $msg = 'Draft payroll run created for '.Carbon::createFromFormat('Y-m', $data['period'])->format('F Y').'.';
        if ($missingDob > 0) {
            $msg .= ' Note: '.$missingDob.' employee(s) have no date of birth and were treated as below 60 (SOCSO Category 1) — set their DOB and recompute to confirm their contribution category.';
        }

        return back()->with('ok', $msg);
    }

    public function updatePayslip(Request $request, Payslip $payslip): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->assertTenant($payslip);
        abort_unless($payslip->payrollRun->isEditable(), 422, 'This payroll run is finalized and locked.');

        $data = $request->validate([
            // Blank/absent = use the auto-pulled figure (approved overtime for this
            // period); a value here overrides it verbatim, exactly like pcb_override.
            // Always RAW hours — the same unit the pulled per-rate lines show — paired
            // with overtime_multiplier below so there is only ever one unit on screen.
            'overtime_hours' => ['nullable', 'numeric', 'min:0', 'max:744'],
            // Not enforced against the Employment Act minimums (1.5x normal day, 2x rest
            // day, 3x public holiday) — the day type lives in attendance, not here, and a
            // company may choose to pay above the minimum. Defaults to 1.5x when omitted.
            'overtime_multiplier' => ['nullable', 'numeric', 'min:1', 'max:10'],
            'bonus' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            // Blank/absent = use the auto-pulled figure (approved unpaid leave for this
            // period); a value here overrides it verbatim.
            'unpaid_days' => ['nullable', 'numeric', 'min:0', 'max:31'],
            // Null/blank = go with the computed PCB; a value here overrides it verbatim
            // and survives future recomputes until cleared (see PayrollCalculator).
            'pcb_override' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            // Individual Transactions: a one-off earning/deduction against a Payroll Item,
            // an amount, and an optional remark — replaces the old free-form add_name/
            // ded_name pairs so every payslip amount traces back to a catalogue item.
            // tx_id/tx_known_ids make the sync id-aware (see syncIndividualTransactions):
            // tx_id is this row's individual_transactions id if it already existed when
            // the form was rendered (blank = a new row); tx_known_ids is every id the
            // form was rendered with, so a row created elsewhere after the page loaded is
            // never touched by this save. Ownership of every id is re-checked server-side
            // (scoped to this employee/period/tenant) rather than trusted from the request.
            'tx_id' => ['array'], 'tx_id.*' => ['nullable', 'integer'],
            'tx_known_ids' => ['array'], 'tx_known_ids.*' => ['nullable', 'integer'],
            'tx_item_id' => ['array'], 'tx_item_id.*' => [
                'nullable',
                Rule::exists('payroll_items', 'id')->where('tenant_id', $payslip->tenant_id)->where('active', true)
                    ->whereNotIn('code', self::FT_FORBIDDEN_ITEM_CODES),
            ],
            'tx_amount' => ['array'], 'tx_amount.*' => ['nullable', 'numeric', 'min:0.01', 'max:10000000'],
            'tx_remark' => ['array'], 'tx_remark.*' => ['nullable', 'string', 'max:255'],
        ]);

        // Basic, allowances/Fixed Transactions and claims reimbursement stay as generated;
        // only variable inputs are editable here. Recompute the full payslip from those.
        $periodEnd = Carbon::createFromFormat('Y-m', $payslip->payrollRun->period)->endOfMonth();
        $age = $payslip->employee->date_of_birth === null ? null : (int) $payslip->employee->date_of_birth->diffInYears($periodEnd);
        $structure = $payslip->employee->salaryStructure;
        // electedBefore1998 has no column — see the same note in createRun(). $structure
        // is genuinely nullable (not every employee has a salary structure row) —
        // Larastan false-positives "nullsafe.neverNull" on ?-> here, so this is written
        // as an explicit null check to sidestep that rather than silence it.
        $epfPart = $this->epf->part(($structure !== null ? $structure->nationality : null) ?? 'citizen', $age, false);

        // overtime_hours/unpaid_days: null or '' (never submitted, or explicitly cleared)
        // means "use the pulled figure"; any other value — including 0 — is HR's override
        // and wins, exactly like pcb_override just above. Locked inside a transaction
        // (lockForUpdate, same as createRun) so two concurrent recomputes across different
        // draft runs can't both pull the same OvertimeRequest/LeaveRequest.
        $rawOvertimeHours = $request->input('overtime_hours');
        $overtimeOverridden = $rawOvertimeHours !== null && $rawOvertimeHours !== '';
        $rawOvertimeMultiplier = $request->input('overtime_multiplier');
        $rawUnpaidDays = $request->input('unpaid_days');
        $unpaidOverridden = $rawUnpaidDays !== null && $rawUnpaidDays !== '';

        $comp = DB::transaction(function () use (
            $request, $data, $payslip, $structure, $epfPart, $periodEnd,
            $overtimeOverridden, $rawOvertimeHours, $rawOvertimeMultiplier, $unpaidOverridden, $rawUnpaidDays,
        ) {
            $overtimeRequests = $this->pullableOvertimeFor(
                $payslip->employee, $payslip->payrollRun->period, $this->usedOvertimeIds($payslip->id)
            );
            $pulledOvertimeGroups = $this->overtimeGroups($overtimeRequests);
            $pulledOvertimeHours = round($overtimeRequests->sum(fn (OvertimeRequest $o) => (float) $o->hours), 2);
            $unpaidLeaveRequests = $this->pullableUnpaidLeaveFor(
                $payslip->employee, $payslip->payrollRun->period, $this->usedUnpaidLeaveIds($payslip->id)
            );
            $pulledUnpaidDays = round($unpaidLeaveRequests->sum(fn (LeaveRequest $l) => (float) $l->days), 2);

            $baseInputs = [
                'basic' => $payslip->basic,
                'allowances_total' => $payslip->allowances_total,
                'fixed_deductions_total' => $payslip->fixed_deductions_total,
                'claims_reimbursement' => $payslip->claims_reimbursement,
                'bonus' => $data['bonus'] ?? 0,
                'statutory_category' => $payslip->employee->statutoryCategory($periodEnd),
                'epf_part' => $epfPart,
                'skbbk_opt_in' => (bool) $structure?->skbbk_opt_in,
            ];

            if ($overtimeOverridden) {
                // HR's own figure: raw hours at HR's chosen multiplier (defaults to the
                // standard 1.5x ordinary rate) — the exact same unit the pulled per-rate
                // lines show, so there is no second "equivalent hours" conversion left
                // to get wrong.
                $baseInputs['overtime_groups'] = [[
                    'hours' => (float) $rawOvertimeHours,
                    'multiplier' => ($rawOvertimeMultiplier !== null && $rawOvertimeMultiplier !== '')
                        ? (float) $rawOvertimeMultiplier : PayrollCalculator::OVERTIME_MULTIPLIER,
                ]];
            } else {
                // Pulled: one group per distinct rate_multiplier found among the approved
                // requests for this period — see overtimeGroups(). Each group's raw hours
                // get multiplied by its own rate exactly once.
                $baseInputs['overtime_groups'] = $pulledOvertimeGroups;
            }
            $baseInputs['unpaid_days'] = $unpaidOverridden ? (float) $rawUnpaidDays : $pulledUnpaidDays;

            $catalog = PayrollItem::where('tenant_id', $payslip->tenant_id)->get()->keyBy('code');

            // Individual Transactions: adding/editing/removing a one-off here writes into
            // the same individual_transactions table the standalone Individual
            // Transactions screen uses (syncIndividualTransactions) — never a parallel
            // mechanism — then reads back from that same table
            // (individualTransactionLinesForPeriod), the identical path createRun() uses.
            // tx_known_ids must actually be present on the request — its absence means
            // this isn't a genuine render of the edit form (a partial/hand-made request),
            // so the sync is skipped entirely rather than reading a missing array as "the
            // form knew about nothing" and deleting every row for this employee/period.
            if ($request->has('tx_known_ids')) {
                $this->syncIndividualTransactions(
                    $payslip->employee, $payslip->payrollRun->period,
                    $request->input('tx_id', []), $request->input('tx_item_id', []),
                    $request->input('tx_amount', []), $request->input('tx_remark', []),
                    $request->input('tx_known_ids', []),
                );
            }
            $individualLines = $this->individualTransactionLinesForPeriod($payslip->employee, $payslip->payrollRun->period);
            $individualEarnings = $individualLines->filter(fn (array $l) => $l['item']->type === 'earning');
            $individualDeductions = $individualLines->filter(fn (array $l) => $l['item']->type === 'deduction');
            $baseInputs['individual_earnings_total'] = round($individualEarnings->sum('amount'), 2);
            $baseInputs['individual_deductions_total'] = round($individualDeductions->sum('amount'), 2);
            $baseInputs['individual_earning_lines'] = $individualEarnings->map(fn (array $l) => [
                'amount' => $l['amount'],
                'epf_liable' => (bool) $l['item']->epf_liable,
                'perkeso_liable' => (bool) $l['item']->perkeso_liable,
            ])->values()->all();

            // Re-derive each Fixed Transaction earning's own wage-base flags from the lines
            // frozen at run generation, so editing bonus/overtime here doesn't silently
            // re-base a non-EPF-liable Fixed Transaction (e.g. travel allowance) as EPF-liable.
            // A payslip with no fixed-transaction-sourced lines (pre-Fixed-Transaction, or an
            // employee with none) falls through to withWageBaseFlags' single lumped
            // Fixed Allowance fallback against allowances_total.
            $fixedEarningLines = $payslip->lines()->where('source', 'fixed-transaction')->where('type', 'earning')->get();
            if ($fixedEarningLines->isNotEmpty()) {
                // A legacy line, or one whose catalogue item has since been deleted, has no
                // payrollItem — Larastan false-positives "nullsafe.neverNull" on the ?->
                // below even though the relation is genuinely nullable, so this is written
                // as an explicit null check to sidestep that rather than silence it.
                $baseInputs['fixed_earning_lines'] = $fixedEarningLines->map(fn (PayslipLine $l) => [
                    'amount' => (float) $l->amount,
                    'epf_liable' => (bool) (($l->payrollItem !== null ? $l->payrollItem->epf_liable : null) ?? true),
                    'perkeso_liable' => (bool) (($l->payrollItem !== null ? $l->payrollItem->perkeso_liable : null) ?? true),
                ])->all();
            }

            $baseInputs = $this->withWageBaseFlags($baseInputs, $catalog);

            // First pass: gross + EPF, needed to split normal vs. additional remuneration
            // for PCB. Second pass: feed the computed PCB back in so it flows into net.
            $comp = $this->calculator->compute($baseInputs);
            $result = $this->pcb->calculate($this->buildPcbInputs($payslip->employee, $payslip->payrollRun->period, $comp, $structure, $epfPart));
            $comp = $this->calculator->compute($baseInputs + [
                'pcb' => $result->netNormalMtd,
                'pcb_additional' => $result->additionalMtd,
                'zakat' => (float) ($structure->zakat_monthly ?? 0),
                'cp38' => (float) ($structure->cp38_monthly ?? 0),
                'pcb_override' => $data['pcb_override'] ?? null,
            ]);

            // toPayslipAttributes() deliberately omits claim_ids, so the reimbursement
            // linkage set at run creation survives edits. Amount columns are excluded from
            // $fillable — forceFill them. overtime_request_ids/unpaid_leave_request_ids are
            // refreshed every recompute (not frozen like claim_ids) — reserving whatever is
            // currently eligible and releasing anything no longer eligible (e.g. an OT
            // request that got rejected after the last save) back to the pool, whether or
            // not HR's own override is what actually drives the amount used above.
            $payslip->forceFill($comp->toPayslipAttributes() + [
                'overtime_request_ids' => $overtimeRequests->pluck('id')->all() ?: null,
                'pulled_overtime_hours' => $pulledOvertimeHours,
                'overtime_overridden' => $overtimeOverridden,
                'unpaid_leave_request_ids' => $unpaidLeaveRequests->pluck('id')->all() ?: null,
                'pulled_unpaid_days' => $pulledUnpaidDays,
                'unpaid_days_overridden' => $unpaidOverridden,
            ])->save();
            $this->refreshVariableLines($payslip, $comp, $individualLines, $catalog);

            return $comp;
        });

        $this->recalcTotals($payslip->payrollRun);
        AuditLog::record('Updated payslip', $payslip->employee->name.' · '.$payslip->payrollRun->label);

        return back()->with('ok', 'Payslip updated for '.$payslip->employee->name.' (net RM '.number_format($comp->netPay, 2).').');
    }

    public function approveRun(Request $request, PayrollRun $run): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->assertTenant($run);
        abort_unless($run->status === 'draft', 422);

        // status is excluded from $fillable (lifecycle column) — set it directly.
        $run->forceFill(['status' => 'approved', 'approved_by_id' => Auth::id()])->save();
        AuditLog::record('Approved payroll run', $run->label);

        return back()->with('ok', $run->label.' payroll approved. Finalize to issue payslips.');
    }

    public function finalizeRun(Request $request, PayrollRun $run): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->assertTenant($run);

        // Four-eyes control: when enabled, a run must be approved before it can be
        // finalized. Off (default) keeps the single-operator draft→finalized shortcut.
        if (app(FeatureManager::class)->enabled(app(CurrentTenant::class)->get(), 'payroll.four_eyes')) {
            abort_unless($run->status === 'approved', 422, 'This run must be approved before it can be finalized.');
        } else {
            abort_unless(in_array($run->status, ['draft', 'approved'], true), 422);
        }

        DB::transaction(function () use ($run) {
            // status + finalized_at are excluded from $fillable — set them directly.
            $run->forceFill([
                'status' => 'finalized',
                'finalized_at' => now(),
                'approved_by_id' => $run->approved_by_id ?? Auth::id(),
            ])->save();

            $payslips = $run->payslips()->with('employee')->get();

            // Mark every approved claim that was reimbursed in this run as paid.
            $claimIds = $payslips->flatMap(fn ($p) => $p->claim_ids ?? [])->unique()->values();
            if ($claimIds->isNotEmpty()) {
                Claim::whereIn('id', $claimIds)->where('status', 'approved')
                    ->update(['status' => 'paid', 'paid_at' => now()]);
            }

            // Same for the overtime and unpaid-leave requests this run pulled in — paid_at
            // both closes the loop on the source request and (via pullableOvertimeFor's/
            // pullableUnpaidLeaveFor's whereNull('paid_at')) keeps it out of every future
            // run's pool for good. Marked whether or not HR overrode the figure used —
            // the requests were still consumed by this payslip either way.
            $overtimeIds = $payslips->flatMap(fn ($p) => $p->overtime_request_ids ?? [])->unique()->values();
            if ($overtimeIds->isNotEmpty()) {
                OvertimeRequest::whereIn('id', $overtimeIds)->update(['paid_at' => now()]);
            }
            $unpaidLeaveIds = $payslips->flatMap(fn ($p) => $p->unpaid_leave_request_ids ?? [])->unique()->values();
            if ($unpaidLeaveIds->isNotEmpty()) {
                LeaveRequest::whereIn('id', $unpaidLeaveIds)->update(['paid_at' => now()]);
            }

            // Notify each employee that their payslip is ready.
            foreach ($payslips as $payslip) {
                AppNotification::send(
                    $payslip->employee->user_id,
                    'Payslip ready',
                    'Your '.$run->label.' payslip is available · net RM '.number_format($payslip->net_pay, 2),
                    route('app.screen', 'payroll'),
                );
            }

            AuditLog::record('Finalized payroll run', $run->label.' · '.$payslips->count().' payslips issued');
        });

        return back()->with('ok', $run->label.' payroll finalized — payslips issued and employees notified.');
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function authorizeAdmin(Request $request): void
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
    }

    /**
     * Assemble one month's PcbInputs for an employee from their statutory profile
     * (marital status from the Employee record — the single source since the reconcile
     * migration 2026_08_25_200300; everything else payroll-specific from SalaryStructure),
     * the year-to-date figures (PcbYearToDate), and this month's computed gross/EPF
     * ($comp, from a first PayrollCalculator pass with no PCB yet).
     *
     * $comp->bonus is treated as the spec's "additional remuneration" (Yt); everything
     * else in gross is "normal remuneration" (Y1). The EPF attributable to the bonus
     * (Kt) is the difference between EPF on the full month's pay and EPF on the pay
     * excluding the bonus — EpfCalculator gives both.
     */
    private function buildPcbInputs(Employee $employee, string $period, PayslipComputation $comp, ?SalaryStructure $structure, ?string $epfPart): PcbInputs
    {
        // Category derivation lives on PcbCalculator (also reused by Cp8dData for the
        // C.P.8D text file's "Category of employee" field) — never re-derive it here.
        $category = PcbCalculator::categoryFor($employee, $structure);

        // $epfPart is the same Part letter the caller's first PayrollCalculator pass
        // used for $comp — passed in rather than re-derived so it can't drift from the
        // employee's real age/nationality (re-deriving with a null age would silently
        // assume under-60 Part A and misstate a 60+ citizen's EPF relief).
        $bonus = $comp->bonus;
        $epfWageExclBonus = max(0.0, round($comp->gross - $bonus - $comp->overtimeAmount, 2));
        $k1 = $this->epf->contribution($epfWageExclBonus, $epfPart)['employee'];
        $kt = max(0.0, round($comp->epfEmployee - $k1, 2));

        $ytd = $this->pcbYtd->forPeriod($employee, $period);
        $n = 12 - (int) substr($period, 5, 2);

        return new PcbInputs(
            category: $category,
            // ponytail: residence for Malaysian tax is about days physically present in
            // the country (and a 182-day-or-more foreign contract counts as resident),
            // not nationality/passport — there's no "days in Malaysia" column on
            // SalaryStructure to derive this faithfully. Defaulting every employee to
            // resident (the common case, and the side that under-withholds rather than
            // over-withholds a genuine resident) until that data exists; do not flip
            // this to the 30% non-resident path off `nationality` alone.
            isResident: true,
            ytdGrossY: $ytd['grossY'],
            ytdEpfK: $ytd['epfK'],
            currentGrossY1: round($comp->gross - $bonus, 2),
            currentEpfK1: $k1,
            monthsRemainingAfterCurrent: $n,
            ytdZakatZ: $ytd['zakatZ'],
            ytdMtdPaidX: $ytd['mtdPaidX'],
            // $structure is genuinely nullable — Larastan false-positives
            // "nullsafe.neverNull" on ?-> below, so these are written as explicit null
            // checks to sidestep that rather than silence it.
            currentZakat: (float) (($structure !== null ? $structure->zakat_monthly : null) ?? 0),
            disabledIndividual: (bool) (($structure !== null ? $structure->disabled_self : null) ?? false),
            // No spouse relief at all for category 1 (single) — see PcbCalculator::reliefs().
            disabledSpouse: $category !== 1 && (bool) (($structure !== null ? $structure->disabled_spouse : null) ?? false),
            qualifyingChildren: (int) (($structure !== null ? $structure->children_relief_count : null) ?? 0),
            ytdOptionalDeductions: $ytd['optionalDeductions'],
            currentAdditionalGrossYt: $bonus,
            currentAdditionalEpfKt: $kt,
        );
    }

    /** Route-model binding resolves before the tenant scope is active — assert explicitly. */
    private function assertTenant(Payslip|PayrollRun $model): void
    {
        abort_unless($model->tenant_id === app(CurrentTenant::class)->id(), 403);
    }

    private function recalcTotals(PayrollRun $run): void
    {
        $payslips = $run->payslips()->get();
        // totals is a computed cache column excluded from $fillable — set it directly.
        $run->forceFill(['totals' => [
            'headcount' => $payslips->count(),
            'gross' => round((float) $payslips->sum('gross'), 2),
            'deductions' => round((float) $payslips->sum('total_deductions'), 2),
            'net' => round((float) $payslips->sum('net_pay'), 2),
            'employer_cost' => round((float) $payslips->sum('employer_cost'), 2),
        ]])->save();
    }

    // ── Payroll item catalogue ──────────────────────────────────────

    /**
     * Merge PayrollCalculator's flag-derived-wage-base inputs onto $inputs: one entry per
     * basic/allowance-total/bonus/addition line (amounts already known), plus overtime's
     * flags separately (its amount is only known once the calculator computes it). Every
     * tenant is seeded with the catalogue (PayrollItem::seedFor), but this still tolerates
     * a missing item — falling back to PayrollItem::SYSTEM_ITEMS, the one definition of
     * the statutory flags, rather than crashing.
     */
    private function withWageBaseFlags(array $inputs, Collection $catalog): array
    {
        $flagsFor = function (string $code) use ($catalog): array {
            $item = $catalog->get($code);
            [, , , $defaultEpf, $defaultPerkeso] = PayrollItem::SYSTEM_ITEMS[$code];

            return [
                'epf_liable' => $item ? (bool) $item->epf_liable : $defaultEpf,
                'perkeso_liable' => $item ? (bool) $item->perkeso_liable : $defaultPerkeso,
            ];
        };

        $lines = [
            ['amount' => $inputs['basic'] ?? 0, ...$flagsFor('basic-salary')],
            ['amount' => $inputs['bonus'] ?? 0, ...$flagsFor('bonus')],
        ];
        if (isset($inputs['fixed_earning_lines'])) {
            // Per-Fixed-Transaction-item flags (createRun, or updatePayslip when the
            // payslip already has fixed-transaction-sourced lines) — each transaction's
            // own Payroll Item drives the wage base, not one lumped Fixed Allowance flag.
            array_push($lines, ...$inputs['fixed_earning_lines']);
        } else {
            // Fallback for payslips predating Fixed Transactions (or an edit where the
            // payslip has none): the single lumped Fixed Allowance flag against
            // allowances_total — the exact pre-Fixed-Transaction behaviour.
            $lines[] = ['amount' => $inputs['allowances_total'] ?? 0, ...$flagsFor('fixed-allowance')];
        }
        $additionFlags = $flagsFor('other-addition');
        foreach (($inputs['additions'] ?? []) as $addition) {
            $lines[] = ['amount' => $addition['amount'] ?? 0, ...$additionFlags];
        }

        $inputs['lines'] = $lines;
        $inputs['overtime_flags'] = $flagsFor('overtime');

        return $inputs;
    }

    /** Attributes for one PayslipLine row; payroll_item_id is null if the tenant has no matching catalogue item. */
    private function lineAttrs(Collection $catalog, string $code, string $name, string $type, float $amount, ?float $quantity, string $source, int $sortOrder): array
    {
        return [
            'payroll_item_id' => $catalog->get($code)?->id,
            'name' => $name,
            'type' => $type,
            'amount' => round($amount, 2),
            'quantity' => $quantity,
            'source' => $source,
            'sort_order' => $sortOrder,
        ];
    }

    /** Same as lineAttrs(), but for a Fixed/Individual Transaction whose Payroll Item is already resolved. */
    private function lineAttrsForItem(PayrollItem $item, float $amount, string $source, int $sortOrder, ?string $remark = null): array
    {
        return [
            'payroll_item_id' => $item->id,
            'name' => $item->name,
            'type' => $item->type,
            'amount' => round($amount, 2),
            'quantity' => null,
            'source' => $source,
            'remark' => $remark,
            'sort_order' => $sortOrder,
        ];
    }

    /**
     * Attributes for this payslip's Individual Transaction lines — one per resolved
     * {item, amount, remark} row (individualTransactionLinesForPeriod()), source 'individual'.
     *
     * Typed as iterable, not Collection, purely to sidestep Collection's TValue being
     * invariant — passing the Collection individualTransactionLinesForPeriod() returns into a
     * Collection-typed param here fails PHPStan's invariance check even though the
     * array shape is identical; iterable doesn't have that restriction and this
     * function only ever foreach()es the argument.
     *
     * @param  iterable<int, array{item: PayrollItem, amount: float, remark: ?string}>  $individualLines
     */
    private function individualLineAttrs(iterable $individualLines, int $sort): array
    {
        $lines = [];
        foreach ($individualLines as $line) {
            $lines[] = $this->lineAttrsForItem($line['item'], $line['amount'], 'individual', $sort++, $line['remark']);
        }

        return $lines;
    }

    /**
     * The variable lines a payslip edit can change: overtime, bonus, free-form additions,
     * the unpaid-leave deduction, and free-form other-deductions. Zero-amount lines are
     * skipped, matching the existing "additions"/"other_deductions" convention of dropping
     * blank rows.
     *
     * Overtime writes ONE line per rate group ($comp->overtimeGroups) — "Overtime 1.5×"
     * and "Overtime 3×" as separate lines, each with its own raw hours as quantity and
     * its own amount — rather than one lumped figure. That per-rate breakdown is exactly
     * what an employee needs to check their own payslip, and it is what keeps the pulled
     * figure and the override field in the same unit (see the report this fixes).
     */
    private function variableLineAttrs(PayslipComputation $comp, Collection $catalog, int $sort): array
    {
        $lines = [];
        foreach ($comp->overtimeGroups as $group) {
            $mult = rtrim(rtrim(number_format($group['multiplier'], 2), '0'), '.');
            $lines[] = $this->lineAttrs($catalog, 'overtime', "Overtime {$mult}×", 'earning', $group['amount'], $group['hours'], 'overtime', $sort++);
        }
        if ($comp->bonus > 0) {
            $lines[] = $this->lineAttrs($catalog, 'bonus', 'Bonus', 'earning', $comp->bonus, null, 'manual', $sort++);
        }
        foreach ($comp->additions as $addition) {
            $lines[] = $this->lineAttrs($catalog, 'other-addition', $addition['name'], 'earning', $addition['amount'], null, 'manual', $sort++);
        }
        if ($comp->unpaidDeduction > 0) {
            $lines[] = $this->lineAttrs($catalog, 'unpaid-leave-deduction', 'Unpaid Leave Deduction', 'deduction', $comp->unpaidDeduction, $comp->unpaidDays, 'leave', $sort++);
        }
        foreach ($comp->otherDeductions as $deduction) {
            $lines[] = $this->lineAttrs($catalog, 'other-deduction', $deduction['name'], 'deduction', $deduction['amount'], null, 'manual', $sort++);
        }

        return $lines;
    }

    /**
     * Full itemisation at run creation: salary/Fixed Transaction/claim lines (fixed for
     * this payslip's life) + the variable ones. $ftLines is this employee's resolved
     * Fixed Transactions for the period — see fixedTransactionLines() — each written
     * against its own Payroll Item, earning or deduction, source 'fixed-transaction' so
     * a later payslip edit (refreshVariableLines) never touches or deletes it.
     */
    private function writePayslipLines(Payslip $payslip, PayslipComputation $comp, Collection $ftLines, Collection $itLines, Collection $catalog): void
    {
        $sort = 0;
        $lines = [
            $this->lineAttrs($catalog, 'basic-salary', 'Basic Salary', 'earning', $comp->basic, null, 'salary', $sort++),
        ];
        foreach ($ftLines as $ftLine) {
            $lines[] = $this->lineAttrsForItem($ftLine['item'], $ftLine['amount'], 'fixed-transaction', $sort++);
        }
        if ($comp->claimsReimbursement > 0) {
            $lines[] = $this->lineAttrs($catalog, 'claim-reimbursement', 'Claim Reimbursement', 'earning', $comp->claimsReimbursement, null, 'claim', $sort++);
        }
        $variableLines = $this->variableLineAttrs($comp, $catalog, $sort);
        $lines = array_merge($lines, $variableLines, $this->individualLineAttrs($itLines, $sort + count($variableLines)));

        $payslip->lines()->createMany($lines);
    }

    /**
     * A payslip edit only ever touches overtime/bonus/additions/unpaid-leave/other-
     * deductions/Individual Transactions — basic, allowances and the claim reimbursement
     * "stay as generated" (see updatePayslip's comment). Rebuilding those from the live
     * SalaryStructure here would drift from the payslip's own stored figures if HR edited
     * the structure after the run was created, so only the variable-source lines are
     * replaced.
     *
     * Typed as iterable, not Collection, for the same TValue-invariance reason noted on
     * individualLineAttrs() — this only ever passes the argument through to that method.
     *
     * @param  iterable<int, array{item: PayrollItem, amount: float, remark: ?string}>  $individualLines
     */
    private function refreshVariableLines(Payslip $payslip, PayslipComputation $comp, iterable $individualLines, Collection $catalog): void
    {
        $payslip->lines()->whereIn('source', ['overtime', 'manual', 'leave', 'individual'])->delete();
        $nextSort = (int) ($payslip->lines()->max('sort_order') ?? -1) + 1;
        $variableLines = $this->variableLineAttrs($comp, $catalog, $nextSort);
        $lines = array_merge($variableLines, $this->individualLineAttrs($individualLines, $nextSort + count($variableLines)));
        $payslip->lines()->createMany($lines);
    }

    public function updateItem(Request $request, PayrollItem $item): RedirectResponse
    {
        $this->authorizeAdmin($request);
        abort_unless($item->tenant_id === app(CurrentTenant::class)->id(), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'name_ms' => ['nullable', 'string', 'max:80'],
            'epf_liable' => ['boolean'],
            'perkeso_liable' => ['boolean'],
            'pcb_taxable' => ['boolean'],
            'active' => ['boolean'],
        ]);

        $item->update([
            'name' => $data['name'],
            'name_ms' => $data['name_ms'] ?? null,
            'epf_liable' => $request->boolean('epf_liable'),
            'perkeso_liable' => $request->boolean('perkeso_liable'),
            'pcb_taxable' => $request->boolean('pcb_taxable'),
            'active' => $request->boolean('active'),
        ]);

        AuditLog::record('Updated payroll item', $item->name);

        return back()->with('ok', 'Payroll item "'.$item->name.'" saved.');
    }

    public function destroyItem(Request $request, PayrollItem $item): RedirectResponse
    {
        $this->authorizeAdmin($request);
        abort_unless($item->tenant_id === app(CurrentTenant::class)->id(), 403);
        abort_if($item->is_system, 422, 'System payroll items cannot be deleted.');

        $item->delete();
        AuditLog::record('Deleted payroll item', $item->name);

        return back()->with('ok', 'Payroll item "'.$item->name.'" deleted.');
    }
}
