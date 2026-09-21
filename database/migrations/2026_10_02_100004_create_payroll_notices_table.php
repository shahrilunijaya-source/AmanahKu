<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec F11: the statutory notices a hire or a leaving date opens — LHDN CP22 / CP22A /
 * CP21, PERKESO Form 2 and KWSP registration. One row per employee per notice type per
 * due date, so a moved leaving date updates the open notice instead of stacking copies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_notices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('type');
            $t->date('due_on');
            $t->date('filed_on')->nullable();
            $t->string('reference', 80)->nullable();
            $t->foreignId('filed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('note', 255)->nullable();
            // LHDN clearance letter, CP22A only: until it lands the final pay is held.
            $t->date('cleared_on')->nullable();
            $t->timestamps();
            $t->unique(['employee_id', 'type', 'due_on']);
            $t->index(['tenant_id', 'type', 'filed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_notices');
    }
};
