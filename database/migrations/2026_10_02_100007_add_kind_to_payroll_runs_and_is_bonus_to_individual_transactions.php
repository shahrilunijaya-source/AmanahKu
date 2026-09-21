<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Spec F10: a period no longer means one run. The monthly run is still unique per
        // tenant and period, but a bonus run (and, from Task 9, a final-pay run for one
        // leaver) sits alongside it in the same month — so the old unique index has to go
        // and the uniqueness rule moves into PayrollController::createRun(), where it can
        // be stated per kind.
        Schema::table('payroll_runs', function (Blueprint $t) {
            $t->string('kind')->default('monthly')->after('period');   // monthly | bonus | final
            // Only a final-pay run is for one named person; every other kind covers the
            // whole company and leaves this null.
            $t->foreignId('employee_id')->nullable()->after('kind')->constrained()->restrictOnDelete();
            // The plain index goes in FIRST: tenant_id's foreign key is riding on the
            // unique index, and MySQL refuses to drop the last index covering it.
            $t->index(['tenant_id', 'period']);
            $t->dropUnique(['tenant_id', 'period']);
        });

        // A bonus is queued the same way any other one-off is — an Individual Transaction
        // — but it must not land on the monthly payslip: a monthly run pulls only the
        // unflagged rows, a bonus run only the flagged ones.
        Schema::table('individual_transactions', function (Blueprint $t) {
            $t->boolean('for_bonus_run')->default(false)->after('period');
        });
    }

    public function down(): void
    {
        Schema::table('individual_transactions', function (Blueprint $t) {
            $t->dropColumn('for_bonus_run');
        });

        Schema::table('payroll_runs', function (Blueprint $t) {
            $t->dropConstrainedForeignId('employee_id');
            $t->dropColumn('kind');
            $t->unique(['tenant_id', 'period']);
            $t->dropIndex(['tenant_id', 'period']);
        });
    }
};
