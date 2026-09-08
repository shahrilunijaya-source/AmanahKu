<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-17: Management view on the dashboard (lateness and overdue tasks).
 *
 * `overdue_ledger` keeps a permanent record of who owned an overdue card in which
 * calendar month, even after the card is reassigned — CR-14 rule 8 needs September's
 * overdue figure to stay against the person who held the card in September.
 *
 * `attendance_incidents` is an HR-marked window (e.g. "clock server down 09:00-10:00")
 * that turns a clock-in inside it into "Unverified" instead of late, on the management
 * lateness panel only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overdue_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('month');
            $table->unsignedInteger('days_overdue');
            $table->timestamps();
        });

        Schema::create('attendance_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->text('note');
            $table->foreignId('created_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_incidents');
        Schema::dropIfExists('overdue_ledger');
    }
};
