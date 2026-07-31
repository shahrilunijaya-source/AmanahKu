<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Receipt / supporting-document support for expense claims. Reimbursement needs
 * proof of spend, so a claim can carry an uploaded receipt (PDF or image) stored
 * on the private disk — mirrors the leave supporting-document model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->string('receipt_path')->nullable()->after('reason');
            $table->string('receipt_name')->nullable()->after('receipt_path');
        });
    }

    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->dropColumn(['receipt_path', 'receipt_name']);
        });
    }
};
