<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per person per session. Tracker default (docs/build/OPEN.md, CR-09): attendance
     * stays inside the Learning module and never writes an attendance_records row — TOT
     * Saturday attendance is a training register, not a clock punch.
     */
    public function up(): void
    {
        Schema::create('tot_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('tot_sessions')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->boolean('present');
            $table->string('reason', 300)->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tot_attendance');
    }
};
