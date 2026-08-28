<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            // Which effort type this card's work is costed as. The board is now the only
            // way work reaches a timesheet, so the classification the director reads is
            // made once here rather than re-picked on every row of every day.
            $table->foreignId('timesheet_category_id')->nullable()->after('project_id')
                ->constrained('timesheet_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('timesheet_category_id');
        });
    }
};
