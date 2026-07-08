<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Office geofence + working hours live on the branch.
        Schema::table('branches', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('state');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->unsignedInteger('radius_m')->default(200)->after('longitude');
            $table->time('work_start')->nullable()->after('radius_m');
            $table->time('work_end')->nullable()->after('work_start');
            $table->decimal('min_hours', 4, 1)->nullable()->after('work_end');
        });

        // Client sites for resident engineers — own coords + the client's working hours.
        Schema::create('work_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('client')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('radius_m')->default(200);
            $table->time('work_start')->nullable();
            $table->time('work_end')->nullable();
            $table->decimal('min_hours', 4, 1)->nullable();
            $table->timestamps();
        });

        // Per-employee work arrangement, registered home, and the hybrid weekday split.
        Schema::table('employees', function (Blueprint $table) {
            $table->enum('work_arrangement', ['office', 'client', 'wfh', 'hybrid'])->default('office')->after('status');
            $table->foreignId('work_site_id')->nullable()->after('branch_id')->constrained('work_sites')->nullOnDelete();
            $table->decimal('home_latitude', 10, 7)->nullable()->after('work_arrangement');
            $table->decimal('home_longitude', 10, 7)->nullable()->after('home_latitude');
            $table->timestamp('home_locked_at')->nullable()->after('home_longitude');
            // ISO weekdays (1=Mon..7=Sun) the staff is expected in the office; the rest are home days.
            $table->json('hybrid_office_days')->nullable()->after('home_locked_at');
        });

        // Attendance evaluation: clock-out geo, expected-site snapshot, radius checks,
        // justifications, computed minutes, and the list of raised flags.
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->decimal('clock_out_latitude', 10, 7)->nullable()->after('longitude');
            $table->decimal('clock_out_longitude', 10, 7)->nullable()->after('clock_out_latitude');
            $table->string('expected_site_type')->nullable()->after('clock_out_longitude'); // office | client | home
            $table->time('expected_start')->nullable()->after('expected_site_type');
            $table->time('expected_end')->nullable()->after('expected_start');
            $table->decimal('expected_min_hours', 4, 1)->nullable()->after('expected_end');
            $table->boolean('in_radius')->nullable()->after('expected_min_hours');
            $table->boolean('out_radius')->nullable()->after('in_radius');
            $table->text('clock_in_justification')->nullable()->after('out_radius');
            $table->text('clock_out_justification')->nullable()->after('clock_in_justification');
            $table->unsignedSmallInteger('worked_minutes')->nullable()->after('clock_out_justification');
            $table->json('flags')->nullable()->after('worked_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn([
                'clock_out_latitude', 'clock_out_longitude', 'expected_site_type',
                'expected_start', 'expected_end', 'expected_min_hours', 'in_radius',
                'out_radius', 'clock_in_justification', 'clock_out_justification',
                'worked_minutes', 'flags',
            ]);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_site_id');
            $table->dropColumn(['work_arrangement', 'home_latitude', 'home_longitude', 'home_locked_at', 'hybrid_office_days']);
        });

        Schema::dropIfExists('work_sites');

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'radius_m', 'work_start', 'work_end', 'min_hours']);
        });
    }
};
