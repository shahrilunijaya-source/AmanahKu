<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Company Settings → Statutory & tax: the zakat employer number, and the person
        // whose name and designation are printed on CP21 / CP22 / CP22A / PCB II
        // (Worksy's "Signature Name").
        Schema::table('tenants', function (Blueprint $t) {
            $t->string('zakat_employer_no', 40)->nullable();
            $t->foreignId('statutory_signatory_employee_id')->nullable()->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropConstrainedForeignId('statutory_signatory_employee_id');
            $t->dropColumn('zakat_employer_no');
        });
    }
};
