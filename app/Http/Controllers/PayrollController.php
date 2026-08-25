<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Claim;
use App\Models\Employee;
use App\Models\FixedTransaction;
use App\Models\OffboardingCase;
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
     * Payroll Item codes a Fixed Transaction must never target — each already has its own
     * automatic source (basic salary from the salary structure, overtime/unpaid-leave from
     * payslip edits, claim reimbursement from approved claims). Allowing a Fixed
     * Transaction against one of these would double up or fight with that automatic line.
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
            $usedClaimIds = Payslip::whereNotNull('claim_ids')->get(['claim_ids'])
                ->pluck('claim_ids')->flatten()->filter()->unique()->all();

            foreach ($employees as $employee) {
                $structure = $employee->salaryStructure;
                $claims = $employee->claims()
                    ->where('status', 'approved')->whereNull('paid_at')
                    ->whereNotIn('id', $usedClaimIds)
                    ->lockForUpdate()->get();

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

                $inputs = [
                    'basic' => $structure->basic_salary,
                    'allowances_total' => round($fixedEarnings->sum('amount'), 2),
                    'fixed_deductions_total' => round($fixedDeductions->sum('amount'), 2),
                    'fixed_earning_lines' => $fixedEarnings->map(fn (array $l) => [
                        'amount' => $l['amount'],
                        'epf_liable' => (bool) $l['item']->epf_liable,
                        'perkeso_liable' => (bool) $l['item']->perkeso_liable,
                    ])->values()->all(),
                    'claims_reimbursement' => $claims->sum('amount'),
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
                $payslip->forceFill($comp->toPayslipAttributes())->save();
                $this->writePayslipLines($payslip, $comp, $ftLines, $catalog);
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
            'overtime_hours' => ['nullable', 'numeric', 'min:0', 'max:744'],
            'bonus' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'unpaid_days' => ['nullable', 'numeric', 'min:0', 'max:31'],
            // Null/blank = go with the computed PCB; a value here overrides it verbatim
            // and survives future recomputes until cleared (see PayrollCalculator).
            'pcb_override' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'add_name' => ['array'], 'add_name.*' => ['nullable', 'string', 'max:60'],
            'add_amount' => ['array'], 'add_amount.*' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'ded_name' => ['array'], 'ded_name.*' => ['nullable', 'string', 'max:60'],
            'ded_amount' => ['array'], 'ded_amount.*' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
        ]);

        // Basic, allowances/Fixed Transactions and claims reimbursement stay as generated;
        // only variable inputs are editable here. Recompute the full payslip from those.
        $periodEnd = Carbon::createFromFormat('Y-m', $payslip->payrollRun->period)->endOfMonth();
        $age = $payslip->employee->date_of_birth === null ? null : (int) $payslip->employee->date_of_birth->diffInYears($periodEnd);
        $structure = $payslip->employee->salaryStructure;
        // electedBefore1998 has no column — see the same note in createRun().
        $epfPart = $this->epf->part($structure?->nationality ?? 'citizen', $age, false);
        $baseInputs = [
            'basic' => $payslip->basic,
            'allowances_total' => $payslip->allowances_total,
            'fixed_deductions_total' => $payslip->fixed_deductions_total,
            'claims_reimbursement' => $payslip->claims_reimbursement,
            'overtime_hours' => $data['overtime_hours'] ?? 0,
            'bonus' => $data['bonus'] ?? 0,
            'unpaid_days' => $data['unpaid_days'] ?? 0,
            'additions' => $this->zipLines($request->input('add_name', []), $request->input('add_amount', [])),
            'other_deductions' => $this->zipLines($request->input('ded_name', []), $request->input('ded_amount', [])),
            'statutory_category' => $payslip->employee->statutoryCategory($periodEnd),
            'epf_part' => $epfPart,
            'skbbk_opt_in' => (bool) $structure?->skbbk_opt_in,
        ];

        // Re-derive each Fixed Transaction earning's own wage-base flags from the lines
        // frozen at run generation, so editing bonus/overtime here doesn't silently
        // re-base a non-EPF-liable Fixed Transaction (e.g. travel allowance) as EPF-liable.
        // A payslip with no fixed-transaction-sourced lines (pre-Fixed-Transaction, or an
        // employee with none) falls through to withWageBaseFlags' single lumped
        // Fixed Allowance fallback against allowances_total.
        $fixedEarningLines = $payslip->lines()->where('source', 'fixed-transaction')->where('type', 'earning')->get();
        if ($fixedEarningLines->isNotEmpty()) {
            $baseInputs['fixed_earning_lines'] = $fixedEarningLines->map(fn (PayslipLine $l) => [
                'amount' => (float) $l->amount,
                'epf_liable' => (bool) ($l->payrollItem?->epf_liable ?? true),
                'perkeso_liable' => (bool) ($l->payrollItem?->perkeso_liable ?? true),
            ])->all();
        }

        $catalog = PayrollItem::where('tenant_id', $payslip->tenant_id)->get()->keyBy('code');
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

        // toPayslipAttributes() deliberately omits claim_ids, so the reimbursement linkage
        // set at run creation survives edits. Amount columns are excluded from $fillable —
        // forceFill them.
        $payslip->forceFill($comp->toPayslipAttributes())->save();
        $this->refreshVariableLines($payslip, $comp, $catalog);
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
        // Category (spec's own definitions): 1 = single; 2 = married, spouse not
        // working; 3 = married with a working spouse, divorced, or widowed.
        // spouse_working is genuine payroll-specific nuance (not a personal-status fact —
        // it affects tax relief, not who the employee is), so it stays on SalaryStructure.
        $category = match (true) {
            ($employee->marital_status ?? 'single') === 'married' && ! ($structure?->spouse_working ?? false) => 2,
            ($employee->marital_status ?? 'single') === 'married',
            ($employee->marital_status ?? 'single') === 'divorced',
            ($employee->marital_status ?? 'single') === 'widowed' => 3,
            default => 1,
        };

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
            currentZakat: (float) ($structure?->zakat_monthly ?? 0),
            disabledIndividual: (bool) ($structure?->disabled_self ?? false),
            // No spouse relief at all for category 1 (single) — see PcbCalculator::reliefs().
            disabledSpouse: $category !== 1 && (bool) ($structure?->disabled_spouse ?? false),
            qualifyingChildren: (int) ($structure?->children_relief_count ?? 0),
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

    /**
     * Zip parallel name[]/amount[] form arrays into [{name, amount}] line items,
     * dropping blank rows.
     *
     * @return array<int, array{name: string, amount: float}>
     */
    private function zipLines(array $names, array $amounts): array
    {
        $lines = [];
        foreach ($names as $i => $name) {
            $name = trim((string) $name);
            $amount = (float) ($amounts[$i] ?? 0);
            if ($name === '' || $amount <= 0) {
                continue;
            }
            $lines[] = ['name' => $name, 'amount' => round($amount, 2)];
        }

        return $lines;
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

    /** Same as lineAttrs(), but for a Fixed Transaction whose Payroll Item is already resolved. */
    private function lineAttrsForItem(PayrollItem $item, float $amount, string $source, int $sortOrder): array
    {
        return [
            'payroll_item_id' => $item->id,
            'name' => $item->name,
            'type' => $item->type,
            'amount' => round($amount, 2),
            'quantity' => null,
            'source' => $source,
            'sort_order' => $sortOrder,
        ];
    }

    /**
     * The variable lines a payslip edit can change: overtime, bonus, free-form additions,
     * the unpaid-leave deduction, and free-form other-deductions. Zero-amount lines are
     * skipped, matching the existing "additions"/"other_deductions" convention of dropping
     * blank rows.
     */
    private function variableLineAttrs(PayslipComputation $comp, Collection $catalog, int $sort): array
    {
        $lines = [];
        if ($comp->overtimeAmount > 0) {
            $lines[] = $this->lineAttrs($catalog, 'overtime', 'Overtime', 'earning', $comp->overtimeAmount, $comp->overtimeHours, 'overtime', $sort++);
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
    private function writePayslipLines(Payslip $payslip, PayslipComputation $comp, Collection $ftLines, Collection $catalog): void
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
        $lines = array_merge($lines, $this->variableLineAttrs($comp, $catalog, $sort));

        $payslip->lines()->createMany($lines);
    }

    /**
     * A payslip edit only ever touches overtime/bonus/additions/unpaid-leave/other-
     * deductions — basic, allowances and the claim reimbursement "stay as generated"
     * (see updatePayslip's comment). Rebuilding those from the live SalaryStructure here
     * would drift from the payslip's own stored figures if HR edited the structure after
     * the run was created, so only the variable-source lines are replaced.
     */
    private function refreshVariableLines(Payslip $payslip, PayslipComputation $comp, Collection $catalog): void
    {
        $payslip->lines()->whereIn('source', ['overtime', 'manual', 'leave'])->delete();
        $nextSort = (int) ($payslip->lines()->max('sort_order') ?? -1) + 1;
        $payslip->lines()->createMany($this->variableLineAttrs($comp, $catalog, $nextSort));
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
