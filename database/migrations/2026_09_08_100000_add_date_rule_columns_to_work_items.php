<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('archived_at');
        });

        // sqlite (test DB) already stores `type` as a plain string column, so any value
        // fits with no ALTER needed. MySQL enforces the enum at the schema level.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE work_items MODIFY type ENUM('assignment','task','adhoc','event') NOT NULL DEFAULT 'task'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE work_items MODIFY type ENUM('assignment','task','adhoc') NOT NULL DEFAULT 'task'");
        }

        Schema::table('work_items', function (Blueprint $table) {
            $table->dropColumn('cancelled_at');
        });
    }
};
