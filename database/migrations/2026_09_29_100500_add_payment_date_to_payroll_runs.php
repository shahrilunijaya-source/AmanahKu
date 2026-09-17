<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The day salaries reach staff bank accounts, set by HR when creating the run (Worksy's "Payment Date"). */
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->date('payment_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropColumn('payment_date');
        });
    }
};
