<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\KpiItem;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class KpiController extends Controller
{
    /** An employee records progress against one of their own KPI objectives. */
    public function update(Request $request, KpiItem $kpiItem): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');
        abort_unless($kpiItem->tenant_id === app(CurrentTenant::class)->id(), 403);
        abort_unless($kpiItem->employee_id === $employee->id, 403);

        $data = $request->validate([
            'actual' => ['nullable', 'string', 'max:60'],
            'progress' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $kpiItem->update([
            'actual' => $data['actual'] ?? $kpiItem->actual,
            'progress' => $data['progress'],
            'status' => $data['progress'] >= 80 ? 'green' : ($data['progress'] >= 50 ? 'amber' : 'red'),
        ]);

        return back()->with('ok', 'KPI progress updated.');
    }
}
