<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S10 / CR-06b §E4, E5: a contract variation (VO) is the only way contract_value,
 * contract_start, contract_end or client move after a project is created. Raised
 * pending, decided by the management tier; approval writes the next project_versions
 * row. Shape fixed in OPEN "QA / CR-06b / shapes fixed by CR06bTest".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_variations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('vo_no', 40);
            $table->date('variation_date');
            $table->text('reason');
            $table->json('changes');
            // string, not decimal(14,2): SQLite gives a decimal()/numeric() column
            // NUMERIC affinity, which silently drops the ".00" off any whole-number
            // value on write (confirmed empirically — see docs/build/OPEN.md). The
            // frozen acceptance test reads this column raw and asserts the exact
            // "-150000.00" string, so the column has to keep what it's given. The
            // model's decimal:2 cast still formats it for every other reader.
            $table->string('delta', 20)->nullable();
            $table->string('attachment_path', 500)->nullable();
            $table->string('status', 10)->default('pending');
            $table->foreignId('raised_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('version_id')->nullable()->constrained('project_versions')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'vo_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_variations');
    }
};
