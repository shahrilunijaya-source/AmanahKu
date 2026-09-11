<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * CR-06a acceptance 5: every project that predates project_versions gets a
 * version 1 dated its own creation date. Idempotent — a project that already
 * has any version is left alone, so re-running (the migration calls this once,
 * a later `artisan projects:backfill-versions` can be re-run safely) never
 * duplicates a row.
 */
class BackfillProjectVersions extends Command
{
    protected $signature = 'projects:backfill-versions';

    protected $description = 'Write version 1 for every project that has no version yet.';

    public function handle(): int
    {
        $count = 0;

        // Cross-tenant on purpose (all_projects, all tenants): withoutGlobalScopes bypasses
        // BelongsToTenant, and every write below sets tenant_id explicitly, the same
        // approach the other cross-tenant commands use.
        Project::query()->withoutGlobalScopes()->orderBy('id')->each(function (Project $project) use (&$count) {
            $hasVersion = DB::table('project_versions')->where('project_id', $project->id)->exists();

            if ($hasVersion) {
                return;
            }

            // A project that predates this migration never went through the "backfill
            // client from code" update the migration itself ran once — a project
            // created directly (a test fixture, a seeder) after that migration still
            // needs the same courtesy the first time it gets a version.
            if (blank($project->client) && filled($project->code)) {
                $project->client = $project->code;
                $project->saveQuietly();
            }

            DB::table('project_versions')->insert([
                'tenant_id' => $project->tenant_id,
                'project_id' => $project->id,
                'version_no' => 1,
                'effective_date' => ($project->created_at ?? now())->toDateString(),
                'snapshot' => json_encode($project->masterSnapshot()),
                'changes' => null,
                'reason' => null,
                'created_by_id' => null,
                'created_at' => now(),
            ]);

            $count++;
        });

        $this->info("Backfilled {$count} project version(s).");

        return self::SUCCESS;
    }
}
