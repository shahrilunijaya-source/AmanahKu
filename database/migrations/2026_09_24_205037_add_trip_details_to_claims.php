<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trip details for mileage claims, so a claim carries every column of the paper
 * Borang Tuntutan Perjalanan (from, to, km, vehicle rate, toll, parking) and the
 * server can work the amount out itself instead of trusting the form.
 *
 * Nullable: every other claim type has no trip, and mileage claims filed before this
 * migration only ever stored the amount. Those print with the km and rate cells blank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->string('vehicle', 20)->nullable()->after('amount');
            $table->decimal('distance_km', 8, 1)->nullable()->after('vehicle');
            $table->string('trip_from', 120)->nullable()->after('distance_km');
            $table->string('trip_to', 120)->nullable()->after('trip_from');
            $table->decimal('toll', 10, 2)->nullable()->after('trip_to');
            $table->decimal('parking', 10, 2)->nullable()->after('toll');
        });
    }

    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->dropColumn(['vehicle', 'distance_km', 'trip_from', 'trip_to', 'toll', 'parking']);
        });
    }
};
