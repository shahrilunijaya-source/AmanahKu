<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PullCalendarChangesJob;
use App\Models\GoogleCalendarConnection;
use Illuminate\Console\Command;

/** CR-01: queue a calendar pull for every connected person. Scheduled every five minutes. */
class PullCalendarChanges extends Command
{
    protected $signature = 'calendar:pull';

    protected $description = 'Pull Google Calendar changes for every connected user and reconcile their cards';

    public function handle(): int
    {
        $count = 0;
        GoogleCalendarConnection::query()->each(function (GoogleCalendarConnection $connection) use (&$count) {
            PullCalendarChangesJob::dispatch($connection->id);
            $count++;
        });

        $this->info("Queued calendar pulls for {$count} connection(s).");

        return self::SUCCESS;
    }
}
