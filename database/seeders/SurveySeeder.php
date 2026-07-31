<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class SurveySeeder extends Seeder
{
    /**
     * Seed a couple of pulse surveys + responses for the first tenant's employees.
     * Idempotent-ish: skips if surveys already exist for the tenant, and guards when
     * there is no tenant or no employees yet. No tenant session exists during seeding,
     * so tenant_id is set explicitly.
     */
    public function run(): void
    {
        $tenant = Tenant::orderBy('id')->first();
        if (! $tenant) {
            return;
        }

        if (Survey::where('tenant_id', $tenant->id)->exists()) {
            return;
        }

        $employees = Employee::where('tenant_id', $tenant->id)->orderBy('id')->get();
        if ($employees->isEmpty()) {
            return;
        }

        $author = $employees->first();

        // 1) eNPS survey with a spread of promoters/passives/detractors.
        $enps = Survey::create([
            'tenant_id' => $tenant->id,
            'title' => 'June engagement pulse',
            'question' => 'How likely are you to recommend Unijaya as a place to work?',
            'type' => 'enps',
            'status' => 'open',
            'created_by_employee_id' => $author->id,
        ]);

        $enpsScores = [10, 9, 9, 8, 7, 6, 9, 10, 4, 8];
        foreach ($employees as $i => $emp) {
            if ($i >= count($enpsScores)) {
                break;
            }
            SurveyResponse::create([
                'tenant_id' => $tenant->id,
                'survey_id' => $enps->id,
                'employee_id' => $emp->id,
                'score' => $enpsScores[$i],
                'comment' => null,
            ]);
        }

        // 2) Scale 1–5 survey with a few responses.
        $scale = Survey::create([
            'tenant_id' => $tenant->id,
            'title' => 'Workload check-in',
            'question' => 'How manageable has your workload been this sprint? (1 = overwhelming, 5 = comfortable)',
            'type' => 'scale',
            'status' => 'open',
            'created_by_employee_id' => $author->id,
        ]);

        $scaleScores = [4, 3, 5, 2, 4, 3];
        foreach ($employees as $i => $emp) {
            if ($i >= count($scaleScores)) {
                break;
            }
            SurveyResponse::create([
                'tenant_id' => $tenant->id,
                'survey_id' => $scale->id,
                'employee_id' => $emp->id,
                'score' => $scaleScores[$i],
                'comment' => $i === 3 ? 'Crunch week before the release.' : null,
            ]);
        }
    }
}
