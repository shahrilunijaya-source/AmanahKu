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
use App\Models\PayrollCp38Month;
use App\Models\PayrollItem;
use App\Models\PayrollOpeningFigure;
use App\Models\PayrollRun;
use App\Models\PayrollSubmission;
use App\Models\Payslip;
use App\Models\PayslipLine;
use App\Models\SalaryStructure;
use App\Services\FeatureManager;
use App\Services\Payroll\EpfCalculator;
use App\Services\Payroll\ExemptionCap;
use App\Services\Payroll\HrdCorpLevy;
use App\Services\Payroll\LifecycleNotices;
use App\Services\Payroll\MinimumWage;
use App\Services\Payroll\PayrollCalculator;
use App\Services\Payroll\PayrollReadiness;
use App\Services\Payroll\PayslipComputation;
use App\Services\Payroll\PcbCalculator;
use App\Services\Payroll\PcbInputs;
use App\Services\Payroll\PcbYearToDate;
use App\Services\Payroll\Proration;
use App\Services\Payroll\StatutoryCalendar;
use App\Support\Permissions;
use App\Support\StatutoryOptions;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    /** Which run pays a Fixed or Individual Transaction, as in Worksy: Month End (default) or Mid Month. */
    private const TRANSACTION_CYCLES = ['month_end', 'mid_month'];

    public function __construct(
        private readonly PayrollCalculator $calculator,
        private readonly PcbCalculator $pcb,
        private readonly EpfCalculator $epf,
        private readonly PcbYearToDate $pcbYtd,
        private readonly StatutoryCalendar $calendar,
        private readonly LifecycleNotices $notices,
    ) {}

    // ── Salary structures ─────────────────────────────────────────

    public function storeSalary(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $tid = app(CurrentTenant::class)->id();

        $validator = validator($request->all(), [
            'employee_id' => ['required', Rule::exists('employees', 'id')->where('tenant_id', $tid)],
            'effective_from' => ['nullable', 'date'],
            'bank_name' => ['nullable', 'string', 'max:60'],
            'bank_account_no' => ['nullable', 'string', 'max:40'],
            // Worksy Bank & Statutory tab fields (profile). Reference only; no calculation reads them.
            'bank_holder_name' => ['nullable', 'string', 'max:160'],
            'tax_resident' => ['nullable', 'boolean'],
            'tax_category' => ['nullable', Rule::in(array_keys(StatutoryOptions::TAX_CATEGORIES))],
            'employee_tax_status' => ['nullable', Rule::in(array_keys(StatutoryOptions::EMPLOYEE_TAX_STATUS))],
            'child_relief' => ['nullable', 'array'],
            'child_relief.*' => ['array'],
            'child_relief.*.*' => ['nullable', 'integer', 'min:0', 'max:20'],
            'epf_scheme' => ['nullable', Rule::in(array_keys(StatutoryOptions::EPF_SCHEMES))],
            'socso_category' => ['nullable', Rule::in(array_keys(StatutoryOptions::SOCSO_CATEGORIES))],
            'socso_exempt' => ['boolean'],
            'hrdf_exempt' => ['boolean'],
            'epf_no' => ['nullable', 'string', 'max:40'],
            'socso_no' => ['nullable', 'string', 'max:40'],
            'nationality' => ['nullable', Rule::in(['citizen', 'pr', 'foreign'])],
            // epf_opt_in_60plus/epf_employee_rate_override are NOT validated or written
            // here on purpose — see the "stored but unwired" comment on SalaryStructure.
            // They stay off this form entirely so re-saving a structure never blanks
            // whatever a tenant already has stored in those columns.
            'tax_no' => ['nullable', 'string', 'max:40'],
            'spouse_working' => ['boolean'],
            'children_relief_count' => ['nullable', 'integer', 'min:0', 'max:20'],
            'disabled_self' => ['boolean'],
            'disabled_spouse' => ['boolean'],
            'zakat_monthly' => ['nullable', 'numeric', 'min:0'],
            'zakat_authority' => ['nullable', Rule::in(array_keys(StatutoryOptions::ZAKAT_AUTHORITIES))],
            'skbbk_opt_in' => ['boolean'],
        ]);
        if ($validator->fails()) {
            // Flashed so the profile's Bank & Statutory modal reopens with the errors (payroll screen ignores it).
            session()->flash('form', 'bank');
            throw new ValidationException($validator);
        }
        $data = $validator->validated();

        SalaryStructure::updateOrCreate(
            ['tenant_id' => $tid, 'employee_id' => $data['employee_id']],
            [
                // 'allowances' is deliberately no longer written here — Fixed Transactions
                // (storeFixedTransaction et al., below) are the single source for recurring
                // earnings now. The column itself is left alone (see the migration
                // 2026_08_25_200200): a finalized payslip's history and any rollback still
                // want it there, just nothing writes or reads it going forward.
                'effective_from' => $data['effective_from'] ?? now()->toDateString(),
                'bank_name' => $data['bank_name'] ?? null,
                // SWIFT/BIC for the agency upload files; "Other" has no code and stays null.
                'bank_code' => StatutoryOptions::BANK_CODES[$data['bank_name'] ?? ''] ?? null,
                'bank_account_no' => $data['bank_account_no'] ?? null,
                'epf_no' => $data['epf_no'] ?? null,
                'socso_no' => $data['socso_no'] ?? null,
                'nationality' => $data['nationality'] ?? 'citizen',
                // epf_opt_in_60plus/epf_employee_rate_override deliberately absent from
                // this write — see the comment on the validation rules above.
                'tax_no' => $data['tax_no'] ?? null,
                // marital_status/nric live on the Employee record now — see the reconcile
                // migration 2026_08_25_200300 and PayrollController::buildPcbInputs().
                'spouse_working' => $request->boolean('spouse_working'),
                'children_relief_count' => $data['children_relief_count'] ?? 0,
                'disabled_self' => $request->boolean('disabled_self'),
                'disabled_spouse' => $request->boolean('disabled_spouse'),
                'zakat_monthly' => $data['zakat_monthly'] ?? 0,
                'zakat_authority' => $data['zakat_authority'] ?? null,
                'skbbk_opt_in' => $request->boolean('skbbk_opt_in'),
                'bank_holder_name' => $data['bank_holder_name'] ?? null,
                'tax_resident' => $request->has('tax_resident') ? $request->boolean('tax_resident') : true,
                'tax_category' => $data['tax_category'] ?? null,
                'employee_tax_status' => $data['employee_tax_status'] ?? null,
                'child_relief_breakdown' => self::childRelief($data['child_relief'] ?? null),
                'epf_scheme' => $data['epf_scheme'] ?? null,
                'socso_category' => $data['socso_category'] ?? null,
                'socso_exempt' => $request->boolean('socso_exempt'),
                'hrdf_exempt' => $request->boolean('hrdf_exempt'),
            ],
        );

        $employee = Employee::find($data['employee_id']);
        $name = $employee?->name;
        AuditLog::record('Updated salary structure', $name);

        // Spec F4: the Minimum Wages Order floor is a warning, never a block — interns and
        // apprentices are outside it, and HR is the one who knows which this is.
        $basic = (float) ($employee->salary ?? 0);
        $warn = MinimumWage::below($basic)
            ? 'Basic pay RM'.number_format($basic, 2).' is below the RM1,700 monthly minimum (Minimum Wages Order 2024, from '.MinimumWage::EFFECTIVE.'). Check the employment type: the floor does not apply to interns or apprentices.'
            : null;

        $back = back()->with('ok', 'Salary structure saved for '.$name.'.');

        return $warn !== null ? $back->with('warn', $warn) : $back;
    }

    // ── Opening figures (mid-year "take on") ───────────────────────

    /**
     * What a previous employer/system already paid an employee earlier in a calendar
     * year — see PayrollOpeningFigure. Without this a mid-year joiner (or a company
     * switching to this app mid-year) gets a wrong PCB and a wrong EA form for the
     * rest of that year.
     */
    /**
     * Normalise the child-relief grid to every LHDN category × {100, 50} as ints, or null when nothing was sent.
     *
     * @param  array<string, array<int, mixed>>|null  $grid
     * @return array<string, array{100: int, 50: int}>|null
     */
    private static function childRelief(?array $grid): ?array
    {
        if ($grid === null) {
            return null;
        }
        $out = [];
        foreach (array_keys(StatutoryOptions::CHILD_RELIEF_CATEGORIES) as $cat) {
            $out[$cat] = ['100' => (int) ($grid[$cat][100] ?? 0), '50' => (int) ($grid[$cat][50] ?? 0)];
        }

        return $out;
    }

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
            'ea' => ['nullable', 'array:'.implode(',', [...PayrollOpeningFigure::EA_AMOUNTS, ...PayrollOpeningFigure::EA_TEXT])],
            ...array_fill_keys(array_map(fn ($k) => 'ea.'.$k, PayrollOpeningFigure::EA_AMOUNTS), ['nullable', 'numeric', 'min:0', 'max:100000000']),
            ...array_fill_keys(array_map(fn ($k) => 'ea.'.$k, PayrollOpeningFigure::EA_TEXT), ['nullable', 'string', 'max:200']),
        ]);

        // Only the fields the form sent are written: the profile's TP3 form and the
        // Take On tab each hold a different part of the same row.
        $row = PayrollOpeningFigure::firstOrNew(['tenant_id' => $tid, 'employee_id' => $data['employee_id'], 'year' => $data['year']]);
        foreach (['gross', 'epf', 'pcb_paid', 'zakat_paid', 'additional_gross', 'additional_epf', 'socso', 'eis', 'optional_deductions', 'exempt_allowances'] as $f) {
            if (array_key_exists($f, $data)) {
                $row->{$f} = $data[$f] ?? 0;
            }
        }
        foreach (['previous_employer', 'previous_employer_tin'] as $f) {
            if (array_key_exists($f, $data)) {
                $row->{$f} = $data[$f];
            }
        }
        if (array_key_exists('ea', $data)) {
            $lines = array_merge($row->ea_lines ?? [], $data['ea'] ?? []);
            $row->ea_lines = array_filter($lines, fn ($v) => $v !== null && $v !== '' && (! is_numeric($v) || (float) $v != 0));
        }
        $row->save();

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

        return $this->toFixedTab($tx->employee_id)->with('ok', 'Fixed transaction added for '.$name.'.');
    }

    public function updateFixedTransaction(Request $request, FixedTransaction $fixedTransaction): RedirectResponse
    {
        $this->authorizeAdmin($request);
        abort_unless($fixedTransaction->tenant_id === app(CurrentTenant::class)->id(), 403);

        $data = $this->validateFixedTransaction($request, $fixedTransaction->tenant_id, $fixedTransaction->employee_id);
        $fixedTransaction->update($data);

        AuditLog::record('Updated fixed transaction', $fixedTransaction->employee?->name.' · '.$fixedTransaction->payrollItem?->name);

        return $this->toFixedTab($fixedTransaction->employee_id)->with('ok', 'Fixed transaction updated for '.$fixedTransaction->employee?->name.'.');
    }

    /** Back to the Fixed Transaction tab with the same staff member still picked. */
    private function toFixedTab(int $employeeId): RedirectResponse
    {
        return redirect()->route('app.screen', ['screen' => 'payroll-transaction', 'tab' => 'fixed', 'emp' => $employeeId]);
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

        return $this->toFixedTab($fixedTransaction->employee_id)->with('ok', 'Fixed transaction ended after '.$data['end_period'].'.');
    }

    /**
     * @return array{employee_id: int, payroll_item_id: int, amount: float, start_period: string, end_period: ?string, last_amount: ?float, prorate: bool, remarks: ?string, consent_reference: ?string, payroll_cycle: string, last_payroll_cycle: ?string}
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
            // EA s.24: a non-statutory deduction needs the employee's written consent —
            // this records where that consent is filed, not the consent itself.
            'consent_reference' => ['nullable', 'string', 'max:160'],
            'payroll_cycle' => ['nullable', Rule::in(self::TRANSACTION_CYCLES)],
            'last_payroll_cycle' => ['nullable', Rule::in(self::TRANSACTION_CYCLES)],
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
            'consent_reference' => $data['consent_reference'] ?? null,
            'payroll_cycle' => $data['payroll_cycle'] ?? 'month_end',
            // Only means something alongside a last-month amount.
            'last_payroll_cycle' => isset($data['last_amount']) ? ($data['last_payroll_cycle'] ?? null) : null,
        ];
    }

    // ── Individual Transactions (one-off per-employee pay/deduction lines) ─────────

    public function storeIndividualTransaction(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $tid = app(CurrentTenant::class)->id();

        $data = $this->validateIndividualTransaction($request, $tid);
        $this->assertPeriodEditable($tid, $data['period'], $data['for_bonus_run'], $data['payroll_cycle']);

        $tx = IndividualTransaction::create($data + ['created_by_id' => Auth::id()]);

        $name = $tx->employee?->name;
        AuditLog::record('Added individual transaction', $name.' · '.$tx->payrollItem?->name.' · '.$data['period'].' · RM '.number_format($tx->amount, 2));

        return back()->with('ok', 'Individual transaction added for '.$name.'.');
    }

    public function updateIndividualTransaction(Request $request, IndividualTransaction $individualTransaction): RedirectResponse
    {
        $this->authorizeAdmin($request);
        abort_unless($individualTransaction->tenant_id === app(CurrentTenant::class)->id(), 403);
        $this->assertPeriodEditable($individualTransaction->tenant_id, $individualTransaction->period, $individualTransaction->for_bonus_run, $individualTransaction->payroll_cycle);

        $data = $this->validateIndividualTransaction($request, $individualTransaction->tenant_id, $individualTransaction->employee_id, $individualTransaction->period);
        // Editing never moves a one-off between the monthly and the bonus run — the same
        // rule the employee and the period follow.
        unset($data['for_bonus_run']);
        if ($individualTransaction->for_bonus_run || ! $request->filled('payroll_cycle')) {
            unset($data['payroll_cycle']);
        } else {
            $this->assertPeriodEditable($individualTransaction->tenant_id, $individualTransaction->period, false, $data['payroll_cycle']);
        }
        $individualTransaction->update($data);

        AuditLog::record('Updated individual transaction', $individualTransaction->employee?->name.' · '.$individualTransaction->payrollItem?->name);

        return back()->with('ok', 'Individual transaction updated for '.$individualTransaction->employee?->name.'.');
    }

    public function destroyIndividualTransaction(Request $request, IndividualTransaction $individualTransaction): RedirectResponse
    {
        $this->authorizeAdmin($request);
        abort_unless($individualTransaction->tenant_id === app(CurrentTenant::class)->id(), 403);
        $this->assertPeriodEditable($individualTransaction->tenant_id, $individualTransaction->period, $individualTransaction->for_bonus_run, $individualTransaction->payroll_cycle);

        $name = $individualTransaction->employee?->name;
        $itemName = $individualTransaction->payrollItem?->name;
        $individualTransaction->delete();

        AuditLog::record('Deleted individual transaction', $name.' · '.$itemName);

        return back()->with('ok', 'Individual transaction deleted for '.$name.'.');
    }

    /**
     * @return array{employee_id: int, payroll_item_id: int, period: string, for_bonus_run: bool, payroll_cycle: string, amount: float, remarks: ?string}
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
            // Spec F10: ticked means "pay this in the bonus run for the month", so the
            // monthly run leaves it alone.
            'for_bonus_run' => ['nullable', 'boolean'],
            'payroll_cycle' => ['nullable', Rule::in(self::TRANSACTION_CYCLES)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:10000000'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);

        return [
            'employee_id' => $lockEmployeeId ?? $data['employee_id'],
            'payroll_item_id' => $data['payroll_item_id'],
            'period' => $lockPeriod ?? $data['period'],
            'for_bonus_run' => $request->boolean('for_bonus_run'),
            // A bonus-run one-off has no cycle of its own; it is paid in the bonus run.
            'payroll_cycle' => $request->boolean('for_bonus_run') ? 'month_end' : ($data['payroll_cycle'] ?? 'month_end'),
            'amount' => $data['amount'],
            'remarks' => $data['remarks'] ?? null,
        ];
    }

    /**
     * A period whose run has been finalized is locked — that payslip has been issued and
     * an Individual Transaction must not add, edit or delete anything against it. A
     * period with a draft run, or no run at all yet, is freely editable.
     */
    private function assertPeriodEditable(int $tid, string $period, bool $forBonus = false, string $cycle = 'month_end'): void
    {
        // Spec F10: the kinds are locked separately — a finalized monthly run must not
        // stop HR queuing a bonus for the same month, and vice versa. A mid-month one-off
        // is also locked once the mid-month run that paid it is finalized.
        $kinds = $forBonus ? ['bonus'] : ($cycle === 'mid_month' ? ['monthly', 'mid_month'] : ['monthly']);
        $finalized = PayrollRun::where('tenant_id', $tid)->where('period', $period)
            ->whereIn('kind', $kinds)
            ->where('status', 'finalized')->exists();
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
    private function individualTransactionLinesForPeriod(Employee $employee, string $period, bool $forBonus = false, ?string $cycle = null): Collection
    {
        $rows = IndividualTransaction::with('payrollItem')
            ->where('employee_id', $employee->id)
            ->forPeriod($period)
            ->forBonusRun($forBonus)
            ->when($cycle !== null, fn ($q) => $q->where('payroll_cycle', $cycle))
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
    private function syncIndividualTransactions(Employee $employee, string $period, array $ids, array $itemIds, array $amounts, array $remarks, array $knownIds, bool $forBonus = false): void
    {
        $knownIds = collect($knownIds)->filter(fn ($v) => $v !== null && $v !== '')->map(fn ($v) => (int) $v)->unique()->values()->all();

        // Re-derive from the DB rather than trusting the posted ids: a row that doesn't
        // actually belong to this employee/period/tenant is dropped here, so it can never
        // be updated or deleted by this form no matter what id was posted for it.
        $knownRows = IndividualTransaction::where('employee_id', $employee->id)
            ->forPeriod($period)
            ->forBonusRun($forBonus)
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
                    // A row added while editing a bonus payslip belongs to the bonus run,
                    // not to next month's monthly one.
                    'for_bonus_run' => $forBonus,
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
     * $cycle keeps only the lines paid in that cycle this month: the last-month amount
     * follows last_payroll_cycle when HR set one, every other month payroll_cycle.
     *
     * @return Collection<int, array{item: PayrollItem, amount: float, fixed_transaction_id: int, cycle: string}>
     */
    private function fixedTransactionLines(Employee $employee, string $period, ?string $cycle = null): Collection
    {
        return FixedTransaction::with('payrollItem')
            ->where('employee_id', $employee->id)
            ->activeDuring($period)
            ->get()
            ->filter(fn (FixedTransaction $ft) => $ft->payrollItem !== null)
            ->map(function (FixedTransaction $ft) use ($employee, $period) {
                $isLastMonth = $ft->end_period === $period && $ft->last_amount !== null;
                $amount = $isLastMonth ? $ft->last_amount : $ft->amount;

                if ($ft->prorate) {
                    $amount = round($amount * $this->prorationFactor($employee, $period), 2);
                }

                return [
                    'item' => $ft->payrollItem,
                    'amount' => $amount,
                    'fixed_transaction_id' => $ft->id,
                    'cycle' => $isLastMonth ? ($ft->last_payroll_cycle ?? $ft->payroll_cycle) : $ft->payroll_cycle,
                ];
            })
            ->filter(fn (array $line) => $cycle === null || $line['cycle'] === $cycle)
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
    /**
     * First day of a "YYYY-MM" pay period.
     *
     * Carbon::createFromFormat('Y-m', ...) fills the missing day from TODAY, so on the
     * 31st a 30-day period parses as the 31st, overflows into the next month, and every
     * period window silently shifts forward — a run created on 31 August for June pulled
     * July's overtime. Always give the parser a day.
     */
    private function periodStart(string $period): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfDay();
    }

    private function prorationFactor(Employee $employee, string $period): float
    {
        $d = $this->employedDays($employee, $period);

        return $d['in_month'] > 0 ? min(1.0, $d['employed'] / $d['in_month']) : 0.0;
    }

    /**
     * Calendar days employed within the period (spec F3). Leaving date: the employee
     * record's last_working_day first, then an offboarding case's last_day.
     *
     * @return array{employed: int, in_month: int}
     */
    private function employedDays(Employee $employee, string $period): array
    {
        return Proration::days($period, $employee->joined_at, $this->lastWorkingDayFor($employee, $period));
    }

    /**
     * The day this employee stops being paid — the employment record's own last working
     * day, or an offboarding case's last day inside this period. Used both to prorate the
     * last month's wages and to decide whether a final pay run may be created.
     */
    private function lastWorkingDayFor(Employee $employee, string $period): ?Carbon
    {
        $periodStart = $this->periodStart($period);
        $periodEnd = $this->periodStart($period)->endOfMonth();
        $lastDay = $employee->last_working_day;
        if ($lastDay === null) {
            $raw = OffboardingCase::where('employee_id', $employee->id)
                ->whereIn('status', ['in_progress', 'completed'])
                ->whereBetween('last_day', [$periodStart->toDateString(), $periodEnd->toDateString()])
                ->orderByDesc('last_day')
                ->value('last_day');
            $lastDay = $raw !== null ? Carbon::parse($raw) : null;
        }

        return $lastDay;
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
        $periodStart = $this->periodStart($period);
        $periodEnd = $this->periodStart($period)->endOfMonth();

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
     * A staff member confirms they have seen their own issued payslip. Once only, own
     * slip only, published runs only, and only while the company has the acknowledgement
     * setting on. HR does not acknowledge on anyone's behalf.
     */
    public function acknowledgePayslip(Request $request, Payslip $payslip): RedirectResponse
    {
        $tid = app(CurrentTenant::class)->id();
        abort_unless($payslip->tenant_id === $tid, 403);
        $user = $request->user();
        $employee = $user ? Employee::where('tenant_id', $tid)->where('user_id', $user->id)->first() : null;
        abort_unless($employee && $payslip->employee_id === $employee->id, 403);
        // Spec F13: acknowledgement is opt-in per company. Off, there is nothing to sign.
        abort_unless(app(FeatureManager::class)->enabled(app(CurrentTenant::class)->get(), 'payroll.payslip_acknowledgement'), 422, 'Payslip acknowledgement is switched off for this company.');
        abort_unless($payslip->payrollRun?->isPublished(), 422);
        abort_if($payslip->acknowledged_at !== null, 422);

        $payslip->forceFill(['acknowledged_at' => now()])->save();
        AuditLog::record('Acknowledged payslip', $payslip->payrollRun->label);

        return redirect()->route('app.screen', ['screen' => 'payroll-my', 'payslip' => $payslip->id])->with('ok', 'Payslip acknowledged.');
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
        $periodStart = $this->periodStart($period);
        $periodEnd = $this->periodStart($period)->endOfMonth();

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
            // Spec F10: 'final' is one named leaver's last pay; the other kinds cover
            // the whole company and take no employee_id.
            'kind' => ['nullable', Rule::in(PayrollRun::KINDS)],
            'employee_id' => [Rule::requiredIf(fn () => $request->input('kind') === 'final'), 'nullable', 'integer'],
            'payment_date' => ['nullable', 'date'],
            'pull_fixed' => ['nullable', 'boolean'],
            'pull_claims' => ['nullable', 'boolean'],
            'pull_overtime' => ['nullable', 'boolean'],
            'pull_unpaid' => ['nullable', 'boolean'],
            'exclude_employee_ids' => ['nullable', 'array'],
            'exclude_employee_ids.*' => ['integer', Rule::exists('employees', 'id')->where('tenant_id', $tid)],
            // Process Payroll wizard: when sent, the run covers only these people.
            'include_employee_ids' => ['nullable', 'array'],
            'include_employee_ids.*' => ['integer', Rule::exists('employees', 'id')->where('tenant_id', $tid)],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'mid_month_basis' => [Rule::requiredIf(fn () => $request->input('kind') === 'mid_month'), 'nullable', Rule::in(['cutoff', 'percentage'])],
            'mid_month_value' => ['nullable', 'integer', 'min:1', $request->input('mid_month_basis') === 'cutoff' ? 'max:28' : 'max:100'],
        ]);
        // A tick that was never sent counts as on, so a post without the ticks pulls everything as before.
        $pulls = collect(PayrollRun::PULL_SOURCES)->mapWithKeys(fn (string $s) => [$s => $request->boolean('pull_'.$s, true)])->all();
        $kind = $data['kind'] ?? 'monthly';

        // Spec F10: a final pay run is for one leaver whose last working day falls inside
        // the period, and there is only ever one of them per person.
        $leaver = null;
        if ($kind === 'final') {
            $leaver = Employee::with('salaryStructure')->where('tenant_id', $tid)->find($data['employee_id'] ?? null);
            if ($leaver === null || $leaver->salaryStructure === null) {
                return back()->withErrors(['employee_id' => 'Choose an employee who has a salary structure.'])->withInput();
            }
            if ($leaver->final_pay_run_id !== null) {
                return back()->withErrors(['employee_id' => $leaver->name.' has already been paid out in a final pay run.'])->withInput();
            }
            $lastDay = $this->lastWorkingDayFor($leaver, $data['period']);
            if ($lastDay === null || ! $lastDay->betweenIncluded($this->periodStart($data['period']), $this->periodStart($data['period'])->endOfMonth())) {
                return back()->withErrors(['employee_id' => $leaver->name.' has no last working day inside '.$data['period'].'. Record the leaving date first.'])->withInput();
            }
            // One payout per leaver per month: if the monthly run already carries their
            // (prorated) payslip, a final run on top would pay the same days twice.
            $alreadyInMonthly = Payslip::where('employee_id', $leaver->id)
                ->whereHas('payrollRun', fn ($r) => $r->where('period', $data['period'])->where('kind', 'monthly'))
                ->exists();
            if ($alreadyInMonthly) {
                return back()->withErrors(['employee_id' => $leaver->name.' already has a payslip in the '.$data['period'].' monthly run. Delete that draft run and create it again after this final pay run, or pay them through the monthly run.'])->withInput();
            }
        }

        // Spec F10: still exactly one monthly run per tenant and period — but a bonus run
        // may sit alongside it in the same month, and there may be more than one of those
        // (two separate bonus payouts in December is an ordinary thing to do).
        if ($kind === 'monthly' && PayrollRun::where('tenant_id', $tid)->where('period', $data['period'])->where('kind', 'monthly')->exists()) {
            return back()->withErrors(['period' => 'A payroll run already exists for '.$data['period'].'.'])->withInput();
        }

        // Mid month is an advance the month-end run takes back, so it has to come first,
        // there is one per period, and month end waits until it is no longer a draft.
        $periodRuns = PayrollRun::where('tenant_id', $tid)->where('period', $data['period']);
        if ($kind === 'mid_month') {
            if ((clone $periodRuns)->where('kind', 'monthly')->exists()) {
                return back()->withErrors(['period' => 'The '.$data['period'].' month-end run already exists. A mid-month run has to come before it.'])->withInput();
            }
            if ((clone $periodRuns)->where('kind', 'mid_month')->exists()) {
                return back()->withErrors(['period' => 'A mid-month run already exists for '.$data['period'].'.'])->withInput();
            }
        }
        if ($kind === 'monthly' && (clone $periodRuns)->where('kind', 'mid_month')->where('status', 'draft')->exists()) {
            return back()->withErrors(['period' => 'The '.$data['period'].' mid-month run is still a draft. Approve or delete the mid-month run first.'])->withInput();
        }

        $excluded = array_map('intval', $data['exclude_employee_ids'] ?? []);
        // A final run is that one leaver and nobody else — they may already be marked
        // resigned, so the "currently employed" allowlist below would miss them.
        $employees = $leaver !== null ? collect([$leaver]) : Employee::active()->with('salaryStructure')
            ->whereHas('salaryStructure')
            ->whereIn('status', ['active', 'probation', 'on_leave'])   // everyone currently employed (allowlist)
            // Anyone already paid out in a final run is done with payroll for good.
            ->whereNull('final_pay_run_id')
            // A draft final run has not stamped final_pay_run_id yet, but it already holds
            // this month's payslip for that leaver.
            ->when(in_array($kind, ['monthly', 'mid_month'], true), fn ($q) => $q->whereNotIn('id', Payslip::whereHas('payrollRun',
                fn ($r) => $r->where('period', $data['period'])->where('kind', 'final'))->select('employee_id')))
            ->when($kind === 'bonus', fn ($q) => $q->whereIn('id', IndividualTransaction::where('tenant_id', $tid)
                ->forPeriod($data['period'])->forBonusRun(true)->select('employee_id')))
            ->orderBy('name')->get();
        if ($leaver === null) {
            // Everyone eligible but not picked in the wizard counts as excluded, so the
            // readiness gate below and the stored exclusions treat them exactly alike.
            if (($data['include_employee_ids'] ?? null) !== null) {
                $notIncluded = $employees->pluck('id')->diff(array_map('intval', $data['include_employee_ids']))->all();
                $excluded = array_values(array_unique([...$excluded, ...$notIncluded]));
            }
            $employees = $employees->whereNotIn('id', $excluded)->values();
        }

        // Spec F2 readiness gate: refuse while the employer or any included employee is
        // missing an identifier an agency upload needs. Exclusions are HR's explicit call
        // and are stored on the run. A bonus run only pays the people with a bonus queued,
        // so only those people's gaps can block it.
        $tenant = app(CurrentTenant::class)->get();
        $readiness = app(PayrollReadiness::class);
        $inThisRun = $employees->pluck('id')->all();
        $problems = array_map(fn (string $g) => 'Company: '.$g, $readiness->employerGaps($tenant));
        if ($leaver !== null) {
            // A leaver may already be marked resigned, which drops them out of the
            // "currently employed" rows below, so check them directly.
            $leaverGaps = $readiness->gapsFor($leaver)['blocking'];
            if ($leaverGaps !== []) {
                $problems[] = $leaver->name.': '.implode(', ', $leaverGaps);
            }
        } else {
            // With the wizard's explicit pick list, only the people actually in the run can
            // block it; the list page already shows HR who is left out (e.g. no salary structure).
            $onlyThisRun = $kind !== 'monthly' || ($data['include_employee_ids'] ?? null) !== null;
            foreach ($readiness->blockingRows($tenant, $excluded) as $row) {
                if ($onlyThisRun && ! in_array($row['employee']->id, $inThisRun, true)) {
                    continue;
                }
                $problems[] = $row['employee']->name.': '.implode(', ', $row['blocking']);
            }
        }
        if ($problems !== []) {
            return back()->withErrors(['readiness' => 'Not ready to run payroll. '.implode(' · ', $problems)])->withInput();
        }

        if ($employees->isEmpty()) {
            return back()->withErrors(['period' => $kind === 'bonus'
                ? 'No bonus is queued for '.$data['period'].'. Tick "pay in the bonus run" on an individual transaction first.'
                : 'No employees have a salary structure yet. Add salary structures first.'])->withInput();
        }

        // Contribution category is assessed at the pay period's end.
        $periodEnd = $this->periodStart($data['period'])->endOfMonth();
        $missingDob = $employees->whereNull('date_of_birth')->count();

        $catalog = PayrollItem::where('tenant_id', $tid)->get()->keyBy('code');
        // Spec F7: one tenant-level rate for the whole run; per-employee eligibility below.
        $hrdfRate = HrdCorpLevy::rate((string) app(FeatureManager::class)->value($tenant, 'payroll.hrdf'));

        $overtimeWarnings = [];
        // EA s.20: a leaver's wages are due on the day the contract ends, so that is the
        // pay date unless HR typed one in.
        $finalPayDate = $leaver === null ? null : $this->lastWorkingDayFor($leaver, $data['period'])?->toDateString();

        $run = DB::transaction(function () use ($data, $kind, $leaver, $finalPayDate, $employees, $periodEnd, $catalog, $pulls, $excluded, $hrdfRate, &$overtimeWarnings) {
            $run = new PayrollRun([
                'period' => $data['period'],
                'kind' => $kind,
                'employee_id' => $leaver?->id,
                'label' => $this->periodStart($data['period'])->format('F Y').['monthly' => '', 'mid_month' => ' mid month', 'bonus' => ' bonus', 'final' => ' final pay'][$kind],
                'run_by_id' => Auth::id(),
                'payment_date' => $data['payment_date'] ?? $finalPayDate,
                'pull_options' => $pulls,
                'excluded_employee_ids' => $excluded ?: null,
                'remarks' => $data['remarks'] ?? null,
                'mid_month_basis' => $kind === 'mid_month' ? $data['mid_month_basis'] : null,
                'mid_month_value' => $kind === 'mid_month' ? ($data['mid_month_value'] ?? ($data['mid_month_basis'] === 'cutoff' ? 15 : 50)) : null,
            ]);
            // status is a lifecycle column excluded from $fillable — set it directly.
            $run->status = 'draft';
            $run->save();

            if ($kind === 'bonus') {
                $this->buildBonusPayslips($run, $employees, $periodEnd, $catalog);
            } elseif ($kind === 'mid_month') {
                $this->buildMidMonthPayslips($run, $employees, $catalog);
                // Everyone chosen joined after the cutoff (or left before the 1st). Throwing
                // rolls the empty run back and sends HR back to the form with their input.
                if (! $run->payslips()->exists()) {
                    throw ValidationException::withMessages(['period' => 'Nobody is due a mid-month advance for '.$this->periodStart($run->period)->format('F Y').'. Everyone chosen joined after the cutoff day or had left before the month began.']);
                }
            } else {
                $this->buildMonthlyPayslips($run, $employees, $periodEnd, $catalog, $pulls, $hrdfRate, $overtimeWarnings);
            }

            $this->recalcTotals($run);
            $skipped = array_keys(array_filter($pulls, fn (bool $on) => ! $on));
            $note = $kind !== 'monthly' ? '' : ($skipped ? ' · not pulled: '.implode(', ', $skipped) : '').($excluded ? ' · excluded: '.count($excluded) : '');
            AuditLog::record('Created payroll run', $run->label.' · '.$run->payslips()->count().' payslips'.$note);

            return $run;
        });

        $msg = 'Draft payroll run created for '.$this->periodStart($data['period'])->format('F Y').'.';
        if ($missingDob > 0) {
            $msg .= ' Note: '.$missingDob.' employee(s) have no date of birth and were treated as below 60 (SOCSO Category 1) — set their DOB and recompute to confirm their contribution category.';
        }
        if ($overtimeWarnings !== []) {
            $msg .= ' Warning: overtime above the 104-hour monthly limit for '.implode(', ', $overtimeWarnings).'.';
        }

        return redirect()->route('app.screen', ['screen' => 'payroll-process', 'tab' => 'monthly', 'step' => 'results', 'run' => $run->id])
            ->with('ok', $msg);
    }

    /**
     * One draft payslip per employee for an ordinary monthly run — everything the run
     * pulls (Fixed and Individual Transactions, claims, overtime, unpaid leave) plus the
     * statutory deductions. Moved out of createRun() unchanged when spec F10 split runs
     * by kind; buildBonusPayslips() is the other half.
     *
     * @param  Collection<int, Employee>  $employees
     * @param  Collection<string, PayrollItem>  $catalog
     * @param  array<string, bool>  $pulls
     * @param  list<string>  $overtimeWarnings
     */
    private function buildMonthlyPayslips(PayrollRun $run, Collection $employees, Carbon $periodEnd, Collection $catalog, array $pulls, float $hrdfRate, array &$overtimeWarnings): void
    {
        $period = $run->period;
        // Claims already attached to any run must never be pulled again — prevents
        // double reimbursement across concurrent or sequential draft runs.
        $usedClaimIds = Payslip::whereNotNull('claim_ids')
            ->pluck('claim_ids')->flatten()->filter()->unique()->all();
        // Same double-pull protection for approved overtime and unpaid leave.
        $usedOvertimeIds = $this->usedOvertimeIds(null);
        $usedLeaveIds = $this->usedUnpaidLeaveIds(null);

        foreach ($employees as $employee) {
            $structure = $employee->salaryStructure;
            $claims = ! $pulls['claims'] ? collect() : $employee->claims()
                ->where('status', 'approved')->whereNull('paid_at')
                ->whereNotIn('id', $usedClaimIds)
                ->lockForUpdate()->get();

            $overtimeRequests = $pulls['overtime'] ? $this->pullableOvertimeFor($employee, $period, $usedOvertimeIds) : collect();
            $pulledOvertimeGroups = $this->overtimeGroups($overtimeRequests);
            $pulledOvertimeHours = round($overtimeRequests->sum(fn (OvertimeRequest $o) => (float) $o->hours), 2);
            if ($pulledOvertimeHours > PayrollCalculator::OVERTIME_HOURS_CAP) {
                $overtimeWarnings[] = $employee->name.' ('.$pulledOvertimeHours.'h)';
            }
            $unpaidLeaveRequests = $pulls['unpaid'] ? $this->pullableUnpaidLeaveFor($employee, $period, $usedLeaveIds) : collect();
            $pulledUnpaidDays = round($unpaidLeaveRequests->sum(fn (LeaveRequest $l) => (float) $l->days), 2);

            $age = $employee->date_of_birth === null ? null : (int) $employee->date_of_birth->diffInYears($periodEnd);
            // electedBefore1998 has no column — no tenant has data going back that far,
            // so every non-citizen falls under mandatory Part F.
            $epfPart = $this->epf->part($structure->nationality ?? 'citizen', $age, false);

            // Fixed Transactions replace salary_structures.allowances as the source of
            // recurring earnings/deductions (see migration 2026_08_25_200200) — split
            // by the transaction's own Payroll Item type.
            // Mid-month Fixed Transactions the mid-month run already paid are part of this
            // month's pay (statutory is worked out here on the whole month), so they come
            // in even with the pull unticked; the advance deduction then takes them back.
            $midMonthAdvance = $this->midMonthAdvanceFor($employee, $period);
            $ftLines = match (true) {
                $pulls['fixed'] => $this->fixedTransactionLines($employee, $period),
                $midMonthAdvance > 0 => $this->fixedTransactionLines($employee, $period, 'mid_month'),
                default => collect(),
            };
            $fixedEarnings = $ftLines->filter(fn (array $l) => $l['item']->type === 'earning');
            $fixedDeductions = $ftLines->filter(fn (array $l) => $l['item']->type === 'deduction');

            // Individual Transactions queued for this period — see
            // individualTransactionLinesForPeriod(), the same read path updatePayslip
            // uses, so a one-off queued before the run existed appears here exactly as
            // it would if it had been typed into the payslip edit form instead.
            $itLines = $this->individualTransactionLinesForPeriod($employee, $period);
            $individualEarnings = $itLines->filter(fn (array $l) => $l['item']->type === 'earning');
            $individualDeductions = $itLines->filter(fn (array $l) => $l['item']->type === 'deduction');

            // Spec F3: an incomplete month of service is paid by calendar days (s.18A),
            // but only for items flagged to prorate.
            $days = $this->employedDays($employee, $period);
            // A tenant with no seeded catalogue prorates by default (has() guards the
            // read; PHPStan types Collection::get() as non-null here).
            $prorateBasic = ! $catalog->has('basic-salary') || (bool) $catalog->get('basic-salary')->prorate_on_incomplete_month;
            $basic = $prorateBasic && $days['employed'] < $days['in_month']
                ? Proration::prorate((float) ($employee->salary ?? 0), $days['employed'], $days['in_month'])
                : (float) ($employee->salary ?? 0);

            $inputs = [
                // Basic salary is the employee record's (Employment tab / Progression), as in
                // Worksy. salary_structures.basic_salary is history only since 2026-09-29.
                'basic' => $basic,
                'allowances_total' => round($fixedEarnings->sum('amount'), 2),
                'fixed_deductions_total' => round($fixedDeductions->sum('amount'), 2),
                'fixed_earning_lines' => $fixedEarnings->map(fn (array $l) => [
                    'amount' => $l['amount'],
                    'epf_liable' => (bool) $l['item']->epf_liable,
                    'perkeso_liable' => (bool) $l['item']->perkeso_liable,
                    'hrdf_liable' => (bool) $l['item']->hrdf_liable,
                ])->values()->all(),
                'individual_earnings_total' => round($individualEarnings->sum('amount'), 2),
                'individual_deductions_total' => round($individualDeductions->sum('amount'), 2),
                'individual_earning_lines' => $individualEarnings->map(fn (array $l) => [
                    'amount' => $l['amount'],
                    'epf_liable' => (bool) $l['item']->epf_liable,
                    'perkeso_liable' => (bool) $l['item']->perkeso_liable,
                    'hrdf_liable' => (bool) $l['item']->hrdf_liable,
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
                'socso_exempt' => (bool) $structure->socso_exempt,
                // Spec F7: citizens only, and not when HR has marked the employee exempt.
                'hrdf_rate' => (($structure->nationality ?? 'citizen') === 'citizen' && ! $structure->hrdf_exempt) ? $hrdfRate : 0.0,
            ];
            $inputs = $this->withWageBaseFlags($inputs, $catalog);
            $comp = $this->calculator->compute($inputs);

            // PCB: the real LHDN computerised MTD calculation, year-to-date-aware —
            // see buildPcbInputs(). Two-pass like EPF/SOCSO above: compute gross/EPF
            // first, feed those into PCB, then recompute so the deduction flows into net.
            $exemptThisMonth = $this->exemptThisMonth($employee, $period, [
                ...$fixedEarnings->map(fn (array $l) => ['item' => $l['item'], 'amount' => (float) $l['amount']])->values()->all(),
                ...$individualEarnings->map(fn (array $l) => ['item' => $l['item'], 'amount' => (float) $l['amount']])->values()->all(),
            ]);
            $result = $this->pcb->calculate($this->buildPcbInputs($employee, $period, $comp, $structure, $epfPart, $exemptThisMonth));
            $inputs['pcb'] = $result->netNormalMtd;
            $inputs['pcb_additional'] = $result->additionalMtd;
            $inputs['zakat'] = (float) ($structure->zakat_monthly ?? 0);
            $inputs['cp38'] = PayrollCp38Month::amountFor($employee, $period);
            $inputs['mid_month_advance'] = $midMonthAdvance;
            $comp = $this->calculator->compute($inputs);

            // Computed amount columns are excluded from $fillable — forceFill them.
            // employee_id + claim_ids are fillable; payroll_run_id is set by the relation;
            // tenant_id is auto-filled by BelongsToTenant on save.
            $payslip = $run->payslips()->make([
                'employee_id' => $employee->id,
                'claim_ids' => $claims->pluck('id')->all() ?: null,
            ]);
            $payslip->forceFill($comp->toPayslipAttributes() + [
                'pcb_exempt_amount' => $exemptThisMonth,
                'days_employed' => $days['employed'],
                'days_in_month' => $days['in_month'],
                'overtime_request_ids' => $overtimeRequests->pluck('id')->all() ?: null,
                'pulled_overtime_hours' => $pulledOvertimeHours,
                'unpaid_leave_request_ids' => $unpaidLeaveRequests->pluck('id')->all() ?: null,
                'pulled_unpaid_days' => $pulledUnpaidDays,
            ])->save();
            $this->writePayslipLines($payslip, $comp, $ftLines, $itLines, $catalog);
        }
    }

    /**
     * Spec F10: a bonus run pays the Individual Transactions flagged for it and nothing
     * else — no basic salary, no Fixed Transactions, no claims, overtime or unpaid leave.
     *
     * EPF follows each item's own epf_liable flag (KWSP wages include bonus), while
     * SOCSO, EIS and the HRD Corp levy are forced to zero for the whole run: PERKESO's
     * published list of payments subject to contribution excludes the annual bonus, and
     * the levy's base is basic pay plus fixed allowances. That is a property of the run,
     * not of the item, so the item flags are deliberately overridden here.
     *
     * @param  Collection<int, Employee>  $employees
     * @param  Collection<string, PayrollItem>  $catalog
     */
    private function buildBonusPayslips(PayrollRun $run, Collection $employees, Carbon $periodEnd, Collection $catalog): void
    {
        foreach ($employees as $employee) {
            $structure = $employee->salaryStructure;
            $itLines = $this->individualTransactionLinesForPeriod($employee, $run->period, true);
            $earnings = $itLines->filter(fn (array $l) => $l['item']->type === 'earning');
            $deductions = $itLines->filter(fn (array $l) => $l['item']->type === 'deduction');
            if ($itLines->isEmpty()) {
                continue;
            }

            $age = $employee->date_of_birth === null ? null : (int) $employee->date_of_birth->diffInYears($periodEnd);
            $epfPart = $this->epf->part($structure->nationality ?? 'citizen', $age, false);

            $inputs = [
                'basic' => 0.0,
                'individual_earnings_total' => round($earnings->sum('amount'), 2),
                'individual_deductions_total' => round($deductions->sum('amount'), 2),
                'individual_earning_lines' => $earnings->map(fn (array $l) => [
                    'amount' => $l['amount'],
                    'epf_liable' => (bool) $l['item']->epf_liable,
                    'perkeso_liable' => false,
                    'hrdf_liable' => false,
                ])->values()->all(),
                'statutory_category' => $employee->statutoryCategory($periodEnd),
                'epf_part' => $epfPart,
                'skbbk_opt_in' => (bool) $structure->skbbk_opt_in,
                'hrdf_rate' => 0.0,
            ];
            $inputs = $this->withWageBaseFlags($inputs, $catalog);
            $comp = $this->calculator->compute($inputs);

            // PCB: the bonus is additional remuneration (spec D.b.2), taxed on top of the
            // month's normal pay — so the normal side of the calculation is the monthly
            // payslip for the same month when there is one, and a projection of it when
            // the bonus is paid before the monthly run exists. Only the additional half of
            // the result belongs to this payslip: the normal MTD is the monthly run's.
            [$normalGross, $normalEpf] = $this->normalRemunerationFor($employee, $run->period, $structure, $epfPart, $catalog);
            $result = $this->pcb->calculate($this->pcbInputsFor(
                $employee, $run->period, $structure,
                $normalGross, $normalEpf, $comp->gross, $comp->epfEmployee,
            ));
            $inputs['pcb'] = 0.0;
            $inputs['pcb_additional'] = $result->additionalMtd;
            $comp = $this->calculator->compute($inputs);

            $payslip = $run->payslips()->make(['employee_id' => $employee->id]);
            $payslip->forceFill($comp->toPayslipAttributes())->save();
            $payslip->lines()->createMany($this->individualLineAttrs($itLines, 0));
        }
    }

    /**
     * A mid-month run pays an advance on basic salary and nothing else: no allowances,
     * claims, overtime or unpaid leave, and no EPF, SOCSO, EIS, PCB, HRDF, zakat or CP38.
     * Statutory is worked out once, on the whole month, by the month-end run, which then
     * takes this advance back out of net pay (midMonthAdvanceFor()).
     *
     * Cutoff basis: salary × calendar days employed from the 1st to the cutoff day ÷ days
     * in the month. Percentage basis: salary × percent. Either way someone with no days
     * employed by the cutoff (day 15 for the percentage basis) gets no advance.
     *
     * Fixed and Individual Transactions HR set to the Mid Month cycle are paid here too,
     * still with no statutory. The month-end run counts them again in the full month's
     * gross and statutory, and its advance deduction (this payslip's net) evens it out.
     *
     * @param  Collection<int, Employee>  $employees
     * @param  Collection<string, PayrollItem>  $catalog
     */
    private function buildMidMonthPayslips(PayrollRun $run, Collection $employees, Collection $catalog): void
    {
        $byCutoff = $run->mid_month_basis === 'cutoff';
        $cutoffDate = $this->periodStart($run->period)->day($byCutoff ? (int) $run->mid_month_value : 15);

        foreach ($employees as $employee) {
            $lastDay = $this->lastWorkingDayFor($employee, $run->period);
            $days = Proration::days($run->period, $employee->joined_at, $lastDay !== null && $lastDay->lt($cutoffDate) ? $lastDay : $cutoffDate);
            if ($days['employed'] === 0) {
                continue;
            }
            $salary = (float) ($employee->salary ?? 0);
            $advance = $byCutoff
                ? Proration::prorate($salary, $days['employed'], $days['in_month'])
                : round($salary * (int) $run->mid_month_value / 100, 2);

            $ftLines = $this->fixedTransactionLines($employee, $run->period, 'mid_month');
            $itLines = $this->individualTransactionLinesForPeriod($employee, $run->period, false, 'mid_month');
            $sum = fn (Collection $lines, string $type) => round($lines->filter(fn (array $l) => $l['item']->type === $type)->sum('amount'), 2);

            // Empty wage-base lines put every statutory base at zero, so no EPF, SOCSO,
            // EIS or HRDF; PCB is never passed in.
            $comp = $this->calculator->compute([
                'basic' => $advance,
                'allowances_total' => $sum($ftLines, 'earning'),
                'fixed_deductions_total' => $sum($ftLines, 'deduction'),
                'individual_earnings_total' => $sum($itLines, 'earning'),
                'individual_deductions_total' => $sum($itLines, 'deduction'),
                'lines' => [],
                'overtime_flags' => [],
            ]);

            $payslip = $run->payslips()->make(['employee_id' => $employee->id]);
            $payslip->forceFill($comp->toPayslipAttributes() + [
                'days_employed' => $days['employed'],
                'days_in_month' => $days['in_month'],
            ])->save();
            $this->writePayslipLines($payslip, $comp, $ftLines, $itLines, $catalog);
        }
    }

    /** Net pay already handed to this employee in the period's mid-month run, which the month-end or final payslip takes back. */
    private function midMonthAdvanceFor(Employee $employee, string $period): float
    {
        return round((float) Payslip::where('employee_id', $employee->id)
            ->whereHas('payrollRun', fn ($q) => $q->where('period', $period)->where('kind', 'mid_month'))
            ->sum('net_pay'), 2);
    }

    /**
     * The normal remuneration (Y1) and its EPF (K1) that a bonus run's PCB has to sit on
     * top of: the same month's monthly payslip when one exists — draft or finalized, the
     * figures are the same money either way — otherwise a projection from the employee's
     * current salary and Fixed Transactions, exactly as createRun() would have computed
     * them. Nothing is saved.
     *
     * @param  Collection<string, PayrollItem>  $catalog
     * @return array{0: float, 1: float}
     */
    private function normalRemunerationFor(Employee $employee, string $period, ?SalaryStructure $structure, ?string $epfPart, Collection $catalog): array
    {
        $monthly = Payslip::where('employee_id', $employee->id)
            ->whereHas('payrollRun', fn ($q) => $q->where('period', $period)->where('kind', 'monthly'))
            ->first();

        if ($monthly !== null) {
            // Same split buildPcbInputs() makes: overtime and any bonus already on the
            // monthly payslip are not part of the EPF wage this K1 is taken on, and pay
            // covered by a yearly exemption cap is out of the taxable base (spec F8).
            $epfWage = max(0.0, round((float) $monthly->gross - (float) $monthly->bonus - (float) $monthly->overtime_amount, 2));

            return [
                round(max(0.0, (float) $monthly->gross - (float) $monthly->bonus - (float) $monthly->pcb_exempt_amount), 2),
                $this->epf->contribution($epfWage, $epfPart)['employee'],
            ];
        }

        $ftLines = $this->fixedTransactionLines($employee, $period);
        $fixedEarnings = $ftLines->filter(fn (array $l) => $l['item']->type === 'earning');
        $projected = $this->calculator->compute($this->withWageBaseFlags([
            'basic' => (float) ($employee->salary ?? 0),
            'allowances_total' => round($fixedEarnings->sum('amount'), 2),
            'fixed_earning_lines' => $fixedEarnings->map(fn (array $l) => [
                'amount' => $l['amount'],
                'epf_liable' => (bool) $l['item']->epf_liable,
                'perkeso_liable' => (bool) $l['item']->perkeso_liable,
                'hrdf_liable' => (bool) $l['item']->hrdf_liable,
            ])->values()->all(),
            'epf_part' => $epfPart,
            'skbbk_opt_in' => (bool) (($structure !== null ? $structure->skbbk_opt_in : null) ?? false),
        ], $catalog));

        return [$projected->gross, $projected->epfEmployee];
    }

    public function updatePayslip(Request $request, Payslip $payslip): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->assertTenant($payslip);
        abort_unless($payslip->payrollRun->isEditable(), 422, 'This payroll run is finalized and locked.');
        // Spec F10: a bonus payslip is nothing but the individual transactions flagged for
        // the bonus run — recomputing it through the monthly path would put SOCSO/EIS and
        // the levy back on it and tax the bonus as normal pay. Change the transaction and
        // regenerate the run instead.
        abort_if($payslip->payrollRun->isBonus(), 422, 'A bonus payslip is edited through its individual transaction — change that and create the bonus run again.');
        // A mid-month payslip is basic salary only, worked out from the run's basis.
        abort_if($payslip->payrollRun->isMidMonth(), 422, 'A mid-month payslip cannot be edited. Delete the mid-month run and create it again.');

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
            // Blank/absent = keep the generated (possibly prorated) basic; a value here
            // is HR's own figure and sticks until the run is regenerated.
            'basic' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
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
        $periodEnd = $this->periodStart($payslip->payrollRun->period)->endOfMonth();
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
        $rawBasic = $request->input('basic');
        $basicOverridden = $rawBasic !== null && $rawBasic !== '';

        $comp = DB::transaction(function () use (
            $request, $data, $payslip, $structure, $epfPart, $periodEnd,
            $overtimeOverridden, $rawOvertimeHours, $rawOvertimeMultiplier, $unpaidOverridden, $rawUnpaidDays,
            $basicOverridden, $rawBasic,
        ) {
            $overtimeRequests = ! $payslip->payrollRun->pulls('overtime') ? collect() : $this->pullableOvertimeFor(
                $payslip->employee, $payslip->payrollRun->period, $this->usedOvertimeIds($payslip->id)
            );
            $pulledOvertimeGroups = $this->overtimeGroups($overtimeRequests);
            $pulledOvertimeHours = round($overtimeRequests->sum(fn (OvertimeRequest $o) => (float) $o->hours), 2);
            $unpaidLeaveRequests = ! $payslip->payrollRun->pulls('unpaid') ? collect() : $this->pullableUnpaidLeaveFor(
                $payslip->employee, $payslip->payrollRun->period, $this->usedUnpaidLeaveIds($payslip->id)
            );
            $pulledUnpaidDays = round($unpaidLeaveRequests->sum(fn (LeaveRequest $l) => (float) $l->days), 2);

            $baseInputs = [
                'basic' => $basicOverridden ? (float) $rawBasic : $payslip->basic,
                'allowances_total' => $payslip->allowances_total,
                'fixed_deductions_total' => $payslip->fixed_deductions_total,
                'claims_reimbursement' => $payslip->claims_reimbursement,
                'bonus' => $data['bonus'] ?? 0,
                'statutory_category' => $payslip->employee->statutoryCategory($periodEnd),
                'epf_part' => $epfPart,
                'skbbk_opt_in' => (bool) $structure?->skbbk_opt_in,
                'socso_exempt' => (bool) $structure?->socso_exempt,
                // A payslip HR already carried forward keeps that policy across recomputes.
                'carry_forward' => $payslip->carried_forward_amount > 0,
                // Spec F7: citizens only, and not when HR has marked the employee exempt.
                'hrdf_rate' => (($structure->nationality ?? 'citizen') === 'citizen' && ! $structure->hrdf_exempt)
                    ? HrdCorpLevy::rate((string) app(FeatureManager::class)->value(app(CurrentTenant::class)->get(), 'payroll.hrdf'))
                    : 0.0,
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
                    $payslip->payrollRun->isBonus(),
                );
            }
            $individualLines = $this->individualTransactionLinesForPeriod($payslip->employee, $payslip->payrollRun->period, $payslip->payrollRun->isBonus());
            $individualEarnings = $individualLines->filter(fn (array $l) => $l['item']->type === 'earning');
            $individualDeductions = $individualLines->filter(fn (array $l) => $l['item']->type === 'deduction');
            $baseInputs['individual_earnings_total'] = round($individualEarnings->sum('amount'), 2);
            $baseInputs['individual_deductions_total'] = round($individualDeductions->sum('amount'), 2);
            $baseInputs['individual_earning_lines'] = $individualEarnings->map(fn (array $l) => [
                'amount' => $l['amount'],
                'epf_liable' => (bool) $l['item']->epf_liable,
                'perkeso_liable' => (bool) $l['item']->perkeso_liable,
                'hrdf_liable' => (bool) $l['item']->hrdf_liable,
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
                    'hrdf_liable' => (bool) (($l->payrollItem !== null ? $l->payrollItem->hrdf_liable : null) ?? false),
                ])->all();
            }

            $baseInputs = $this->withWageBaseFlags($baseInputs, $catalog);

            // First pass: gross + EPF, needed to split normal vs. additional remuneration
            // for PCB. Second pass: feed the computed PCB back in so it flows into net.
            $comp = $this->calculator->compute($baseInputs);
            $exemptThisMonth = $this->exemptThisMonth($payslip->employee, $payslip->payrollRun->period, [
                ...$fixedEarningLines->map(fn (PayslipLine $l) => ['item' => $l->payrollItem, 'amount' => (float) $l->amount])->values()->all(),
                ...$individualEarnings->map(fn (array $l) => ['item' => $l['item'], 'amount' => (float) $l['amount']])->values()->all(),
            ]);
            $result = $this->pcb->calculate($this->buildPcbInputs($payslip->employee, $payslip->payrollRun->period, $comp, $structure, $epfPart, $exemptThisMonth));
            $comp = $this->calculator->compute($baseInputs + [
                'pcb' => $result->netNormalMtd,
                'pcb_additional' => $result->additionalMtd,
                'zakat' => (float) ($structure->zakat_monthly ?? 0),
                'cp38' => PayrollCp38Month::amountFor($payslip->employee, $payslip->payrollRun->period),
                'mid_month_advance' => $this->midMonthAdvanceFor($payslip->employee, $payslip->payrollRun->period),
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
                'pcb_exempt_amount' => $exemptThisMonth,
                'overtime_request_ids' => $overtimeRequests->pluck('id')->all() ?: null,
                'pulled_overtime_hours' => $pulledOvertimeHours,
                'overtime_overridden' => $overtimeOverridden,
                'unpaid_leave_request_ids' => $unpaidLeaveRequests->pluck('id')->all() ?: null,
                'pulled_unpaid_days' => $pulledUnpaidDays,
                'unpaid_days_overridden' => $unpaidOverridden,
                'basic_overridden' => $basicOverridden || $payslip->basic_overridden,
            ])->save();
            $this->refreshVariableLines($payslip, $comp, $individualLines, $catalog);
            $this->syncCarriedForward($payslip, $comp);

            return $comp;
        });

        $this->recalcTotals($payslip->payrollRun);
        AuditLog::record('Updated payslip', $payslip->employee->name.' · '.$payslip->payrollRun->label);

        return redirect()->route('app.screen', ['screen' => 'payroll-review', 'tab' => 'individual', 'run' => $payslip->payroll_run_id, 'payslip' => $payslip->id])
            ->with('ok', 'Payslip updated for '.$payslip->employee->name.' (net RM '.number_format($comp->netPay, 2).').');
    }

    /** Spec F5: the one field that changes after finalize, set only here. */
    public function markPaid(Request $request, PayrollRun $run): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->assertTenant($run);
        abort_unless($run->status === 'finalized', 422, 'Only a finalized run can be marked paid.');
        abort_if($run->paid_at !== null, 422, 'Already marked paid.');

        $run->forceFill(['paid_at' => now()])->save();
        AuditLog::record('Marked payroll paid', $run->label);

        return back()->with('ok', $run->label.' marked paid.');
    }

    /** Spec F4: HR confirms the employee's written consent for deductions above the s.24 cap. */
    public function confirmDeductionConsent(Request $request, Payslip $payslip): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->assertTenant($payslip);
        abort_unless($payslip->payrollRun->isEditable(), 422, 'This payroll run is finalized and locked.');

        $payslip->forceFill(['deduction_consent_confirmed' => ! $payslip->deduction_consent_confirmed])->save();
        AuditLog::record($payslip->deduction_consent_confirmed ? 'Confirmed deduction consent' : 'Withdrew deduction consent', $payslip->employee->name.' · '.$payslip->payrollRun->label);

        return back()->with('ok', 'Consent '.($payslip->deduction_consent_confirmed ? 'recorded' : 'withdrawn').' for '.$payslip->employee->name.'.');
    }

    /**
     * Spec F4 "deduct next month": cap this month's deductions at gross, net becomes zero,
     * the shortfall is queued as next period's Individual Transaction (subject to the same
     * 50% cap there when that run is created).
     */
    /**
     * Spec F10: let a held final pay go. HR does this once LHDN clears the CP22A (or the
     * 90-day wait is over), which is a judgement call outside the system — so the reason
     * is required and audited, and the payslip keeps who released it and when.
     */
    public function releaseHold(Request $request, Payslip $payslip): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->assertTenant($payslip);
        abort_unless((bool) $payslip->held_for_cp22a, 422, 'This payslip is not being held.');

        $data = $request->validate(['reason' => ['required', 'string', 'max:240']]);

        $payslip->forceFill([
            'held_for_cp22a' => false,
            'hold_released_at' => now(),
            'hold_released_by_id' => Auth::id(),
        ])->save();

        AuditLog::record('Released final pay hold', $payslip->employee?->name.' · '.$payslip->payrollRun->label.' · '.$data['reason']);

        return back()->with('ok', 'Final pay released. It is in the bank file from now on.');
    }

    public function carryForward(Request $request, Payslip $payslip): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->assertTenant($payslip);
        abort_unless($payslip->payrollRun->isEditable(), 422, 'This payroll run is finalized and locked.');
        abort_if($payslip->payrollRun->isFinal(), 422, 'A final pay run has no next month to carry into. Lower the deductions on this payslip until net pay is zero or more, and recover the rest from the employee directly.');
        abort_unless($payslip->net_pay < 0, 422, 'Net pay is not negative.');

        DB::transaction(function () use ($payslip) {
            $shortfall = round(-$payslip->net_pay, 2);
            $payslip->forceFill([
                'total_deductions' => round($payslip->total_deductions - $shortfall, 2),
                'net_pay' => 0.0,
                'carried_forward_amount' => $shortfall,
            ])->save();
            $this->syncCarriedForward($payslip, null);
            $this->recalcTotals($payslip->payrollRun);
            AuditLog::record('Carried shortfall to next month', $payslip->employee->name.' · '.$payslip->payrollRun->label.' · RM '.number_format($shortfall, 2));
        });

        return back()->with('ok', 'Shortfall carried to next month for '.$payslip->employee->name.'.');
    }

    /**
     * Keep next period's carried-forward Individual Transaction equal to the payslip's
     * carried_forward_amount: create, update or remove it. Tagged by remark so a recompute
     * finds its own row and never touches a one-off HR typed.
     */
    private function syncCarriedForward(Payslip $payslip, ?PayslipComputation $comp): void
    {
        $amount = $comp !== null ? $comp->carriedForward : (float) $payslip->carried_forward_amount;
        if ($comp !== null) {
            $payslip->forceFill(['carried_forward_amount' => $amount])->save();
        }
        $nextPeriod = $this->periodStart($payslip->payrollRun->period)->addMonth()->format('Y-m');
        $remark = 'Carried forward from '.$payslip->payrollRun->label;
        $existing = IndividualTransaction::where('employee_id', $payslip->employee_id)->forPeriod($nextPeriod)->where('remarks', $remark)->first();

        if ($amount <= 0) {
            $existing?->delete();

            return;
        }
        $item = PayrollItem::where('tenant_id', $payslip->tenant_id)->where('code', 'other-deduction')->firstOrFail();
        if ($existing) {
            $existing->update(['amount' => $amount]);
        } else {
            IndividualTransaction::create(['employee_id' => $payslip->employee_id, 'payroll_item_id' => $item->id, 'period' => $nextPeriod, 'amount' => $amount, 'remarks' => $remark, 'created_by_id' => Auth::id()]);
        }
    }

    public function approveRun(Request $request, PayrollRun $run): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->assertTenant($run);
        abort_unless($run->status === 'draft', 422);

        // status is excluded from $fillable (lifecycle column) — set it directly.
        $run->forceFill(['status' => 'approved', 'approved_by_id' => Auth::id()])->save();
        AuditLog::record('Approved payroll run', $run->label);

        return redirect()->route('app.screen', ['screen' => 'payroll-payment', 'tab' => 'payout', 'run' => $run->id])->with('ok', $run->label.' payroll approved. Finalize to issue payslips.');
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

        // Spec F5: a pay date is required at finalize and must be within seven days of the
        // period end (EA s.19) unless HR gives a reason, which is audited.
        $data = $request->validate([
            'payment_date' => [$run->payment_date ? 'nullable' : 'required', 'date'],
            'pay_date_override_reason' => ['nullable', 'string', 'max:240'],
        ], ['payment_date.required' => 'Set the pay date before finalizing (EA s.19: wages are due within seven days of the period end).']);
        // Validation guarantees one of the two is present.
        $payDate = CarbonImmutable::parse($data['payment_date'] ?? $run->payment_date);
        $late = $payDate->gt($run->payByDate());
        $overrideReason = $data['pay_date_override_reason'] ?? null;
        if ($late && blank($overrideReason)) {
            return back()->withErrors(['payment_date' => 'Pay date '.$payDate->format('j M Y').' is later than the seventh day after the period end ('.$run->payByDate()->format('j M Y').'). EA s.19 requires payment within seven days; give a reason to override.'])->withInput();
        }

        // Spec F4 guards: a payslip over the s.24 deduction cap needs recorded consent, and
        // a negative net is not payable until HR carries the shortfall forward.
        $slips = $run->payslips()->with('employee')->get();
        $unconsented = $slips->filter(fn (Payslip $p) => $p->deduction_cap_exceeded && ! $p->deduction_consent_confirmed);
        abort_if($unconsented->isNotEmpty(), 422, 'Deductions exceed 50% of wages (EA s.24) without recorded consent for: '.$unconsented->map(fn (Payslip $p) => $p->employee->name)->implode(', ').'.');
        $negative = $slips->filter(fn (Payslip $p) => $p->net_pay < 0);
        abort_if($negative->isNotEmpty(), 422, 'Net pay is negative for: '.$negative->map(fn (Payslip $p) => $p->employee->name)->implode(', ').'. Carry the shortfall to next month first.');

        DB::transaction(function () use ($run, $payDate, $late, $overrideReason) {
            // status + finalized_at are excluded from $fillable — set them directly.
            $run->forceFill([
                'status' => 'finalized',
                'finalized_at' => now(),
                'approved_by_id' => $run->approved_by_id ?? Auth::id(),
                'payment_date' => $payDate->toDateString(),
                'pay_date_override_reason' => $late ? $overrideReason : null,
            ])->save();
            if ($late) {
                AuditLog::record('Pay date later than seven days', $run->label.' · '.$payDate->format('j M Y').' · '.$overrideReason);
            }

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

            // Spec F10/F11: a leaver is paid out for good here — no later monthly run
            // picks them up — and the money itself waits while the CP22A is unsettled.
            if ($run->isFinal()) {
                foreach ($payslips as $payslip) {
                    $employee = $payslip->employee;
                    if ($employee === null) {
                        continue;
                    }
                    $payslip->forceFill(['held_for_cp22a' => $this->notices->holdsFinalPay($employee)])->save();
                    $employee->forceFill(['final_pay_run_id' => $run->id])->save();
                    if ($payslip->held_for_cp22a) {
                        AuditLog::record('Held final pay for CP22A', $employee->name.' · '.$run->label.' · released by hand once LHDN clears the CP22A');
                    }
                }
            }

            // Spec F12: open this month's agency filings so the Deadlines tab and the
            // reminder digest have something to track from the moment the run is closed.
            // A mid-month advance carries no statutory, so it owes no filing of its own.
            $tenant = app(CurrentTenant::class)->get();
            if ($tenant !== null && ! $run->isMidMonth()) {
                $this->calendar->openFor($run, $tenant);
            }

            AuditLog::record('Finalized payroll run', $run->label.' · '.$payslips->count().' payslips issued');
        });

        // Spec F13: staff see nothing until the run is published. HR may do both at once.
        if ($request->boolean('publish_now')) {
            $this->publish($run);

            return redirect()->route('app.screen', ['screen' => 'payroll-payment', 'tab' => 'payout', 'run' => $run->id])->with('ok', $run->label.' payroll finalized, payslips published and employees notified.');
        }

        return redirect()->route('app.screen', ['screen' => 'payroll-payment', 'tab' => 'payout', 'run' => $run->id])->with('ok', $run->label.' payroll finalized. Publish it when staff should see their payslips.');
    }

    /**
     * Spec F13: release the payslips of a finalized run to staff. Separate from finalize
     * so HR can close the figures, check the bank file and only then let everyone see
     * their slip. Once only — a second publish would re-notify everybody.
     */
    public function publishRun(Request $request, PayrollRun $run): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->assertTenant($run);
        abort_unless($run->status === 'finalized', 422, 'Only a finalized run can be published.');
        abort_if($run->isPublished(), 422, 'This run has already been published.');

        $this->publish($run);

        return redirect()->route('app.screen', ['screen' => 'payroll-payment', 'tab' => 'payout', 'run' => $run->id])->with('ok', $run->label.' payslips published and employees notified.');
    }

    /**
     * Stamps published_at and sends the in-app notice to every employee with a login
     * (an employee with no user account gets nothing and HR hands over the PDF). The
     * email waits for the 5th of the month, see the payroll:payslip-ready command.
     */
    private function publish(PayrollRun $run): void
    {
        $payslips = $run->payslips()->with('employee')->get();

        DB::transaction(function () use ($run, $payslips) {
            $run->forceFill(['published_at' => now()])->save();

            foreach ($payslips as $payslip) {
                $userId = $payslip->employee?->user_id;
                if ($userId === null) {
                    continue;
                }
                AppNotification::send(
                    $userId,
                    'Payslip ready',
                    'Your '.$run->label.' payslip is available · net RM '.number_format($payslip->net_pay, 2),
                    route('app.screen', 'payroll-my'),
                );
            }

            AuditLog::record('Published payroll run', $run->label.' · '.$payslips->count().' payslips released to staff');
        });
    }

    /**
     * Delete a payroll run and every payslip/line it generated. A draft run is cheap to
     * remove — nothing outside it has been touched yet — so any HR/management operator
     * may delete it after a plain confirm(). A finalized run already consumed real
     * records (claims marked paid, overtime/unpaid-leave requests marked paid_at), so
     * deleting it must undo every one of those consumptions or they are lost for good —
     * see the reversal block below, which mirrors finalizeRun()'s consumption line for
     * line. Restricted to Permissions::MANAGEMENT_TIER and gated behind typing the exact
     * period back, on top of the browser confirm() every destructive action here already
     * gets — see payroll.blade.php.
     */
    public function destroyRun(Request $request, PayrollRun $run): RedirectResponse
    {
        $this->assertTenant($run);
        $wasFinalized = $run->status === 'finalized';

        if ($wasFinalized) {
            $this->authorizeTenantRole($request, Permissions::MANAGEMENT_TIER);
            $data = $request->validate(['confirm_period' => ['required', 'string']]);
            abort_unless($data['confirm_period'] === $run->period, 422, 'Type the period exactly ('.$run->period.') to confirm deleting a finalized run.');
        } else {
            $this->authorizeAdmin($request);
        }

        // Spec F12: once a filing has gone to an agency the run behind it is history.
        $filed = PayrollSubmission::where('payroll_run_id', $run->id)->whereNotNull('submitted_at')->exists();
        abort_if($filed, 422, 'This run has already been filed with an agency; it cannot be deleted.');
        // The month-end run has already taken this advance back out of net pay.
        abort_if($run->isMidMonth() && PayrollRun::where('tenant_id', $run->tenant_id)->where('period', $run->period)->where('kind', 'monthly')->exists(),
            422, 'The '.$run->period.' month-end run already deducts this mid-month advance. Delete the month-end run first.');

        $label = $run->label;
        $period = $run->period;

        DB::transaction(function () use ($run, $wasFinalized) {
            $payslips = $run->payslips()->get();

            if ($wasFinalized) {
                // Reverse every consumption finalizeRun() made, so each source record is
                // free to be picked up by a future run again — mirrors finalizeRun()'s
                // three consumption blocks exactly, in reverse.
                $claimIds = $payslips->flatMap(fn ($p) => $p->claim_ids ?? [])->unique()->values();
                if ($claimIds->isNotEmpty()) {
                    Claim::whereIn('id', $claimIds)->where('status', 'paid')
                        ->update(['status' => 'approved', 'paid_at' => null]);
                }
                $overtimeIds = $payslips->flatMap(fn ($p) => $p->overtime_request_ids ?? [])->unique()->values();
                if ($overtimeIds->isNotEmpty()) {
                    OvertimeRequest::whereIn('id', $overtimeIds)->update(['paid_at' => null]);
                }
                $unpaidLeaveIds = $payslips->flatMap(fn ($p) => $p->unpaid_leave_request_ids ?? [])->unique()->values();
                if ($unpaidLeaveIds->isNotEmpty()) {
                    LeaveRequest::whereIn('id', $unpaidLeaveIds)->update(['paid_at' => null]);
                }
            }

            foreach ($payslips as $payslip) {
                $payslip->lines()->delete();
            }
            PayrollSubmission::where('payroll_run_id', $run->id)->delete();
            $run->payslips()->delete();
            $run->delete();
        });

        // Loud on purpose for a finalized run — this is the one action in payroll that
        // unwinds records other parts of the app already treated as settled.
        AuditLog::record(
            $wasFinalized ? 'DELETED FINALIZED PAYROLL RUN' : 'Deleted draft payroll run',
            $label.' ('.$period.')'.($wasFinalized ? ' · claims/overtime/unpaid-leave reversed to unpaid' : ''),
        );

        return redirect()->route('app.screen', ['screen' => 'payroll-payment', 'tab' => 'payout'])->with('ok', $label.' payroll run deleted.');
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
    private function buildPcbInputs(Employee $employee, string $period, PayslipComputation $comp, ?SalaryStructure $structure, ?string $epfPart, float $exemptThisMonth = 0.0): PcbInputs
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

        return $this->pcbInputsFor(
            $employee, $period, $structure,
            // Spec F8: the part of this month's pay covered by a Payroll Item's yearly
            // exemption cap is out of the PCB base (Y1) and nothing else.
            round(max(0.0, $comp->gross - $bonus - $exemptThisMonth), 2),
            $k1, $bonus, $kt,
            $category,
        );
    }

    /**
     * The PcbInputs for one employee and month once the four money figures are known:
     * Y1/K1 (this month's normal remuneration and its EPF) and Yt/Kt (this month's
     * additional remuneration and its EPF). Split out of buildPcbInputs() so a bonus run
     * — whose Y1 comes from the monthly payslip rather than from its own payslip — reuses
     * the same year-to-date, relief and category wiring instead of a second copy of it.
     */
    private function pcbInputsFor(Employee $employee, string $period, ?SalaryStructure $structure, float $y1, float $k1, float $yt, float $kt, ?int $category = null): PcbInputs
    {
        $category ??= PcbCalculator::categoryFor($employee, $structure);
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
            currentGrossY1: $y1,
            currentEpfK1: $k1,
            monthsRemainingAfterCurrent: $n,
            ytdZakatZ: $ytd['zakatZ'],
            ytdMtdPaidX: $ytd['mtdPaidX'],
            // $structure is genuinely nullable — Larastan false-positives
            // "nullsafe.neverNull" on ?-> below, so these are written as explicit null
            // checks to sidestep that rather than silence it.
            // Spec F8: zakat deducted from pay plus zakat the employee declared on TP1
            // (paid straight to Pusat Zakat, so it never appears as a payslip deduction).
            currentZakat: round((float) (($structure !== null ? $structure->zakat_monthly : null) ?? 0) + $ytd['currentZakat'], 2),
            disabledIndividual: (bool) (($structure !== null ? $structure->disabled_self : null) ?? false),
            // No spouse relief at all for category 1 (single) — see PcbCalculator::reliefs().
            disabledSpouse: $category !== 1 && (bool) (($structure !== null ? $structure->disabled_spouse : null) ?? false),
            qualifyingChildren: (int) (($structure !== null ? $structure->children_relief_count : null) ?? 0),
            ytdOptionalDeductions: $ytd['optionalDeductions'],
            currentOptionalDeductions: $ytd['currentOptionalDeductions'],
            currentAdditionalGrossYt: $yt,
            currentAdditionalEpfKt: $kt,
        );
    }

    /**
     * Spec F8: how much of this month's earnings falls under a Payroll Item's yearly
     * tax-exempt cap. Only the PCB base moves — the EPF and PERKESO wage bases are
     * separate statutory concepts and are deliberately left alone.
     *
     * @param  array<int, array{item: ?PayrollItem, amount: float}>  $earningLines
     */
    private function exemptThisMonth(Employee $employee, string $period, array $earningLines): float
    {
        $exempt = 0.0;
        foreach ($earningLines as $line) {
            $item = $line['item'];
            if ($item === null || $item->pcb_exempt_cap_yearly === null || ! $item->pcb_taxable) {
                continue;
            }
            $amount = round($line['amount'], 2);
            $taxable = ExemptionCap::taxableThisMonth($amount, $this->pcbYtd->exemptUsed($employee, $period, $item), $item->pcb_exempt_cap_yearly);
            $exempt += max(0.0, $amount - $taxable);
        }

        return round($exempt, 2);
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
                // Spec F7: the levy's wage base is basic pay plus fixed allowances.
                'hrdf_liable' => $item ? (bool) $item->hrdf_liable : in_array($code, ['basic-salary', 'fixed-allowance'], true),
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
        if ($comp->midMonthAdvance > 0) {
            $lines[] = $this->lineAttrs($catalog, 'mid-month-advance', 'Mid-month advance', 'deduction', $comp->midMonthAdvance, null, 'manual', $sort++);
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
            'prorate_on_incomplete_month' => ['boolean'],
            'hrdf_liable' => ['boolean'],
            'pcb_taxable' => ['boolean'],
            'active' => ['boolean'],
        ]);

        $item->update([
            'name' => $data['name'],
            'name_ms' => $data['name_ms'] ?? null,
            'epf_liable' => $request->boolean('epf_liable'),
            'perkeso_liable' => $request->boolean('perkeso_liable'),
            'prorate_on_incomplete_month' => $request->boolean('prorate_on_incomplete_month'),
            'hrdf_liable' => $request->boolean('hrdf_liable'),
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
