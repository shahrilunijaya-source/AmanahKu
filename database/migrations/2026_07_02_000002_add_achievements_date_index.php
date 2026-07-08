<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (tenant_id, date) index on achievements (AK-PERF-03). The recognition feed orders by
 * date within a tenant on every dashboard load; the `date` column (added later, in
 * create_performance_and_recognition) never got an index. Purely additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('achievements', function (Blueprint $table) {
            $table->index(['tenant_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('achievements', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'date']);
        });
    }
};
