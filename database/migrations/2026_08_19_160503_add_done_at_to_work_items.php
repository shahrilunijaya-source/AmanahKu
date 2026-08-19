<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->timestamp('done_at')->nullable()->after('status');
        });

        // Backfill: cards already sitting in Done get a fresh day from today rather
        // than being auto-archived on the very next scheduler tick.
        DB::table('work_items')->where('status', 'done')->whereNull('done_at')->update(['done_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropColumn('done_at');
        });
    }
};
