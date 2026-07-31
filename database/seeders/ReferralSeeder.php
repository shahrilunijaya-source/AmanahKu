<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\JobRequisition;
use App\Models\Referral;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class ReferralSeeder extends Seeder
{
    /**
     * Seed ~4 employee referrals across statuses for the first tenant's employees.
     * Safe to re-run: skips entirely if the first tenant already has referrals, and
     * guards against tenants with no employees. Links candidates to existing open
     * requisitions where available; leaves job_requisition_id null otherwise.
     */
    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('id')->first();
        if (! $tenant) {
            return;
        }

        $tid = $tenant->id;

        // Idempotent-ish: global scope is inactive in seeders, so scope to the tenant.
        if (Referral::where('tenant_id', $tid)->exists()) {
            return;
        }

        $employees = Employee::where('tenant_id', $tid)->orderBy('id')->take(3)->get();
        if ($employees->isEmpty()) {
            return;
        }

        // Recruitment owns requisitions — only link to any that already exist.
        $openRoles = JobRequisition::where('tenant_id', $tid)->where('status', 'open')->orderBy('id')->get();

        // [employee index, name, email, phone, status, bonus_eligible, bonus_status, link role?]
        $plan = [
            [0, 'Nurul Aziz', 'nurul.aziz@example.com', '012-3456789', 'submitted', false, 'none', true],
            [1, 'Daniel Tan', 'daniel.tan@example.com', null, 'reviewing', false, 'none', true],
            [2, 'Farah Idris', 'farah.idris@example.com', '019-8765432', 'interviewing', true, 'pending', false],
            [0, 'Marcus Lee', 'marcus.lee@example.com', null, 'hired', true, 'pending', false],
        ];

        foreach ($plan as $i => $row) {
            $employee = $employees->get($row[0]);
            if (! $employee) {
                continue;
            }

            $decided = in_array($row[4], ['hired', 'rejected'], true);

            Referral::create([
                'tenant_id' => $tid,
                'referrer_employee_id' => $employee->id,
                'job_requisition_id' => $row[7] ? $openRoles->get($i)?->id : null,
                'candidate_name' => $row[1],
                'candidate_email' => $row[2],
                'candidate_phone' => $row[3],
                'resume_url' => null,
                'note' => 'Strong fit — worked together previously.',
                'status' => $row[4],
                'bonus_eligible' => $row[5],
                'bonus_status' => $row[6],
                'decided_at' => $decided ? now() : null,
                'decided_by_id' => $decided ? $employee->id : null,
            ]);
        }
    }
}
