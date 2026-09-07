<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-20: the curated holiday-eve greeting HR writes per public holiday. Blank means the
 * generic "Happy holiday" line is shown instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_holidays', function (Blueprint $table) {
            $table->string('greeting_en', 200)->nullable()->after('state');
            $table->string('greeting_ms', 200)->nullable()->after('greeting_en');
        });
    }

    public function down(): void
    {
        Schema::table('public_holidays', function (Blueprint $table) {
            $table->dropColumn(['greeting_en', 'greeting_ms']);
        });
    }
};
