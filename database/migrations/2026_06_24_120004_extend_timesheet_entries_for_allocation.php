<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Upgrade timesheet entries from free-text project + hours to the
     * category / project / sub-pillar / percentage allocation model.
     * The legacy `project` and `hours` columns are kept (nullable) for
     * back-compat with rows seeded before this change; a later migration
     * can drop them once nothing references them.
     */
    public function up(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('entry_date')
                ->constrained('timesheet_categories')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->after('category_id')
                ->constrained('projects')->nullOnDelete();
            $table->foreignId('sub_pillar_id')->nullable()->after('project_id')
                ->constrained('project_sub_pillars')->nullOnDelete();
            $table->decimal('percentage', 5, 2)->default(0)->after('sub_pillar_id'); // 0.00–100.00
            $table->text('description')->nullable()->after('percentage');            // sanitised HTML

            // Reports filter by tenant + date range across all employees.
            $table->index(['tenant_id', 'entry_date']);
        });

        // Relax the legacy columns so new-shape rows (no project string / no hours) are valid.
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->string('project', 120)->nullable()->change();
            $table->decimal('hours', 4, 2)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropConstrainedForeignId('project_id');
            $table->dropConstrainedForeignId('sub_pillar_id');
            $table->dropColumn(['percentage', 'description']);
            $table->dropIndex(['tenant_id', 'entry_date']);
        });

        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->string('project', 120)->nullable(false)->change();
            $table->decimal('hours', 4, 2)->default(0)->change();
        });
    }
};
