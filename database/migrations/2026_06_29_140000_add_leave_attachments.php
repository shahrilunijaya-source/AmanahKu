<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supporting-document support for leave, aligned with the Employment Act 1955.
 *
 * Some leave types legally require proof: sick/medical and hospitalisation need a
 * medical certificate (s.60F), maternity needs a doctor/birth confirmation (s.37),
 * and paternity needs a birth/marriage record (s.60FA). `requires_attachment` marks
 * those types so the form and controller can demand a file; the request stores the
 * uploaded document on the private disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->boolean('requires_attachment')->default(false)->after('entitlement');
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->string('attachment_path')->nullable()->after('reason');
            $table->string('attachment_name')->nullable()->after('attachment_path');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn(['attachment_path', 'attachment_name']);
        });

        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn('requires_attachment');
        });
    }
};
