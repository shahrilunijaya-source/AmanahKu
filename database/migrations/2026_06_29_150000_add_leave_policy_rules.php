<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leave-policy rules that separate planned entitlement from unplanned absence.
 *
 *  - deducts_from_leave_type_id: a type with no entitlement of its own draws down
 *    another type's balance. Emergency leave is not a privilege — it deducts from
 *    Annual (an unplanned way to spend planned leave).
 *  - is_unplanned: marks emergency-style leave. It bypasses the advance-notice
 *    rule and is tracked separately in reports as a red-flag signal.
 *  - min_notice_days: planned leave must be applied for this many days ahead
 *    (Annual = 3). Zero means no notice requirement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->foreignId('deducts_from_leave_type_id')->nullable()->after('requires_attachment')
                ->constrained('leave_types')->nullOnDelete();
            $table->boolean('is_unplanned')->default(false)->after('deducts_from_leave_type_id');
            $table->unsignedSmallInteger('min_notice_days')->default(0)->after('is_unplanned');
        });
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deducts_from_leave_type_id');
            $table->dropColumn(['is_unplanned', 'min_notice_days']);
        });
    }
};
