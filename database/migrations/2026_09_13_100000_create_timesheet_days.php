<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S08 / CR-03: per-day timesheet state (daily submit, deadline, lock, return/approve,
 * unlock). See docs/build/contracts/audit-log.md and OPEN "QA / CR-03" for the shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timesheet_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('timesheet_id')->constrained()->cascadeOnDelete();
            $table->date('entry_date');
            $table->string('status', 10)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->boolean('late')->default(false);
            $table->boolean('resubmitted')->default(false);
            $table->text('zero_reason')->nullable();
            $table->text('return_reason')->nullable();
            $table->timestamp('unlocked_at')->nullable();
            $table->foreignId('unlocked_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->unique(['timesheet_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheet_days');
    }
};
