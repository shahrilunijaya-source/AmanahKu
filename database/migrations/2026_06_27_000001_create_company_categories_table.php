<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Company categories (Stage 1 / 2 / 3) — the subscription package a super-admin
 * assigns to a company. The category only sets the DEFAULT feature package; the
 * resolved tenant entitlement (tenant_features) remains the source of truth. The
 * three rows are reference/lookup data, so they are seeded here to guarantee they
 * exist on every fresh install and in the test database (RefreshDatabase) without
 * depending on a separate seeder run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_categories', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();          // stage-1 / stage-2 / stage-3
            $table->unsignedTinyInteger('level')->unique(); // 1 / 2 / 3 — drives the package
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('company_categories')->insert([
            [
                'key' => 'stage-1', 'level' => 1,
                'name' => 'Stage 1 — Basic HR',
                'description' => 'Core HR: company, branches, departments, positions, staff, attendance, leave, announcements, documents and basic reporting.',
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'key' => 'stage-2', 'level' => 2,
                'name' => 'Stage 2 — HR Operations',
                'description' => 'Everything in Stage 1 plus recruitment, onboarding, claims, training, performance, disciplinary, assets and advanced reporting.',
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'key' => 'stage-3', 'level' => 3,
                'name' => 'Stage 3 — Intelligent HR',
                'description' => 'Everything in Stage 1 and 2 plus AI HR assistant, AI insights, workflow automation, predictive analytics and management dashboards.',
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);

        Schema::table('tenants', function (Blueprint $table) {
            $table->foreignId('company_category_id')->nullable()->after('plan')
                ->constrained('company_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_category_id');
        });
        Schema::dropIfExists('company_categories');
    }
};
