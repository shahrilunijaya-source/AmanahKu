<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** When the staff member confirmed they saw their issued payslip (My Payroll, Acknowledge). */
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->timestamp('acknowledged_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn('acknowledged_at');
        });
    }
};
