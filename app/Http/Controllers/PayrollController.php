<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Claim;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\StatutoryRate;
use App\Services\FeatureManager;
use App\Services\Payroll\PayrollCalculator;
use App\Services\Payroll\PcbCalculator;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PayrollController extends Controller
{
    /** Payroll administration is restricted to senior management + HR. */
    private const ADMIN_ROLES = ['management', 'hr'];

    public function __construct(
        private readonly PayrollCalculator $calculator,
        private readonly PcbCalculator $pcb,
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
            'nric' => ['nullable', 'string', 'max:20'],
            'alw_name' => ['array'],
            'alw_name.*' => ['nullable', 'string', 'max:60'],
            'alw_amount' => ['array'],
            'alw_amount.*' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
        ]);

        SalaryStructure::updateOrCreate(
            ['tenant_id' => $tid, 'employee_id' => $data['employee_id']],
            [
                'basic_salary' => $data['basic_salary'],
                'allowances' => $this->zipLines($request->input('alw_name', []), $request->input('alw_amount', [])) ?: null,
                'effective_from' => $data['effective_from'] ?? now()->toDateString(),
                'bank_name' => $data['bank_name'] ?? null,
                'bank_account_no' => $data['bank_account_no'] ?? null,
                'epf_no' => $data['epf_no'] ?? null,
                'socso_no' => $data['socso_no'] ?? null,
                'nric' => $data['nric'] ?? null,
            ],
        );

        $name = Employee::find($data['employee_id'])?->name;
        AuditLog::record('Updated salary structure', $name.' · basic RM '.number_format((float) $data['basic_salary'], 2));

        return back()->with('ok', 'Salary structure saved for '.$name.'.');
    }

    // ── Statutory rate config ─────────────────────────────────────

    public function updateRates(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $tid = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'epf_employee_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'epf_employer_pct_below' => ['required', 'numeric', 'min:0', 'max:100'],
            'epf_employer_pct_above' => ['required', 'numeric', 'min:0', 'max:100'],
            'epf_threshold' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'socso_employer_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'socso_employee_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'socso_ceiling' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'eis_employer_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'eis_employee_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'eis_ceiling' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'pcb_individual_relief' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'pcb_epf_relief_cap' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ]);

        $configs = [
            'epf' => [
                'employee_pct' => (float) $data['epf_employee_pct'],
                'employer_pct_below' => (float) $data['epf_employer_pct_below'],
                'employer_pct_above' => (float) $data['epf_employer_pct_above'],
                'threshold' => (float) $data['epf_threshold'],
            ],
            'socso' => [
                'employer_pct' => (float) $data['socso_employer_pct'],
                'employee_pct' => (float) $data['socso_employee_pct'],
                'wage_ceiling' => (float) $data['socso_ceiling'],
            ],
            'eis' => [
                'employer_pct' => (float) $data['eis_employer_pct'],
                'employee_pct' => (float) $data['eis_employee_pct'],
                'wage_ceiling' => (float) $data['eis_ceiling'],
            ],
            'pcb' => [
                'auto' => $request->boolean('pcb_auto'),
                'individual_relief' => (float) ($data['pcb_individual_relief'] ?? 9000),
                'epf_relief_cap' => (float) ($data['pcb_epf_relief_cap'] ?? 4000),
            ],
        ];

        foreach ($configs as $type => $config) {
            StatutoryRate::updateOrCreate(
                ['tenant_id' => $tid, 'type' => $type],
                ['config' => $config, 'label' => strtoupper($type)],
            );
        }

        AuditLog::record('Updated statutory rates', 'EPF / SOCSO / EIS');

        return back()->with('ok', 'Statutory rates updated. Verify against official KWSP/PERKESO tables before a real run.');
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

        $rates = $this->ratesWithFeatures();
        // Contribution category is assessed at the pay period's end.
        $periodEnd = Carbon::createFromFormat('Y-m', $data['period'])->endOfMonth();
        $missingDob = $employees->whereNull('date_of_birth')->count();

        DB::transaction(function () use ($data, $employees, $rates, $periodEnd) {
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

                $inputs = [
                    'basic' => $structure->basic_salary,
                    'allowances_total' => $structure->allowancesTotal(),
                    'claims_reimbursement' => $claims->sum('amount'),
                    'statutory_category' => $employee->statutoryCategory($periodEnd),
                ];
                $comp = $this->calculator->compute($inputs, $rates);

                // Auto-PCB (I-016, OFF by default): estimate from the computed gross + EPF,
                // then recompute so the deduction flows into net. Manual edits still win later.
                if (! empty($rates['pcb']['auto'])) {
                    $relief = $this->pcb->standardAnnualRelief(
                        $comp->epfEmployee,
                        (float) ($rates['pcb']['individual_relief'] ?? PcbCalculator::DEFAULT_INDIVIDUAL_RELIEF),
                        (float) ($rates['pcb']['epf_relief_cap'] ?? PcbCalculator::DEFAULT_EPF_RELIEF_CAP),
                    );
                    $inputs['pcb'] = $this->pcb->monthlyEstimate($comp->gross, $relief);
                    $comp = $this->calculator->compute($inputs, $rates);
                }

                // Computed amount columns are excluded from $fillable — forceFill them.
                // employee_id + claim_ids are fillable; payroll_run_id is set by the relation;
                // tenant_id is auto-filled by BelongsToTenant on save.
                $payslip = $run->payslips()->make([
                    'employee_id' => $employee->id,
                    'claim_ids' => $claims->pluck('id')->all() ?: null,
                ]);
                $payslip->forceFill($comp->toPayslipAttributes())->save();
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
            'pcb' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'add_name' => ['array'], 'add_name.*' => ['nullable', 'string', 'max:60'],
            'add_amount' => ['array'], 'add_amount.*' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'ded_name' => ['array'], 'ded_name.*' => ['nullable', 'string', 'max:60'],
            'ded_amount' => ['array'], 'ded_amount.*' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
        ]);

        // Basic, allowances and claims reimbursement stay as generated; only variable
        // inputs are editable here. Recompute the full payslip from those.
        $periodEnd = Carbon::createFromFormat('Y-m', $payslip->payrollRun->period)->endOfMonth();
        $comp = $this->calculator->compute([
            'basic' => $payslip->basic,
            'allowances_total' => $payslip->allowances_total,
            'claims_reimbursement' => $payslip->claims_reimbursement,
            'overtime_hours' => $data['overtime_hours'] ?? 0,
            'bonus' => $data['bonus'] ?? 0,
            'unpaid_days' => $data['unpaid_days'] ?? 0,
            'pcb' => $data['pcb'] ?? 0,
            'additions' => $this->zipLines($request->input('add_name', []), $request->input('add_amount', [])),
            'other_deductions' => $this->zipLines($request->input('ded_name', []), $request->input('ded_amount', [])),
            'statutory_category' => $payslip->employee->statutoryCategory($periodEnd),
        ], $this->ratesWithFeatures());

        // toPayslipAttributes() deliberately omits claim_ids, so the reimbursement linkage
        // set at run creation survives edits. Amount columns are excluded from $fillable —
        // forceFill them.
        $payslip->forceFill($comp->toPayslipAttributes())->save();
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
     * Statutory rate tables with admin feature flags layered on top:
     *  - payroll.auto_pcb (bool)        → gates the auto-PCB estimate compute path.
     *  - payroll.statutory_mode (enum)  → flat|brackets toggles SOCSO/EIS use_brackets.
     * Defaults (auto_pcb off, mode brackets) leave the computed values unchanged.
     *
     * @return array{epf: array<string, mixed>, socso: array<string, mixed>, eis: array<string, mixed>, pcb: array<string, mixed>}
     */
    private function ratesWithFeatures(): array
    {
        $features = app(FeatureManager::class);
        $tenant = app(CurrentTenant::class)->get();
        $rates = StatutoryRate::merged();

        // Auto-PCB runs when opted in by either the admin feature flag or the legacy
        // rate-config toggle (I-016) — the flag is an additional enable path, so tenants
        // that turned auto-PCB on via the rate config keep their behaviour.
        $rates['pcb']['auto'] = $features->enabled($tenant, 'payroll.auto_pcb') || ! empty($rates['pcb']['auto']);

        // Flat percentage vs PERKESO stepped brackets for SOCSO + EIS.
        $useBrackets = $features->value($tenant, 'payroll.statutory_mode') === 'brackets';
        $rates['socso']['use_brackets'] = $useBrackets;
        $rates['eis']['use_brackets'] = $useBrackets;

        return $rates;
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
}
