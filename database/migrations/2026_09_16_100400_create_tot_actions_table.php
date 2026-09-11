<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keputusan/Tindakan Susulan: one row per follow-up action recorded at a session.
     * `target_date` is nullable — no date given means "Bulan hadapan" (next TOT Saturday),
     * per dates.md Rule 4 the effective due date is computed, never guessed and stored here.
     * `work_item_id` is set once "Create T.A.A. task" succeeds; a second click on the same
     * action is rejected (see TotController::createActionCard) rather than making a duplicate
     * card. CR-10 (S12) owns editing this row afterwards, helpers and status sync.
     */
    public function up(): void
    {
        Schema::create('tot_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('tot_sessions')->cascadeOnDelete();
            $table->foreignId('slot_id')->nullable()->constrained('tot_slots')->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('action', 300);
            $table->foreignId('owner_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('target_date')->nullable();
            $table->foreignId('work_item_id')->nullable()->constrained('work_items')->nullOnDelete();
            $table->timestamps();

            $table->index(['session_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tot_actions');
    }
};
