<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An "Other" project per tenant, for adhoc work that has no named project yet. It is a
 * real row so a card booked to it still reaches the timesheet (a Development or
 * Maintenance row needs a project id), but `is_other` keeps it off the Projects screen
 * and out of the project API. No categories attached, so it is offered for every
 * category that needs a project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('is_other')->default(false)->after('is_active');
        });

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::table('projects')->updateOrInsert(
                ['tenant_id' => $tenantId, 'name' => 'Other'],
                ['is_other' => true, 'is_active' => true, 'sort' => 65535, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        DB::table('projects')->where('is_other', true)->delete();

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('is_other');
        });
    }
};
