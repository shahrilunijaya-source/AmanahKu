<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec F9: a CP38 direction from LHDN is a notice with a total to collect and a monthly
 * instalment, not a flat "RM per month" field. The running balance lives on the notice
 * and only moves when a run is finalized, so a draft run can be rebuilt freely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_cp38_notices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('reference', 60)->nullable();
            $t->date('notice_date')->nullable();
            // null = open ended: LHDN ordered an instalment with no stated total.
            $t->decimal('total_amount', 12, 2)->nullable();
            $t->decimal('monthly_instalment', 12, 2);
            $t->char('first_period', 7);
            $t->char('last_period', 7)->nullable();
            $t->decimal('remaining_balance', 12, 2)->nullable();
            $t->string('status')->default('active');
            $t->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['tenant_id', 'employee_id', 'status']);
        });

        Schema::table('payslips', function (Blueprint $t) {
            // {notice_id: amount} actually taken this month, so deleting a finalized run
            // restores each notice by exactly what it gave.
            $t->json('cp38_applied')->nullable()->after('cp38');
        });

        // Carry the dead flat figure over as an open-ended notice from this month on.
        $period = now()->format('Y-m');
        foreach (DB::table('salary_structures')->where('cp38_monthly', '>', 0)->get() as $s) {
            DB::table('payroll_cp38_notices')->insert([
                'tenant_id' => $s->tenant_id,
                'employee_id' => $s->employee_id,
                'monthly_instalment' => $s->cp38_monthly,
                'first_period' => $period,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Keep the column (finalized history and any rollback still want it there) but
        // nothing reads or writes it after this migration.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE salary_structures MODIFY cp38_monthly DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'dead since 2026-10, see payroll_cp38_notices'");
        }
    }

    public function down(): void
    {
        Schema::table('payslips', fn (Blueprint $t) => $t->dropColumn('cp38_applied'));
        Schema::dropIfExists('payroll_cp38_notices');
    }
};
