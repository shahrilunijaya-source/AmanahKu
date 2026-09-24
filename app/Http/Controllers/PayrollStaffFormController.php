<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayrollFormOverride;
use App\Models\Tenant;
use App\Services\Payroll\StaffFormData;
use App\Tenancy\CurrentTenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Payroll → Form's LHDN staff forms (CP21, CP22, CP22A, PCB II): saving what HR typed
 * over the filled-in values, and the batch PDF of one form for everyone listed. Also
 * PERKESO's Borang SIP 2 for new hires.
 */
class PayrollStaffFormController extends Controller
{
    /** @var list<string> */
    private const ADMIN_ROLES = ['management', 'hr'];

    public function __construct(private readonly StaffFormData $forms) {}

    public function update(Request $request, Employee $employee, string $form, int $year): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
        $tenant = app(CurrentTenant::class)->get();
        abort_unless($tenant !== null && $employee->tenant_id === $tenant->id, 403);
        abort_unless(in_array($form, StaffFormData::FORMS, true), 404);

        $data = $request->validate(['fields' => ['array'], 'fields.*' => ['nullable', 'string', 'max:500']]);
        $before = $this->values($tenant, $employee, $form, $year, true);
        $filled = $this->values($tenant, $employee, $form, $year, false);
        $typed = array_intersect_key($data['fields'] ?? [], $filled);

        // Only a value that differs from what the records fill in is kept as an override,
        // so a later change in the records still flows through untouched fields.
        $kept = array_filter($typed, fn ($v, $k) => (string) $v !== (string) $filled[$k], ARRAY_FILTER_USE_BOTH);
        PayrollFormOverride::updateOrCreate(
            ['employee_id' => $employee->id, 'form' => $form, 'year' => $year],
            ['fields' => array_map(fn ($v) => (string) $v, $kept)],
        );

        $changed = array_keys(array_filter($typed, fn ($v, $k) => (string) $v !== (string) ($before[$k] ?? ''), ARRAY_FILTER_USE_BOTH));
        if ($changed !== []) {
            AuditLog::record('Edited '.StaffFormData::TITLES[$form], $employee->name.' · '.$year.' · '.implode(', ', $changed));
        }

        return back()->with('ok', StaffFormData::TITLES[$form].' saved.');
    }

    /** One PDF, one form per page, for everyone the form lists that year. Carries NRICs, so audited. */
    public function batchPdf(Request $request, string $form, int $year): Response
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
        $tenant = app(CurrentTenant::class)->get();
        abort_if($tenant === null, 403);
        abort_unless(in_array($form, StaffFormData::FORMS, true), 404);

        $staff = $this->forms->staff($tenant, $form, $year);
        abort_if($staff->isEmpty(), 404);

        AuditLog::record('Downloaded '.StaffFormData::TITLES[$form].' batch', $year.' · '.$staff->count().' staff');

        return Pdf::loadView('pdf.staff-forms', [
            'title' => StaffFormData::TITLES[$form],
            'year' => $year,
            'forms' => $staff->map(fn (Employee $e) => $this->forms->build($tenant, $e, $form, $year))->all(),
        ])->download(strtolower(str_replace(' ', '-', StaffFormData::TITLES[$form])).'-'.$year.'.pdf');
    }

    /** PERKESO Borang SIP 2 for the staff picked on the SIP 2 tab, ten to a page as the form has it. */
    public function sip2Pdf(Request $request): Response
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
        $tenant = app(CurrentTenant::class)->get();
        abort_if($tenant === null, 403);
        $ids = $request->validate(['employees' => ['required', 'array', 'max:500'], 'employees.*' => ['integer']])['employees'];

        $staff = Employee::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereIn('id', $ids)
            ->orderBy('joined_at')->orderBy('name')->get();
        abort_if($staff->isEmpty(), 404);

        AuditLog::record('Downloaded Borang SIP 2', $staff->count().' staff');

        return Pdf::loadView('pdf.sip2', ['tenant' => $tenant, 'pages' => $staff->chunk(10)->map->values()->all()])
            ->setPaper('a4', 'landscape')->download('borang-sip2-'.now()->format('Ymd').'.pdf');
    }

    /** @return array<string, ?string> */
    private function values(Tenant $tenant, Employee $employee, string $form, int $year, bool $withOverrides): array
    {
        return collect($this->forms->build($tenant, $employee, $form, $year, $withOverrides)['sections'])
            ->flatMap(fn (array $s) => array_column($s['fields'], 'value', 'key'))->all();
    }
}
