<?php

use App\Console\Commands\BackfillProjectVersions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * S09 / CR-06a: version history for the project master. One row per effective
 * master-field change, version 1 on create. Acceptance 5: existing projects must
 * migrate as version 1 with effective date = creation date, so `up()` backfills
 * them immediately via `projects:backfill-versions` (idempotent, safe to re-run).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version_no');
            $table->date('effective_date');
            $table->json('snapshot');
            $table->json('changes')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['project_id', 'version_no']);
        });

        // Guarded: the command class ships in the same session, but a rollback that
        // replays only part of history (or an environment where the console kernel
        // has not autoloaded it yet) must not take the migration down with it.
        if (class_exists(BackfillProjectVersions::class)) {
            Artisan::call('projects:backfill-versions');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_versions');
    }
};
