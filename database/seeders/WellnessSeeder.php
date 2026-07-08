<?php

namespace Database\Seeders;

use App\Models\EapResource;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\WellnessCheckin;
use App\Models\WellnessRequest;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class WellnessSeeder extends Seeder
{
    /**
     * Seed the EAP library, a spread of private pulse check-ins (so the HR aggregate
     * has signal), and a couple of confidential 1:1 requests for the first tenant.
     * Safe to re-run: skips if the tenant already has EAP resources, and guards
     * against tenants with no employees. No tenant session exists during seeding,
     * so tenant_id is set explicitly and queries are scoped to the tenant by hand.
     */
    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('id')->first();
        if (! $tenant) {
            return;
        }

        $tid = $tenant->id;

        // Global scope is inactive in seeders, so scope to the tenant explicitly.
        if (EapResource::where('tenant_id', $tid)->exists()) {
            return;
        }

        $employees = Employee::where('tenant_id', $tid)->orderBy('id')->get();
        if ($employees->isEmpty()) {
            return;
        }

        // 1) EAP library — across all categories, with a Malaysia mental-health hotline.
        $resources = [
            ['Befrienders KL', 'Hotline', '24/7 emotional support and suicide-prevention helpline. You do not have to face it alone.', '03-7627 2929', 'https://www.befrienders.org.my'],
            ['Talian Kasih', 'Hotline', 'Government 24-hour helpline for crisis, abuse, and family welfare support.', '15999', null],
            ['Confidential Counselling Sessions', 'Mental Health', 'Up to six free, fully confidential sessions a year with a licensed counsellor — book directly, HR never sees the details.', 'eap@unijaya.example', null],
            ['Financial Wellness Clinic', 'Financial', 'One-on-one guidance on budgeting, debt, and retirement planning with an accredited adviser.', null, null],
            ['Workplace Ergonomics & Fitness', 'Physical', 'Desk-setup assessments and subsidised gym membership to keep you well physically.', null, null],
            ['Legal Aid Advisory', 'Legal', 'Free initial consultation on personal legal matters — tenancy, family, and consumer issues.', 'legalaid@unijaya.example', null],
        ];

        foreach ($resources as $r) {
            EapResource::create([
                'tenant_id' => $tid,
                'title' => $r[0],
                'category' => $r[1],
                'description' => $r[2],
                'contact' => $r[3],
                'url' => $r[4],
                'is_active' => true,
            ]);
        }

        // 2) Private pulse check-ins across several employees + dates — gives the HR
        //    aggregate something to average over. [employee index, mood, stress, days ago, note]
        $checkins = [
            [0, 4, 2, 0, null],
            [0, 3, 3, 7, 'Busy week but coping.'],
            [1, 5, 1, 1, null],
            [1, 4, 2, 8, null],
            [2, 2, 4, 0, 'Feeling stretched with the release crunch.'],
            [2, 3, 3, 6, null],
            [3, 4, 2, 2, null],
            [4, 3, 3, 3, null],
            [4, 2, 5, 10, null],
            [5, 5, 2, 1, null],
        ];

        foreach ($checkins as $c) {
            $employee = $employees->get($c[0]);
            if (! $employee) {
                continue;
            }

            WellnessCheckin::create([
                'tenant_id' => $tid,
                'employee_id' => $employee->id,
                'mood' => $c[1],
                'stress' => $c[2],
                'note' => $c[4],
                'checkin_date' => Carbon::today()->subDays($c[3])->toDateString(),
            ]);
        }

        // 3) Confidential 1:1 requests — one open, one acknowledged.
        $first = $employees->first();
        $second = $employees->get(1) ?? $first;

        WellnessRequest::create([
            'tenant_id' => $tid,
            'employee_id' => $first->id,
            'topic' => 'Mental Health',
            'message' => 'I would appreciate a private chat about managing workload stress.',
            'urgency' => 'normal',
            'status' => 'open',
        ]);

        WellnessRequest::create([
            'tenant_id' => $tid,
            'employee_id' => $second->id,
            'topic' => 'Financial',
            'message' => 'Could I get a referral to the financial wellness clinic?',
            'urgency' => 'low',
            'status' => 'acknowledged',
            'handled_by_id' => $first->id,
            'handled_at' => Carbon::now()->subDay(),
        ]);
    }
}
