<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A type with this flag is charged every calendar day in the range, weekends and
        // public holidays included. Maternity leave (Employment Act s.37) is 98
        // consecutive days, not 98 working days.
        Schema::table('leave_types', function (Blueprint $table) {
            $table->boolean('counts_calendar_days')->default(false)->after('is_hr_granted_only');
        });

        DB::table('leave_types')->whereIn('name', ['Maternity', 'Cuti Bersalin'])->update(['counts_calendar_days' => true]);
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn('counts_calendar_days');
        });
    }
};
