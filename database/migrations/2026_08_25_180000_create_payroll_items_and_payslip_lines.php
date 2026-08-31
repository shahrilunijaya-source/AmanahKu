<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The named pay-item catalogue. Every amount on a payslip should trace back to one
        // of these — its flags say what a line built from it does to EPF, PERKESO (SOCSO +
        // EIS share one wage-base definition), PCB and the year-end EA form, instead of that
        // being hardcoded per-column in PayrollCalculator.
        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code');                          // stable slug, e.g. "basic-salary"
            $table->string('name');
            $table->string('name_ms')->nullable();
            $table->string('type');                          // earning | deduction
            $table->boolean('epf_liable')->default(false);
            $table->boolean('perkeso_liable')->default(false); // covers SOCSO + EIS — one shared wage base
            $table->boolean('pcb_taxable')->default(false);
            $table->decimal('pcb_exempt_cap_yearly', 12, 2)->nullable(); // e.g. RM6,000/yr travel exemption; recorded, not yet applied
            $table->string('ea_box')->nullable();             // year-end EA form box; recorded, nothing reads it yet
            $table->string('source')->default('manual');      // manual | claim | overtime | leave | salary
            $table->boolean('is_system')->default(false);     // seeded items — HR must not delete these
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        // A richer, itemised representation of a payslip's amounts alongside the existing
        // lumped columns (basic/allowances_total/bonus/...) — additive this pass, not a
        // replacement. `name` is a snapshot at write time so renaming a catalogue item later
        // never rewrites an already-issued payslip's history.
        Schema::create('payslip_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payslip_id')->constrained()->cascadeOnDelete();
            // Nullable only for legacy/migrated rows, or when the tenant has no catalogue
            // item seeded yet — see PayrollController's fallback flags.
            $table->foreignId('payroll_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('type');                           // earning | deduction
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('quantity', 8, 2)->nullable();     // e.g. overtime hours
            $table->string('source')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_lines');
        Schema::dropIfExists('payroll_items');
    }
};
