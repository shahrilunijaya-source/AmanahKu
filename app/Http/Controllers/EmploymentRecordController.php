<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksReportingCycles;
use App\Models\Employee;
use App\Services\EmploymentRecordService;
use App\Services\EmploymentTransitionException;
use App\Services\Payroll\BackPay;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Employment tab save on the profile. Progression actions live in ProgressionController. */
class EmploymentRecordController extends Controller
{
    use ChecksReportingCycles;

    public function update(Request $request, Employee $employee, EmploymentRecordService $service): RedirectResponse
    {
        $this->authorizeTenantRole($request, ['management', 'hr']);
        $tenantId = app(CurrentTenant::class)->id();
        abort_unless($employee->tenant_id === $tenantId, 403);

        $data = $request->validate($this->rules($tenantId, $employee) + ['effective_on' => ['required', 'date']]);
        $fields = self::fields($data, $this->hasTenantRole($request, ['director', 'hr']));

        try {
            $service->update($employee, $data['effective_on'], $fields, $data['employment_remark'] ?? null, $request->attributes->get('employee'));
        } catch (EmploymentTransitionException $ex) {
            return back()->withInput()->withErrors(['effective_on' => $ex->getMessage()]);
        }

        return redirect(route('app.screen', 'profile').'?emp='.$employee->id.'&tab=employment')->with('ok', $employee->name.' updated.'.BackPay::noteSuffix());
    }

    /**
     * Shared with ProgressionController: the Worksy employment field set.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function rules(int $tenantId, ?Employee $self = null): array
    {
        $inTenant = fn (string $table) => Rule::exists($table, 'id')->where('tenant_id', $tenantId);

        return [
            'department_id' => ['nullable', 'integer', $inTenant('departments')],
            'branch_id' => ['nullable', 'integer', $inTenant('branches')],
            'position_id' => ['nullable', 'integer', $inTenant('positions')],
            'employment_type_id' => ['nullable', 'integer', $inTenant('employment_types')],
            'reports_to_id' => [
                'nullable', 'integer', Rule::notIn([$self?->id]),
                Rule::exists('employees', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('archived_at')),
                function (string $attribute, mixed $value, \Closure $fail) use ($self): void {
                    if ($self && $value && $this->wouldCycle($self->id, (int) $value)) {
                        $fail('That manager already reports to this person — it would create a loop.');
                    }
                },
            ],
            'division' => ['nullable', 'string', 'max:80'],
            'section' => ['nullable', 'string', 'max:80'],
            'job_grade' => ['nullable', 'string', 'max:40'],
            'category' => ['nullable', 'string', 'max:40'],
            'line' => ['nullable', 'string', 'max:40'],
            'probation_months' => ['nullable', 'integer', 'between:0,24'],
            'probation_days' => ['nullable', 'integer', 'between:0,31'],
            'resign_notice_months' => ['nullable', 'integer', 'between:0,24'],
            'resign_notice_days' => ['nullable', 'integer', 'between:0,31'],
            'short_notice_months' => ['nullable', 'integer', 'between:0,24'],
            'short_notice_days' => ['nullable', 'integer', 'between:0,31'],
            'salary' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'pay_mode' => ['nullable', 'in:monthly,daily,hourly'],
            'payment_term' => ['nullable', 'in:daily,weekly,biweekly,monthly'],
            'payment_method' => ['nullable', 'in:cash,bank,cheque'],
            'employment_remark' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Strip salary unless the caller may set it; empty strings become null.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fields(array $data, bool $canSetSalary): array
    {
        $fields = array_intersect_key($data, array_flip(Employee::EMPLOYMENT_FIELDS));
        if (! $canSetSalary) {
            unset($fields['salary']);
        }

        return array_map(fn ($v) => $v === '' ? null : $v, $fields);
    }
}
