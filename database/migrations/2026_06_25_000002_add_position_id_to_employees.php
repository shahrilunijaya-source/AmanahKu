<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Position band drives timesheet costing. Nullable: an employee may be
            // unassigned, in which case no manday/manhour rate can be resolved.
            // Distinct from the free-text `position` job-title column.
            $table->foreignId('position_id')->nullable()->after('level')
                ->constrained('positions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('position_id');
        });
    }
};
