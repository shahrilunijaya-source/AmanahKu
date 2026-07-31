<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the cross-link at a written Knowledge Bank lesson.
     *
     * Presenting a TOT is itself the month's contribution: TotController::creditContribution
     * marks it when the slot goes done. Pointing at a separate lesson implied the obligation
     * needed one, which was never true, and the editor asked for a raw numeric ID nobody
     * could know. Destructive: any stored cross-link is lost.
     */
    public function up(): void
    {
        Schema::table('tot_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('entry_id');
        });
    }

    public function down(): void
    {
        Schema::table('tot_sessions', function (Blueprint $table) {
            $table->foreignId('entry_id')->nullable()->after('held_on')
                ->constrained('knowledge_entries')->nullOnDelete();
        });
    }
};
