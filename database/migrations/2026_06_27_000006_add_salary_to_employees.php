<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-employee salary. The Position band sets the charge-out rate (max salary)
 * used for costing; this is the individual's actual pay, captured on the staff
 * form with the band max shown as a guide. Nullable — not every record has it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('salary', 12, 2)->nullable()->after('position_id');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('salary');
        });
    }
};
