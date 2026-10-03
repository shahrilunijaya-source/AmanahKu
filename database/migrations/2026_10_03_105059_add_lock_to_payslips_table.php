<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            // Set while HR has locked the draft payslip; every payroll writer refuses it.
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropConstrainedForeignId('locked_by_id');
            $table->dropColumn('locked_at');
        });
    }
};
