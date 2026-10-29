<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A special work day can now be limited to one month ('YYYY-MM'). For that month it replaces
 * the every-month rule on the same weekday, so moving one month's TOT Saturday no longer
 * rewrites every other month. Null = every month, as before.
 *
 * The unique key widens to (tenant, weekday, month). The new key is added before the old one is
 * dropped because MySQL needs an index starting with tenant_id for the foreign key at all times.
 *
 * ponytail: NULLs count as distinct in a unique key, so "one every-month rule per weekday" is now
 * held by form validation only. Add a generated column (COALESCE(month, '')) to the key if a
 * duplicate ever slips in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_day_rules', function (Blueprint $table) {
            $table->char('month', 7)->nullable()->after('weekday');
            $table->unique(['tenant_id', 'weekday', 'month']);
        });

        Schema::table('work_day_rules', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::table('work_day_rules', function (Blueprint $table) {
            $table->unique(['tenant_id', 'weekday']);
        });

        Schema::table('work_day_rules', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'weekday', 'month']);
            $table->dropColumn('month');
        });
    }
};
