<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decision trail for leave so the applicant can see the full chronology of their
 * request: who approved / rejected it and when. Verification (who/when) already
 * exists from the two-step-gate migration; this completes the timeline for the
 * final decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->foreignId('approved_by_id')->nullable()->after('verified_at')->constrained('employees')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by_id');
            $table->foreignId('rejected_by_id')->nullable()->after('approved_at')->constrained('employees')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by_id');
            $table->dropConstrainedForeignId('rejected_by_id');
            $table->dropColumn(['approved_at', 'rejected_at']);
        });
    }
};
