<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\TimesheetCategory;
use Illuminate\Database\Seeder;

class TimesheetCategorySeeder extends Seeder
{
    /**
     * Seed the default timesheet categories for every tenant. The list itself lives on
     * the model (TimesheetCategory::DEFAULTS) because company creation seeds it too —
     * two copies would drift, and a company that starts with the wrong categories cannot
     * cost anything until someone notices.
     */
    public function run(): void
    {
        foreach (Tenant::all() as $tenant) {
            TimesheetCategory::seedFor($tenant);
        }
    }
}
