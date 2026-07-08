<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\DataScope;
use App\Support\Csv;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /** Stream the tenant's employee roster as CSV (scoped by the global tenant scope). */
    public function exportEmployees(Request $request): StreamedResponse
    {
        // The full roster export (incl. email + KPI) is a manager/HR action.
        $this->authorizeTenantRole($request, ['manager', 'management', 'hr']);

        $filename = 'employees-'.now()->format('Y-m-d').'.csv';

        // Data scope: a branch/department-restricted manager exports only their slice of
        // the roster, not the whole company (AK-AUTHZ-01).
        $scope = $request->attributes->get('tenantScope', 'company');
        $self = $request->attributes->get('employee');
        $roster = app(DataScope::class)->applyToEmployees(Employee::active(), $scope, $self);

        return response()->streamDownload(function () use ($roster) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Name', 'Email', 'Position', 'Department', 'Branch', 'Level', 'Status', 'Workload', 'KPI %']);

            $roster->with(['department', 'branch'])
                ->withCount(['workItems as open_items_count' => fn ($q) => $q->where('status', '!=', 'done')])
                ->orderBy('name')->chunk(200, function ($chunk) use ($out) {
                foreach ($chunk as $e) {
                    // Every user-controlled column is neutralised against CSV formula injection.
                    fputcsv($out, Csv::safeRow([
                        $e->name, $e->email, $e->position,
                        $e->department?->name, $e->branch?->name, $e->level,
                        $e->status, $e->workload_label, $e->kpi_pct,
                    ]));
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
