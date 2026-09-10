<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR-10 (S12): the tagged helpers on a Tindakan row, the second and later ids of
     * `owners[]` (the first is `tot_actions.owner_employee_id`, unchanged). Read to build
     * the card's `work_item_participant` rows (role `helper`) at creation and re-synced on
     * every edit; the card's own participant table is the source of truth for the board,
     * this one is the source of truth for the Tindakan form.
     */
    public function up(): void
    {
        Schema::create('tot_action_helper', function (Blueprint $table) {
            $table->id();
            $table->foreignId('action_id')->constrained('tot_actions')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['action_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tot_action_helper');
    }
};
