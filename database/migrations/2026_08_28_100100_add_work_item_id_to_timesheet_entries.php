<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            // The board card this row was logged against. Null for a row the staffer
            // typed. Two jobs: stop the prefill offering a card on a day it is already
            // logged, and carry that card's category to the rest of its week.
            $table->foreignId('work_item_id')->nullable()->after('source')
                ->constrained('work_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_item_id');
        });
    }
};
