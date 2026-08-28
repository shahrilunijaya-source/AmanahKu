<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per visit a card makes to the In Progress column. A separate table rather
     * than a pair of columns on work_items: two columns remember only the most recent
     * visit, so a card that bounces prog -> review -> prog would lose its earlier days,
     * and those days are exactly what the timesheet prefill reads.
     */
    public function up(): void
    {
        Schema::create('work_item_progress_stints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_item_id')->constrained()->cascadeOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->timestamps();

            $table->index(['work_item_id', 'started_at']);
            // The week lookup filters by tenant over a date range.
            $table->index(['tenant_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_progress_stints');
    }
};
