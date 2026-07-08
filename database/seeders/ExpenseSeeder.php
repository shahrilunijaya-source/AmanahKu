<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\ExpenseReport;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class ExpenseSeeder extends Seeder
{
    /**
     * Seed 2-3 expense reports (each with 2-4 lines, mixed statuses) for the
     * Unijaya tenant's employees. Safe to re-run: skips if that tenant already
     * has reports, and guards against a missing tenant or empty employee list.
     * No tenant session exists in seeders, so tenant_id is set explicitly.
     */
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'unijaya')->first();
        if (! $tenant) {
            return;
        }

        $tid = $tenant->id;

        // Global scope is inactive in seeders, so scope to the tenant explicitly.
        if (ExpenseReport::where('tenant_id', $tid)->exists()) {
            return;
        }

        $employees = Employee::where('tenant_id', $tid)->orderBy('id')->take(3)->get();
        if ($employees->isEmpty()) {
            return;
        }

        // [employee index, title, period, status, [ [date, category, description, amount], ... ] ]
        $plan = [
            [0, 'June client visits', 'June 2026', 'submitted', [
                ['2026-06-03', 'Travel', 'Flight KL–Penang return', 480.00],
                ['2026-06-03', 'Accommodation', 'Hotel — 1 night', 220.00],
                ['2026-06-04', 'Meals', 'Client lunch', 95.50],
            ]],
            [1, 'May field expenses', 'May 2026', 'approved', [
                ['2026-05-12', 'Mileage', 'Site survey — 120km', 72.00],
                ['2026-05-18', 'Office Supplies', 'Printer toner', 145.00],
            ]],
            [2, 'Conference & training', 'June 2026', 'draft', [
                ['2026-06-10', 'Travel', 'Grab to venue', 38.00],
                ['2026-06-10', 'Meals', 'Breakfast', 24.00],
                ['2026-06-11', 'Accommodation', 'Hostel — 2 nights', 180.00],
                ['2026-06-11', 'Other', 'Workshop materials', 60.00],
            ]],
        ];

        foreach ($plan as $row) {
            $employee = $employees->get($row[0]);
            if (! $employee) {
                continue;
            }

            $report = ExpenseReport::create([
                'tenant_id' => $tid,
                'employee_id' => $employee->id,
                'title' => $row[1],
                'period_label' => $row[2],
                'status' => $row[3],
                'submitted_at' => $row[3] === 'draft' ? null : now(),
                'decided_at' => $row[3] === 'approved' ? now() : null,
                'decided_by_id' => $row[3] === 'approved' ? $employee->id : null,
            ]);

            foreach ($row[4] as $line) {
                $report->lines()->create([
                    'tenant_id' => $tid,
                    'expense_date' => $line[0],
                    'category' => $line[1],
                    'description' => $line[2],
                    'amount' => $line[3],
                ]);
            }

            // Sum lines and store on the report (global scope inactive — sum by tenant + report).
            $report->update([
                'total' => $report->lines()->where('tenant_id', $tid)->sum('amount'),
            ]);
        }
    }
}
