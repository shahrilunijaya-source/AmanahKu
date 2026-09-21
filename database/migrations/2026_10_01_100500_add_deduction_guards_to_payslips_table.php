<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec F4: EA s.24 deduction cap flag with HR's recorded consent, and the shortfall a
 * negative net pay hands to next month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->boolean('deduction_cap_exceeded')->default(false)->after('total_deductions');
            $table->boolean('deduction_consent_confirmed')->default(false)->after('deduction_cap_exceeded');
            $table->decimal('carried_forward_amount', 12, 2)->default(0)->after('deduction_consent_confirmed');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn(['deduction_cap_exceeded', 'deduction_consent_confirmed', 'carried_forward_amount']);
        });
    }
};
