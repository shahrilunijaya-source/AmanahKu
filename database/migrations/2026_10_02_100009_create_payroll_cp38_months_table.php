<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CP38 the way Worksy keeps it: one amount per employee per month, typed into a
 * 12-month grid, instead of notices with a balance that counts down. Active notices
 * are spread into month rows from this month on, so nothing already set up is lost.
 * payroll_cp38_notices and payslips.cp38_applied stay for history; nothing reads them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_cp38_months', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->char('period', 7);
            $t->decimal('amount', 12, 2);
            $t->timestamps();
            $t->unique(['tenant_id', 'employee_id', 'period']);
        });

        $now = now()->format('Y-m');
        // An open-ended notice is carried to the end of next year; HR extends it on the grid.
        $openEnd = (now()->year + 1).'-12';
        $rows = [];
        foreach (DB::table('payroll_cp38_notices')->where('status', 'active')->orderBy('first_period')->orderBy('id')->get() as $n) {
            $balance = $n->remaining_balance === null ? null : (float) $n->remaining_balance;
            $period = max($n->first_period, $now);
            $last = $n->last_period ?? $openEnd;
            while ($period <= $last && ($balance === null || $balance > 0)) {
                $take = $balance === null ? (float) $n->monthly_instalment : min((float) $n->monthly_instalment, $balance);
                $key = $n->tenant_id.'|'.$n->employee_id.'|'.$period;
                $rows[$key] = ['tenant_id' => $n->tenant_id, 'employee_id' => $n->employee_id, 'period' => $period,
                    'amount' => round(($rows[$key]['amount'] ?? 0) + $take, 2), 'created_at' => now(), 'updated_at' => now()];
                if ($balance !== null) {
                    $balance -= $take;
                }
                $period = date('Y-m', strtotime($period.'-01 +1 month'));
            }
        }
        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            DB::table('payroll_cp38_months')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_cp38_months');
    }
};
