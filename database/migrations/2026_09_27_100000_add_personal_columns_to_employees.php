<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = ['first_name', 'last_name', 'full_name_ic', 'religion', 'race', 'nationality', 'blood_type', 'personal_email', 'address_2', 'city', 'state', 'postcode', 'country', 'emergency_contact_relationship', 'passport_no', 'passport_expiry', 'permit_no', 'permit_expiry'];

    /** Worksy Personal tab fields. Everything nullable; country defaults to Malaysia. */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('first_name', 120)->nullable();
            $table->string('last_name', 120)->nullable();
            $table->string('full_name_ic', 200)->nullable();
            $table->string('religion', 40)->nullable();
            $table->string('race', 60)->nullable();
            $table->string('nationality', 80)->nullable();
            $table->string('blood_type', 4)->nullable();
            $table->string('personal_email', 190)->nullable();
            $table->string('address_2', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postcode', 12)->nullable();
            $table->string('country', 80)->nullable()->default('Malaysia');
            $table->string('emergency_contact_relationship', 60)->nullable();
            $table->string('passport_no', 40)->nullable();
            $table->date('passport_expiry')->nullable();
            $table->string('permit_no', 60)->nullable();
            $table->date('permit_expiry')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn(self::COLUMNS));
    }
};
