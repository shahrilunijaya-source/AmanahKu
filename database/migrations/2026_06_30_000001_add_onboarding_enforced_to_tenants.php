<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the onboarding gates (launch lock + staff profile-completion) are enforced
 * for a tenant. New companies opt in through the provisioning flow; existing companies
 * are backfilled to enforced so a live workspace behaves the same after this deploy.
 * Defaults to false at the column level so directly-inserted fixtures (tests) are not
 * gated unless they explicitly opt in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('onboarding_enforced')->default(false)->after('status');
        });

        // Existing real companies should enforce onboarding from now on.
        DB::table('tenants')->update(['onboarding_enforced' => true]);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('onboarding_enforced');
        });
    }
};
