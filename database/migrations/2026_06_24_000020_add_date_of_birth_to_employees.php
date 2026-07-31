<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Drives the PERKESO contribution category (≥60 → SOCSO Cat 2, no EIS). Nullable —
            // a missing DOB falls back to Category 1 and is flagged on the payroll run.
            $table->date('date_of_birth')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('date_of_birth');
        });
    }
};
