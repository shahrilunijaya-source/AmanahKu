<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matrix reporting + directors.
 *
 * The org chart's solid line stays a single parent (employees.reports_to_id) so the
 * tree still renders and the daily approval flow keeps working. This adds:
 *
 *  - employees.is_director — an explicit HR-set flag that marks a person as a company
 *    director. Directors pin to the top of the chart with a badge; the flag is decoupled
 *    from the `management` login role on purpose.
 *  - employee_manager — additional (dotted-line) managers. A requester's leave/claim/
 *    overtime can be verified by their primary superior OR any of these extra managers
 *    ("either manager verifies"). Purely additive: no existing reports_to_id link moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('is_director')->default(false)->after('reports_to_id');
        });

        Schema::create('employee_manager', function (Blueprint $table) {
            $table->id();
            // The report (employee_id) gains an ADDITIONAL manager (manager_id). Both sides
            // cascade-delete so a removed person leaves no dangling verify link behind.
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('manager_id')->constrained('employees')->cascadeOnDelete();
            $table->timestamps();

            // One row per (report, extra manager) pair; the verify-queue lookups hit both columns.
            $table->unique(['employee_id', 'manager_id']);
            $table->index('manager_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_manager');

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('is_director');
        });
    }
};
