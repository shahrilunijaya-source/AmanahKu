<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** An Individual Transaction's optional free-text note (e.g. "advance for July trip"). */
    public function up(): void
    {
        Schema::table('payslip_lines', function (Blueprint $table) {
            $table->string('remark')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('payslip_lines', function (Blueprint $table) {
            $table->dropColumn('remark');
        });
    }
};
