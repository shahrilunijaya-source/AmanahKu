<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Medical claims already paid out this year under the old payroll system. Not on
     * Form EA and not taxed: it only counts toward the yearly medical claim cap, which
     * otherwise sees just the claims made in this app.
     */
    public function up(): void
    {
        Schema::table('payroll_opening_figures', function (Blueprint $table) {
            $table->decimal('medical_claimed', 12, 2)->default(0)->after('exempt_allowances');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_opening_figures', function (Blueprint $table) {
            $table->dropColumn('medical_claimed');
        });
    }
};
