<?php

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\SalaryStructure;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * employees.nric / employees.marital_status (set by the first-login wizard) and
     * salary_structures.nric / salary_structures.marital_status (set by HR on the salary
     * form) were two sources for the same personal fact. Reconcile to ONE: the employees
     * column, since it's the personal record and the value the employee themselves typed.
     * Payroll code now reads Employee for both (PayrollController, exports, bank files).
     *
     * Both columns are 'encrypted' casts (ciphertext, not readable via raw DB::table), so
     * this goes through Eloquent like PayrollItem::seedFor() does elsewhere.
     *
     * Fill-nulls-only: an employees value already set is never overwritten, so no existing
     * PCB figure moves silently. Where BOTH are set and disagree, the employees value wins
     * and the conflict is logged (not silently dropped) so HR can be told PCB may now
     * differ for that person — check the Laravel log after deploying this.
     */
    public function up(): void
    {
        $conflicts = 0;
        /** @var array<int, array{tenant_id: int, name: string, field: string}> $conflicting */
        $conflicting = [];

        SalaryStructure::whereNotNull('nric')->orWhereNotNull('marital_status')
            ->with('employee')->get()
            ->each(function (SalaryStructure $structure) use (&$conflicts, &$conflicting) {
                $employee = $structure->employee;
                if ($employee === null) {
                    return;
                }

                $dirty = false;

                if (filled($structure->nric)) {
                    if (blank($employee->nric)) {
                        $employee->nric = $structure->nric;
                        $dirty = true;
                    } elseif ($employee->nric !== $structure->nric) {
                        $conflicts++;
                        $conflicting[] = ['tenant_id' => $employee->tenant_id, 'name' => (string) $employee->name, 'field' => 'NRIC'];
                    }
                }

                if (filled($structure->marital_status)) {
                    if (blank($employee->marital_status)) {
                        $employee->marital_status = $structure->marital_status;
                        $dirty = true;
                    } elseif ($employee->marital_status !== $structure->marital_status) {
                        $conflicts++;
                        $conflicting[] = ['tenant_id' => $employee->tenant_id, 'name' => (string) $employee->name, 'field' => 'marital status'];
                    }
                }

                if ($dirty) {
                    $employee->save();
                }
            });

        if ($conflicts > 0) {
            Log::warning("reconcile_nric_and_marital_status: {$conflicts} salary_structures row(s) had a value that disagreed with the employee record. The employee record's value won; PCB may now differ for those people — review manually.");

            // The Laravel log is unreadable on the production host from our side (no shell,
            // no log access), so the same warning goes into each company's own audit trail
            // where HR can actually find it and check the people named.
            foreach (collect($conflicting)->groupBy('tenant_id') as $tenantId => $rows) {
                AuditLog::forceCreate([
                    'tenant_id' => $tenantId,
                    'user_id' => null,
                    'actor_name' => 'System',
                    'action' => 'Payroll profile reconciled — please review',
                    'target' => collect($rows)->map(fn ($r) => $r['name'].' ('.$r['field'].')')->unique()->implode(', ')
                        .' — the employee record\'s value was kept; the payroll copy differed. Check their tax figures.',
                ]);
            }
        }

        Schema::table('salary_structures', function (Blueprint $table) {
            $table->dropColumn(['nric', 'marital_status']);
        });
    }

    public function down(): void
    {
        // Schema-only: the two columns are re-added but left null. The pre-reconciliation
        // per-column values aren't reliably recoverable (see the migrate-allowances
        // migration's down() for the same reasoning), and by the time this rolls back HR
        // may have relied on the employees column being canonical.
        Schema::table('salary_structures', function (Blueprint $table) {
            $table->string('nric')->nullable()->after('socso_no');
            $table->string('marital_status')->nullable()->after('tax_no');
        });
    }
};
