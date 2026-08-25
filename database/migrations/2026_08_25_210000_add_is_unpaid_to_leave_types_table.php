<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which leave type IS "unpaid leave" for payroll purposes. Matching by the literal
        // name 'Unpaid' (LeaveController::absorbOverflowAsUnpaid, PayrollController's
        // unpaid-leave pull) breaks the moment a company renames it or seeds it in Malay —
        // an explicit flag survives a rename.
        Schema::table('leave_types', function (Blueprint $table) {
            $table->boolean('is_unpaid')->default(false)->after('name');
        });

        // Backfill: exactly the same predicate the code it replaces used, so this migration
        // changes nothing for an existing tenant beyond making the match explicit.
        DB::table('leave_types')->where('name', 'Unpaid')->update(['is_unpaid' => true]);
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn('is_unpaid');
        });
    }
};
