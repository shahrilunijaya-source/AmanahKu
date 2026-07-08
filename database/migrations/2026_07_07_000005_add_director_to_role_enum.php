<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add 'director' to the tenant_user.role enum. Director is a first-class, management-tier
 * role (Permissions::MANAGEMENT_TIER) assignable from the Roles & access screen; the DB
 * enum is the last gate that still enforced the original four, so it is widened here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_user', function (Blueprint $table) {
            $table->enum('role', ['employee', 'manager', 'management', 'director', 'hr'])
                ->default('employee')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_user', function (Blueprint $table) {
            $table->enum('role', ['employee', 'manager', 'management', 'hr'])
                ->default('employee')
                ->change();
        });
    }
};
