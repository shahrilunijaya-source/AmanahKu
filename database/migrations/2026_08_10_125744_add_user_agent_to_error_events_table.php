<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The device the fault happened on.
 *
 * A fault that only appears for some people is almost always a fault that only appears
 * on some devices, and until now the table said nothing about the device at all. That
 * left "it works on my desktop" as the only available comparison, which is the one
 * comparison a developer can already make without asking anybody.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('error_events', function (Blueprint $table) {
            // Long, and untrusted: a user agent is client-supplied text. Stored for
            // reading only, never parsed into a decision.
            $table->string('user_agent', 512)->nullable()->after('ip');
        });
    }

    public function down(): void
    {
        Schema::table('error_events', function (Blueprint $table) {
            $table->dropColumn('user_agent');
        });
    }
};
