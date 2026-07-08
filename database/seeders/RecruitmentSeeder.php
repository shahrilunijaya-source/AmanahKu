<?php

namespace Database\Seeders;

use App\Models\Candidate;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobRequisition;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class RecruitmentSeeder extends Seeder
{
    /**
     * Seed ~2 requisitions with a handful of candidates across stages for the first
     * tenant. Guarded: skips when there is no tenant or when requisitions already
     * exist. No tenant session exists during seeding, so tenant_id is set explicitly.
     */
    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('id')->first();
        if (! $tenant) {
            return;
        }

        $tid = $tenant->id;

        if (JobRequisition::where('tenant_id', $tid)->exists()) {
            return;
        }

        $eng = Department::where('tenant_id', $tid)->where('name', 'like', '%Engineer%')->first()
            ?? Department::where('tenant_id', $tid)->orderBy('id')->first();
        $opener = Employee::where('tenant_id', $tid)->orderBy('id')->first();

        $backend = JobRequisition::create([
            'tenant_id' => $tid,
            'department_id' => $eng?->id,
            'created_by_employee_id' => $opener?->id,
            'title' => 'Senior Backend Engineer',
            'openings' => 2,
            'location' => 'KL HQ',
            'status' => 'open',
        ]);

        foreach ([
            ['Farah Idris', 'farah.idris@example.com', '012-3456789', 'screening', 'Strong Laravel background; referred by team lead.'],
            ['Daniel Tan', 'daniel.tan@example.com', null, 'interview', 'Technical round scheduled for next week.'],
            ['Priya Nair', 'priya.nair@example.com', '019-8765432', 'applied', 'Applied via careers page.'],
            ['Hafiz Rahman', null, null, 'offer', 'Offer drafted, pending compensation sign-off.'],
        ] as [$name, $email, $phone, $stage, $notes]) {
            Candidate::create([
                'tenant_id' => $tid,
                'job_requisition_id' => $backend->id,
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'stage' => $stage,
                'notes' => $notes,
            ]);
        }

        $designer = JobRequisition::create([
            'tenant_id' => $tid,
            'department_id' => $opener?->department_id,
            'created_by_employee_id' => $opener?->id,
            'title' => 'Product Designer',
            'openings' => 1,
            'location' => 'Remote (MY)',
            'status' => 'open',
        ]);

        foreach ([
            ['Aina Yusof', 'aina.yusof@example.com', null, 'hired', 'Accepted offer, starting next month.'],
            ['Marcus Lee', 'marcus.lee@example.com', '011-2233445', 'rejected', 'Portfolio not a fit for the role.'],
            ['Suria Devi', null, '013-5566778', 'applied', 'Sourced from design community.'],
        ] as [$name, $email, $phone, $stage, $notes]) {
            Candidate::create([
                'tenant_id' => $tid,
                'job_requisition_id' => $designer->id,
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'stage' => $stage,
                'notes' => $notes,
            ]);
        }
    }
}
