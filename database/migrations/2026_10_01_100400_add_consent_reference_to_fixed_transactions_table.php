<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec F4: where the employee's written consent for a non-statutory deduction is filed (EA s.24). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_transactions', function (Blueprint $table) {
            $table->string('consent_reference', 160)->nullable()->after('remarks');
        });
    }

    public function down(): void
    {
        Schema::table('fixed_transactions', function (Blueprint $table) {
            $table->dropColumn('consent_reference');
        });
    }
};
