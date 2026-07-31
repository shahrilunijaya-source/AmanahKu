<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-membership data access scope, layered on the existing role enum. Defaults to
 * 'company' so every existing member keeps full-company visibility (no behaviour
 * change on upgrade). Narrower scopes (own/team/department/branch) opt a member into
 * a restricted view, enforced by App\Services\DataScope on privileged list queries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_user', function (Blueprint $table) {
            $table->string('data_scope')->default('company')->after('role'); // own|team|department|branch|company
        });
    }

    public function down(): void
    {
        Schema::table('tenant_user', function (Blueprint $table) {
            $table->dropColumn('data_scope');
        });
    }
};
