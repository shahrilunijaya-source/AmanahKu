<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S09 / CR-06a: the project master fields. `project_code` is the new immutable
 * integration key (unique per tenant); the old `code` stays as the short badge.
 * See docs/build/OPEN.md "QA / CR-06a / shapes fixed by CR06aTest" for the shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('project_code', 40)->nullable()->after('code');
            $table->string('client', 160)->nullable()->after('name');
            $table->string('status', 10)->default('active')->after('client');
            $table->decimal('contract_value', 14, 2)->nullable()->after('status');
            $table->string('procurement_method', 80)->nullable()->after('contract_value');
            $table->string('contractor', 160)->nullable()->after('procurement_method');
            $table->decimal('bond_value', 14, 2)->nullable()->after('contractor');
            $table->date('bond_submitted_at')->nullable()->after('bond_value');
            $table->date('loa_date')->nullable()->after('bond_submitted_at');
            $table->string('loa_ref', 80)->nullable()->after('loa_date');
            $table->date('agreement_date')->nullable()->after('loa_ref');
            $table->string('agreement_ref', 80)->nullable()->after('agreement_date');
            $table->date('contract_start')->nullable()->after('agreement_ref');
            $table->date('contract_end')->nullable()->after('contract_start');
            $table->string('drive_link', 500)->nullable()->after('contract_end');
            $table->foreignId('pm_id')->nullable()->after('drive_link')->constrained('employees')->nullOnDelete();
            $table->foreignId('pe_id')->nullable()->after('pm_id')->constrained('employees')->nullOnDelete();
            $table->timestamp('closed_at')->nullable()->after('pe_id');
            $table->foreignId('closed_by_id')->nullable()->after('closed_at')->constrained('employees')->nullOnDelete();

            $table->unique(['tenant_id', 'project_code']);
        });

        // Legacy rows carried the client name as their short `code` badge (e.g. "JKDM").
        // Give them a starting `client` value rather than leaving the new master field
        // empty for every project that predates this migration.
        DB::table('projects')->whereNull('client')->where('code', '!=', '')->whereNotNull('code')
            ->update(['client' => DB::raw('code')]);
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pm_id');
            $table->dropConstrainedForeignId('pe_id');
            $table->dropConstrainedForeignId('closed_by_id');
            $table->dropUnique(['tenant_id', 'project_code']);
            $table->dropColumn([
                'project_code', 'client', 'status', 'contract_value', 'procurement_method',
                'contractor', 'bond_value', 'bond_submitted_at', 'loa_date', 'loa_ref',
                'agreement_date', 'agreement_ref', 'contract_start', 'contract_end',
                'drive_link', 'closed_at',
            ]);
        });
    }
};
