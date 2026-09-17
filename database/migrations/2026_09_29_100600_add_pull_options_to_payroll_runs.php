<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which sources the run pulls in (Worksy's "Pull ..." ticks): fixed, claims, overtime,
     * unpaid. NULL means everything, which is how every run before this column behaved.
     */
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->json('pull_options')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropColumn('pull_options');
        });
    }
};
