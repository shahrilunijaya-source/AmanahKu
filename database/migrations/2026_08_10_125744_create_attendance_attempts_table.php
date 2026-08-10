<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per clock-in / clock-out attempt, refused ones included.
 *
 * A refused punch is not an error: the server answers 302 with a message in the error
 * bag, which is ordinary HTTP traffic that no log line and no error tracker records.
 * So "I cannot clock in" arrived with no evidence behind it at all — not in the fault
 * table, not in the audit ledger, not in a log file (production offers none to read).
 * This table is that evidence: the outcome, and enough about the device and the payload
 * to tell a phone apart from a desk machine.
 *
 * Deliberately NOT the audit ledger: audit_logs is the company's own record of who
 * changed what, and a staff member being told to add a reason is not a company event.
 * Read by a super-admin only, and pruned nightly alongside error_events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_attempts', function (Blueprint $table) {
            $table->id();
            // Plain columns, not foreign keys — same reason as error_events: the row must
            // outlive the employee, user or company it points at.
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            // 'in' or 'out' as submitted. Not validated here: an attempt rejected FOR a
            // bad action still has to be recordable, or the one case worth seeing is the
            // one case missing.
            $table->string('action', 8)->nullable();
            // ok | noop | needs_justification | needs_photo | invalid — indexed because
            // the only useful question is "show me everything that was not ok".
            $table->string('outcome', 32)->index();
            // The exact sentence the staff member saw. This is what makes the row
            // readable without reproducing the punch.
            $table->string('message', 500)->nullable();
            // Whether a fix arrived, NOT the coordinates. A successful punch already
            // stores its coordinates on the attendance record; a diagnostic table has no
            // business holding a second copy of somebody's location.
            $table->boolean('has_location');
            $table->boolean('has_photo');
            // Bytes, and the whole request body. Together these separate a phone camera
            // selfie that overran a PHP upload limit from a punch that never sent one.
            $table->unsignedInteger('photo_bytes')->nullable();
            $table->unsignedInteger('content_length')->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_attempts');
    }
};
