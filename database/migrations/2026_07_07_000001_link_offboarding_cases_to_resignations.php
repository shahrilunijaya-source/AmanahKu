<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links an offboarding case back to the resignation that spawned it (nullable — termination /
 * end-of-contract / retirement cases have no resignation) and records when archival closed the
 * case. Additive to 2026_06_24_000009_create_offboarding_tables.php; the status enum already
 * carries 'completed', now made live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offboarding_cases', function (Blueprint $table) {
            $table->foreignId('resignation_id')->nullable()->after('employee_id')
                ->constrained('resignations')->nullOnDelete();
            $table->timestamp('completed_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('offboarding_cases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resignation_id');
            $table->dropColumn('completed_at');
        });
    }
};
