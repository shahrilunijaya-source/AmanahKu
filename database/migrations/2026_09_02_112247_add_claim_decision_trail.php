<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decision trail for claims, mirroring the one leave already carries
 * (2026_06_29_160000). Verification records who and when; approval and rejection
 * only ever flipped the status, so "who approved this claim" had no answer at all
 * — which is what the approvals count on the Claims screen needs.
 *
 * Nullable by necessity, not preference: every claim decided before this migration
 * has nobody recorded and nothing to back-fill from. Those rows stay blank forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->foreignId('approved_by_id')->nullable()->after('verified_at')->constrained('employees')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by_id');
            $table->foreignId('rejected_by_id')->nullable()->after('approved_at')->constrained('employees')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by_id');
            $table->dropConstrainedForeignId('rejected_by_id');
            $table->dropColumn(['approved_at', 'rejected_at']);
        });
    }
};
