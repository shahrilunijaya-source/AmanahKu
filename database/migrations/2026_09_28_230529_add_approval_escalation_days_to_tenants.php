<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Days a leave or claim request may wait for the manager's verification before the final
 * approvers (HR / director) may approve it directly. Managers who are too busy to verify
 * were holding requests up indefinitely. NULL turns the shortcut off; set on Company Settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedTinyInteger('approval_escalation_days')->nullable()->default(3);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('approval_escalation_days');
        });
    }
};
