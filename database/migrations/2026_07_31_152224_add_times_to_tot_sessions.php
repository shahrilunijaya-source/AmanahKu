<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a slot runs. Nullable because almost every month keeps the usual hour and
     * nobody wants to retype it: null means TotSession::DEFAULT_START / DEFAULT_END, and
     * a value here is a month somebody moved.
     */
    public function up(): void
    {
        Schema::table('tot_sessions', function (Blueprint $table) {
            $table->time('starts_at')->nullable()->after('status');
            $table->time('ends_at')->nullable()->after('starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('tot_sessions', function (Blueprint $table) {
            $table->dropColumn(['starts_at', 'ends_at']);
        });
    }
};
