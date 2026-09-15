<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Basic salary now lives on the employee record only (Employment tab / Progression), as in
 * Worksy; payroll reads employees.salary. One-time copy for anyone whose payroll figure was
 * set but whose employee record was not. salary_structures.basic_salary stays as history.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('salary_structures')
            ->join('employees', 'employees.id', '=', 'salary_structures.employee_id')
            ->where('salary_structures.basic_salary', '>', 0)
            ->where(fn ($q) => $q->whereNull('employees.salary')->orWhere('employees.salary', '<=', 0))
            ->get(['employees.id', 'salary_structures.basic_salary']);

        foreach ($rows as $r) {
            DB::table('employees')->where('id', $r->id)->update(['salary' => $r->basic_salary]);
        }
    }

    public function down(): void
    {
        // Nothing to undo: the copy only filled empty employee salaries.
    }
};
