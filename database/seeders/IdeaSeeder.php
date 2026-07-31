<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Idea;
use App\Models\IdeaVote;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class IdeaSeeder extends Seeder
{
    /**
     * Seed a handful of suggestion-box ideas + votes for the first tenant's employees.
     * Idempotent-ish: skips if ideas already exist for the tenant, and guards when there
     * is no tenant or no employees yet. No tenant session exists during seeding, so
     * tenant_id is set explicitly. Votes avoid duplicating (idea_id, employee_id).
     */
    public function run(): void
    {
        $tenant = Tenant::orderBy('id')->first();
        if (! $tenant) {
            return;
        }

        if (Idea::where('tenant_id', $tenant->id)->exists()) {
            return;
        }

        $employees = Employee::where('tenant_id', $tenant->id)->orderBy('id')->get();
        if ($employees->isEmpty()) {
            return;
        }

        $author = $employees->first();

        $seed = [
            ['title' => 'Hybrid Fridays', 'body' => 'Let teams work from home on Fridays to cut commute fatigue and focus on deep work.', 'category' => 'Workplace', 'status' => 'reviewing', 'votes' => 7],
            ['title' => 'Standardise the staging environment', 'body' => 'A shared, seeded staging instance would speed up QA and reduce "works on my machine" issues.', 'category' => 'Tech', 'status' => 'accepted', 'votes' => 5],
            ['title' => 'Quarterly skills swap sessions', 'body' => 'Internal lunch-and-learns where staff teach each other one skill per quarter.', 'category' => 'Process', 'status' => 'new', 'votes' => 3],
            ['title' => 'Refill the pantry weekly', 'body' => 'Coffee and snacks keep running out mid-week. A weekly restock would help.', 'category' => 'Other', 'status' => 'done', 'votes' => 2],
        ];

        foreach ($seed as $row) {
            $idea = Idea::create([
                'tenant_id' => $tenant->id,
                'employee_id' => $author->id,
                'title' => $row['title'],
                'body' => $row['body'],
                'category' => $row['category'],
                'status' => $row['status'],
            ]);

            // Give the first N distinct employees one vote each (respects the unique key).
            $voters = $employees->take($row['votes']);
            foreach ($voters as $voter) {
                IdeaVote::create([
                    'tenant_id' => $tenant->id,
                    'idea_id' => $idea->id,
                    'employee_id' => $voter->id,
                ]);
            }
        }
    }
}
