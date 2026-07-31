<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Per-scope dashboard card visibility/order: {"me":{"hidden":[],"order":[]},"company":{"hidden":[],"order":[]}}.
            $table->json('dashboard_prefs')->nullable()->after('password_change_required');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('dashboard_prefs');
        });
    }
};
