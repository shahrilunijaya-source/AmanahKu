<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Employee;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class LearningSeeder extends Seeder
{
    /**
     * Seed 5-6 courses across categories/levels plus a few enrollments (one
     * completed, one in-progress) for the Unijaya tenant's employees so the
     * learning library has signal. Safe to re-run: skips if that tenant already
     * has courses, and guards against a missing tenant or empty employee list.
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
        if (Course::where('tenant_id', $tid)->exists()) {
            return;
        }

        $employees = Employee::where('tenant_id', $tid)->orderBy('id')->get();
        if ($employees->isEmpty()) {
            return;
        }

        // [title, category, level, provider, duration_hours, description]
        $catalogue = [
            ['Leadership Essentials', 'Leadership', 'Beginner', 'Unijaya Academy', 6.0, 'Core skills for first-time team leads: delegation, feedback, and running effective one-on-ones.'],
            ['Excel for Analysts', 'Technical', 'Intermediate', 'Microsoft', 8.0, 'Pivot tables, Power Query, and dashboard modelling for finance and operations reporting.'],
            ['Workplace Communication', 'Soft Skills', 'Beginner', 'Unijaya Academy', 4.0, 'Clear written and verbal communication, active listening, and constructive conflict handling.'],
            ['Cybersecurity Awareness', 'Compliance', 'Beginner', 'SANS', 2.0, 'Recognising phishing, password hygiene, and safe handling of company and customer data.'],
            ['Project Management Fundamentals', 'Leadership', 'Intermediate', 'PMI', 10.0, 'Scoping, scheduling, risk management, and stakeholder reporting for project leads.'],
            ['Bahasa Malaysia for Business', 'Soft Skills', 'Beginner', 'Unijaya Academy', 12.0, 'Practical business Bahasa Malaysia for meetings, email, and client correspondence.'],
        ];

        $courses = [];
        foreach ($catalogue as $row) {
            $courses[] = Course::create([
                'tenant_id' => $tid,
                'title' => $row[0],
                'category' => $row[1],
                'level' => $row[2],
                'provider' => $row[3],
                'duration_hours' => $row[4],
                'description' => $row[5],
                'is_active' => true,
            ]);
        }

        // [employee index, course index, status, progress]
        $enrollments = [
            [0, 0, 'completed', 100],
            [0, 2, 'in_progress', 40],
            [1, 1, 'in_progress', 65],
            [1, 3, 'completed', 100],
            [2, 4, 'enrolled', 0],
            [3, 0, 'in_progress', 25],
        ];

        foreach ($enrollments as $row) {
            $employee = $employees->get($row[0]);
            $course = $courses[$row[1]] ?? null;
            if (! $employee || ! $course) {
                continue;
            }

            $completed = $row[2] === 'completed';

            // Guard against duplicate (course, employee) enrollments on re-runs.
            CourseEnrollment::updateOrCreate(
                [
                    'course_id' => $course->id,
                    'employee_id' => $employee->id,
                ],
                [
                    'tenant_id' => $tid,
                    'status' => $row[2],
                    'progress' => $row[3],
                    'enrolled_at' => now()->subDays(30)->toDateString(),
                    'completed_at' => $completed ? now()->subDays(3)->toDateString() : null,
                ],
            );
        }
    }
}
