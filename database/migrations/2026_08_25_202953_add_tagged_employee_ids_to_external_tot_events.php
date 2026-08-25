<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_tot_events', function (Blueprint $table) {
            $table->json('tagged_employee_ids')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('external_tot_events', function (Blueprint $table) {
            $table->dropColumn('tagged_employee_ids');
        });
    }
};
