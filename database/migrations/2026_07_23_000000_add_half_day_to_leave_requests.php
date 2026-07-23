<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A single-day leave request may now cover only half the day. `half_day_period`
     * records which half ('am' = morning, 'pm' = afternoon); null keeps the historical
     * whole-day meaning, so every existing row stays a full day. The 0.5-vs-1.0 day
     * count itself lives in the existing `days` column (already decimal).
     */
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->string('half_day_period', 2)->nullable()->after('date_to');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn('half_day_period');
        });
    }
};
