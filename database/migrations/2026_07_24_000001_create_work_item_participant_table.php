<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A work item can include people beyond its single owner. Each included
        // employee sees the same shared card on their own board (one row, many
        // boards). Distinct from assigned_by_id (a single-owner "tac"): this is
        // shared visibility, not a change of ownership.
        Schema::create('work_item_participant', function (Blueprint $table) {
            $table->foreignId('work_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['work_item_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_participant');
    }
};
