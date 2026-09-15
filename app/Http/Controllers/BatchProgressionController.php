<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Services\EmploymentRecordService;
use App\Services\EmploymentTransitionException;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Batch Progression Update and Batch Salary Adjustment (Progression screen).
 * The whole run sits inside one transaction: the service's per-person transaction
 * becomes a savepoint, so one refused person rolls back everyone.
 */
class BatchProgressionController extends EmploymentRecordController
{
    public const BATCH_FIELDS = ['department_id', 'branch_id', 'division', 'section', 'position_id', 'reports_to_id', 'employment_type_id', 'payment_term', 'payment_method'];

    public const MAX_ROWS = 200;

    public function batchUpdate(Request $request, EmploymentRecordService $service): RedirectResponse
    {
        $this->authorizeTenantRole($request, ['management', 'hr']);
        $tenantId = app(CurrentTenant::class)->id();

        $data = $request->validate($this->batchRules($tenantId) + [
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => [Rule::in(self::BATCH_FIELDS)],
        ] + array_intersect_key($this->rules($tenantId), array_flip(self::BATCH_FIELDS)));

        // Only the ticked fields travel; an empty value on a ticked field clears it.
        $fields = [];
        foreach ($data['fields'] as $key) {
            $fields[$key] = ($data[$key] ?? '') === '' ? null : $data[$key];
        }

        return $this->run($request, 'update', $data['employee_ids'], function (Employee $e) use ($service, $data, $fields, $request): void {
            if (($fields['reports_to_id'] ?? null) !== null && (int) $fields['reports_to_id'] === $e->id) {
                throw new EmploymentTransitionException($e->name.' cannot report to themselves.');
            }
            $service->update($e, $data['effective_on'], $fields, $data['remark'] ?? null, $request->attributes->get('employee'));
        }, 'Batch progression update');
    }

    public function batchSalary(Request $request, EmploymentRecordService $service): RedirectResponse
    {
        $this->authorizeTenantRole($request, ['management', 'hr']);
        abort_unless($this->hasTenantRole($request, ['director', 'hr']), 403);
        $tenantId = app(CurrentTenant::class)->id();

        $data = $request->validate($this->batchRules($tenantId) + [
            'mode' => ['required', 'in:increase_amount,increase_percent,set_amount'],
            'value' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'override_band' => ['nullable', 'boolean'],
        ]);
        $override = (bool) ($data['override_band'] ?? false);

        return $this->run($request, 'salary', $data['employee_ids'], function (Employee $e) use ($service, $data, $override, $request): void {
            $new = self::adjust((float) ($e->salary ?? 0), $data['mode'], (float) $data['value']);
            $max = (float) ($e->positionBand?->max_salary ?? 0);
            if ($max > 0 && $new > $max) {
                if (! $override) {
                    throw new EmploymentTransitionException($e->name.': RM '.number_format($new, 2).' is above the '.$e->positionBand->title.' band maximum of RM '.number_format($max, 2).'. Tick "override band" to allow it.');
                }
                AuditLog::record('Batch salary band override', $e->name.' · RM '.number_format($new, 2).' > RM '.number_format($max, 2));
            }
            $service->update($e, $data['effective_on'], ['salary' => $new], $data['remark'] ?? null, $request->attributes->get('employee'));
        }, 'Batch salary adjustment');
    }

    /** New salary for one mode; always 2 dp. */
    public static function adjust(float $current, string $mode, float $value): float
    {
        return round(match ($mode) {
            'increase_amount' => $current + $value,
            'increase_percent' => $current * (1 + $value / 100),
            default => $value,
        }, 2);
    }

    /** @return array<string, array<int, mixed>> */
    private function batchRules(int $tenantId): array
    {
        return [
            'employee_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],
            'employee_ids.*' => ['integer', 'distinct', Rule::exists('employees', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('archived_at'))],
            'effective_on' => ['required', 'date'],
            'remark' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @param  list<int>  $ids
     * @param  \Closure(Employee): void  $each
     */
    private function run(Request $request, string $batch, array $ids, \Closure $each, string $summary): RedirectResponse
    {
        $people = Employee::with('positionBand')->whereIn('id', $ids)->get();

        try {
            DB::transaction(function () use ($people, $each): void {
                foreach ($people as $e) {
                    $each($e);
                }
            });
        } catch (EmploymentTransitionException $ex) {
            return back()->withInput()->withErrors(['employee_ids' => $ex->getMessage()]);
        }

        AuditLog::record($summary, $people->count().' staff · effective '.$request->input('effective_on'));

        return redirect(route('app.screen', 'progression').'?batch='.$batch)->with('ok', $summary.' saved for '.$people->count().' staff.');
    }
}
