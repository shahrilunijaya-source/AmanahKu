<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LHDN's Employer's Tax Identification Number, prefixed "E" on every statutory form
 * (Form EA header, and eventually Form E / CP8D). Distinct from `registration_number`
 * (the SSM company registration number) — LHDN issues this one separately. Stored
 * without the "E" prefix; the prefix is print formatting, not data (see EaFormPdfData).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('employer_tin')->nullable()->after('registration_number');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('employer_tin');
        });
    }
};
