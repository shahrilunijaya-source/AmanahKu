<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            // Optional idempotency handle for scheduled senders ("attendance-in-2026-07-25").
            // NULL is the normal case for one-off event notifications, and SQL treats NULLs
            // as distinct in a unique index, so existing rows and ad-hoc sends are unaffected.
            $table->string('dedupe_key')->nullable()->after('url');
            $table->unique(['tenant_id', 'user_id', 'dedupe_key']);
        });
    }

    public function down(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'user_id', 'dedupe_key']);
            $table->dropColumn('dedupe_key');
        });
    }
};
