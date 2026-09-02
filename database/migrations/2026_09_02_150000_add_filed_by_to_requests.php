<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who filed a leave request or claim on somebody else's behalf. Null for the normal
 * case (the requester filed it themselves). Set only when HR submits for a member of
 * staff, so the approval step can refuse the filer the same way it refuses the
 * requester and the verifier — nobody signs off what they put in.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['leave_requests', 'claims'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('filed_by_id')->nullable()->after('employee_id')->constrained('employees')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['leave_requests', 'claims'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('filed_by_id');
            });
        }
    }
};
