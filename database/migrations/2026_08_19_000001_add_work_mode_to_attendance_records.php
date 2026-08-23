<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The work mode the employee declared for each punch: 'office_home' (the default,
        // and what every pre-existing row means) or 'site_visit'. Paired in/out like every
        // other per-punch column on this table, because a day can start at the office and
        // end at a customer. Nullable and never back-filled — null reads as office_home.
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->string('work_mode')->nullable()->after('type');
            $table->string('clock_out_work_mode')->nullable()->after('work_mode');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn(['work_mode', 'clock_out_work_mode']);
        });
    }
};
