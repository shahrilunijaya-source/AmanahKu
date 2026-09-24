<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Payroll → Form's Edit button: what HR typed over on an LHDN staff form
        // (CP21 / CP22 / CP22A / PCB II), one row per person per form per year.
        Schema::create('payroll_form_overrides', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('form', 12);
            $t->unsignedSmallInteger('year');
            $t->json('fields');
            $t->timestamps();
            $t->unique(['tenant_id', 'employee_id', 'form', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_form_overrides');
    }
};
