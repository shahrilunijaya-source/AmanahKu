<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an employment type (Freelance, say) opt out of clock-in: staff on it are never
 * reminded, marked late or counted absent, and payroll pays them as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employment_types', function (Blueprint $table) {
            $table->boolean('clock_exempt')->default(false)->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('employment_types', function (Blueprint $table) {
            $table->dropColumn('clock_exempt');
        });
    }
};
