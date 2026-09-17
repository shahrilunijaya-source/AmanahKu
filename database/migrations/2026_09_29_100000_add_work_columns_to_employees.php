<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('attendance_id', 40)->nullable()->after('staff_id');
            $table->string('work_phone', 40)->nullable()->after('attendance_id');
            $table->date('benefit_start_at')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn(['attendance_id', 'work_phone', 'benefit_start_at']));
    }
};
