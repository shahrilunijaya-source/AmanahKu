<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec F8: what an employee declared on Form TP1 for one month — optional deductions
 * (∑LP / LP1 in the LHDN MTD formula) and zakat paid directly to Pusat Zakat. One row
 * per relief line; a zakat-only row has a null relief_code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_tp1_claims', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->smallInteger('year');
            $t->tinyInteger('month');
            $t->string('relief_code', 40)->nullable();
            $t->decimal('amount', 12, 2)->default(0);
            $t->decimal('zakat_amount', 12, 2)->default(0);
            $t->string('note', 255)->nullable();
            $t->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['tenant_id', 'employee_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_tp1_claims');
    }
};
