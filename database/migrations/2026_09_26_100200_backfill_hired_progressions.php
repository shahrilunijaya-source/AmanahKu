<?php

use App\Models\Employee;
use App\Models\EmployeeProgression;
use App\Services\EmploymentRecordService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** One 'hired' row per current staff member so the Timeline tab is never empty. */
    public function up(): void
    {
        $svc = app(EmploymentRecordService::class);
        Employee::withoutGlobalScopes()->whereNull('archived_at')->each(function (Employee $e) use ($svc) {
            if (EmployeeProgression::withoutGlobalScopes()->where('employee_id', $e->id)->exists()) {
                return;
            }
            $svc->hire($e);
        });
    }

    public function down(): void
    {
        EmployeeProgression::withoutGlobalScopes()->where('type', 'hired')->whereNull('recorded_by_employee_id')->delete();
    }
};
