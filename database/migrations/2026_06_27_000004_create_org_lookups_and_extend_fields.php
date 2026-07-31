<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Richer org structure: tenant-scoped staff_levels + employment_types lookups, and
 * additive (nullable) fields on employees, branches and positions. Everything is
 * additive + nullable to preserve the existing seed data and test suite. The legacy
 * Employee.level string is kept for back-compat alongside the new staff_level_id FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 20)->nullable();
            $table->unsignedSmallInteger('rank')->nullable(); // seniority ordering
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('employment_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 20)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('staff_level_id')->nullable()->after('level')->constrained('staff_levels')->nullOnDelete();
            $table->foreignId('employment_type_id')->nullable()->after('staff_level_id')->constrained('employment_types')->nullOnDelete();
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->string('code', 40)->nullable()->after('name');
            $table->string('type')->nullable()->after('code'); // Headquarters/Branch/Office/Outlet/Project Site/Operational/Other
            $table->string('address')->nullable()->after('type');
            $table->string('contact_number', 40)->nullable()->after('address');
            $table->string('email')->nullable()->after('contact_number');
            $table->string('status')->default('active')->after('email');
            $table->date('effective_date')->nullable()->after('status');
            $table->unique(['tenant_id', 'code']);
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->string('code', 40)->nullable()->after('title');
            $table->foreignId('staff_level_id')->nullable()->after('code')->constrained('staff_levels')->nullOnDelete();
            $table->foreignId('reports_to_position_id')->nullable()->after('staff_level_id')->constrained('positions')->nullOnDelete();
            $table->string('default_role')->nullable()->after('reports_to_position_id'); // employee/manager/management/hr
            $table->boolean('is_managerial')->default(false)->after('default_role');
            $table->string('description')->nullable()->after('is_managerial');
            $table->string('status')->default('active')->after('description');
            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'code']);
            $table->dropConstrainedForeignId('staff_level_id');
            $table->dropConstrainedForeignId('reports_to_position_id');
            $table->dropColumn(['code', 'default_role', 'is_managerial', 'description', 'status']);
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'code']);
            $table->dropColumn(['code', 'type', 'address', 'contact_number', 'email', 'status', 'effective_date']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('staff_level_id');
            $table->dropConstrainedForeignId('employment_type_id');
        });

        Schema::dropIfExists('employment_types');
        Schema::dropIfExists('staff_levels');
    }
};
