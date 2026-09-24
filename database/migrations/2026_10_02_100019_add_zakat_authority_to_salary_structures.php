<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Payroll → Form → Zakat: which state zakat body a staff member's deduction is paid
        // to (StatutoryOptions::ZAKAT_AUTHORITIES). Null lists them under "No zakat authority set".
        Schema::table('salary_structures', function (Blueprint $t) {
            $t->string('zakat_authority', 30)->nullable()->after('zakat_monthly');
        });
    }

    public function down(): void
    {
        Schema::table('salary_structures', function (Blueprint $t) {
            $t->dropColumn('zakat_authority');
        });
    }
};
