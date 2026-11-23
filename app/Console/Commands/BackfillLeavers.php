<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\LeaverBackfill;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;

/**
 * Command-line way in to App\Services\LeaverBackfill (the directory's "Upload leaver
 * dates" screen is the other). The CSV holds staff names, so it stays OUT of the repo.
 * A dry run unless --apply is passed.
 */
class BackfillLeavers extends Command
{
    protected $signature = 'staff:backfill-leavers {file : CSV with name,last_working_day,resigned_on,joined_on}
        {--tenant= : Company slug}
        {--apply : Write the changes (default is a dry run)}';

    protected $description = 'Record leaving dates for resigned staff from a CSV and archive them on their last working day.';

    public function handle(CurrentTenant $context, LeaverBackfill $backfill): int
    {
        $tenant = Tenant::where('slug', (string) $this->option('tenant'))->first();
        $rows = LeaverBackfill::readCsv((string) $this->argument('file'));
        if (! $tenant || $rows === null) {
            $this->error(! $tenant ? 'Unknown --tenant slug.' : 'Cannot read the CSV file.');

            return self::FAILURE;
        }
        $context->set($tenant);

        foreach ($backfill->plan($rows) as $item) {
            $line = "{$item['name']}: {$item['detail']}".($item['outcome'] === 'ready' ? ", last day {$item['last_working_day']}" : '');
            $item['outcome'] === 'skipped' ? $this->warn("{$line} (skipped)") : $this->line($line);
        }

        if ($this->option('apply')) {
            $this->info('Updated '.$backfill->apply($rows).' leaver(s).');
        } else {
            $this->info('Dry run only. Re-run with --apply to write these changes.');
        }
        $context->set(null);

        return self::SUCCESS;
    }
}
