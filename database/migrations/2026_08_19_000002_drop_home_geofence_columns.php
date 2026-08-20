<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work-from-home is no longer fenced, so the stored home coordinates are no longer read by
 * anything. Keeping them would be the worst of both outcomes: unused, but still the most
 * sensitive location the app holds for every remote worker.
 *
 * down() recreates the columns but NOT the values. There is NO working rollback for this
 * migration — once up() runs, the coordinate data is gone for good. The deploy that carries
 * this to staging/production takes a mysqldump first, and that dump is the only copy of
 * these coordinates that will exist afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['home_latitude', 'home_longitude', 'home_locked_at']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('wfh_radius_m');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('home_latitude', 10, 7)->nullable()->after('work_arrangement');
            $table->decimal('home_longitude', 10, 7)->nullable()->after('home_latitude');
            $table->timestamp('home_locked_at')->nullable()->after('home_longitude');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedInteger('wfh_radius_m')->nullable()->after('subscription_end');
        });
    }
};
