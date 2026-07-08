<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expanded company profile + lifecycle fields on tenants. All additive + nullable
 * to preserve back-compat with existing seed data and the test suite. Branding
 * (logo, secondary colour, welcome message) and contact details are editable by the
 * company admin; status + subscription dates are super-admin-only (enforced in the
 * controllers, never surfaced in the tenant-facing UI).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('registration_number')->nullable()->after('name');
            $table->string('company_code')->nullable()->unique()->after('registration_number');
            $table->string('industry')->nullable()->after('company_code');
            $table->string('address')->nullable()->after('industry');
            $table->string('contact_number')->nullable()->after('address');
            $table->string('email')->nullable()->after('contact_number');
            $table->string('website')->nullable()->after('email');
            $table->string('logo_path')->nullable()->after('website');
            $table->string('secondary_color', 9)->nullable()->after('color');
            $table->string('welcome_message')->nullable()->after('secondary_color');
            // Lifecycle — super-admin controlled.
            $table->string('status')->default('active')->after('meta'); // active | suspended
            $table->date('subscription_start')->nullable()->after('status');
            $table->date('subscription_end')->nullable()->after('subscription_start');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'registration_number', 'company_code', 'industry', 'address',
                'contact_number', 'email', 'website', 'logo_path',
                'secondary_color', 'welcome_message',
                'status', 'subscription_start', 'subscription_end',
            ]);
        });
    }
};
