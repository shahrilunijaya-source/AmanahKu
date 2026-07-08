<?php

namespace Database\Seeders;

use App\Models\ComplianceItem;
use App\Models\Employee;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class ComplianceSeeder extends Seeder
{
    /**
     * Seed 6 compliance items spread across expiry buckets relative to app-now
     * (mid-2026) for the first tenant's employees. Safe to re-run: skips if the
     * first tenant already has items, and guards against tenants with no employees.
     */
    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('id')->first();
        if (! $tenant) {
            return;
        }

        $tid = $tenant->id;

        // Idempotent: global scope is inactive in seeders, so scope to the tenant.
        if (ComplianceItem::where('tenant_id', $tid)->exists()) {
            return;
        }

        $employees = Employee::where('tenant_id', $tid)->orderBy('id')->take(6)->get();
        if ($employees->isEmpty()) {
            return;
        }

        $now = Carbon::create(2026, 6, 24);

        // [employee index, type, name, identifier, issuer, issued offset days, expiry offset days]
        $plan = [
            [2, 'license', 'Forklift License', 'FL-2023-8841', 'JKKP Malaysia', -740, -40],     // expired
            [3, 'certification', 'First Aid Certification', 'FA-MRC-5567', 'Malaysian Red Crescent', -345, 20],  // ≤30
            [4, 'permit', 'Foreign Worker Permit', 'PLKS-2024-119', 'Jabatan Imigresen', -315, 50],   // ≤60
            [0, 'certification', 'CIDB Green Card', 'GC-771204-01', 'CIDB Malaysia', -280, 85],   // ≤90
            [1, 'certification', 'Professional Engineer (BEM)', 'PE-2019-3320', 'Board of Engineers Malaysia', -1100, 210], // valid
            [5, 'license', 'Commercial Driving License (GDL)', 'GDL-MY-4402', 'JPJ Malaysia', -600, 365],  // valid
        ];

        foreach ($plan as $row) {
            $employee = $employees->get($row[0]);
            if (! $employee) {
                continue;
            }

            ComplianceItem::create([
                'tenant_id' => $tid,
                'employee_id' => $employee->id,
                'type' => $row[1],
                'name' => $row[2],
                'identifier' => $row[3],
                'issuer' => $row[4],
                'issued_at' => $now->copy()->addDays($row[5]),
                'expires_at' => $now->copy()->addDays($row[6]),
            ]);
        }
    }
}
