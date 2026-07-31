<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            // Null = the staffer typed this row. 'leave' / 'holiday' = generated from an
            // approved leave request or a public holiday, and regenerated on every save.
            $table->string('source', 16)->nullable()->after('percentage');
        });
    }

    public function down(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
