<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Trello-style colored labels on a card. A JSON array of slug keys drawn
        // from the fixed WorkItem::LABELS palette — no per-card free text, so no
        // separate labels table is needed.
        Schema::table('work_items', function (Blueprint $table) {
            $table->json('labels')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropColumn('labels');
        });
    }
};
