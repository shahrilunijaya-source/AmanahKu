<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The immediate superior's comment when verifying a leave request. Sits beside the
 * verifier's own columns so the whole verify decision reads as one block, and shows
 * on the applicant's timeline and in the approver's queue — the approver otherwise
 * sees a verified request with no word on why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->text('verify_note')->nullable()->after('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn('verify_note');
        });
    }
};
