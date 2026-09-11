<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Employee;
use App\Models\GoogleCalendarConnection;
use App\Models\Tenant;
use App\Ports\CalendarPort;
use App\Support\Calendar\CalendarReconciler;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * CR-01: pull one person's calendar changes since their last pull and reconcile them,
 * once per tenant they belong to. The port keeps the incremental cursor (sync token) on
 * the connection; `since` is the fallback for a first pull.
 */
class PullCalendarChangesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $connectionId) {}

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    public function handle(CurrentTenant $context, CalendarPort $port, CalendarReconciler $reconciler): void
    {
        $connection = GoogleCalendarConnection::find($this->connectionId);
        if (! $connection) {
            return;
        }

        $since = CarbonImmutable::instance($connection->last_pulled_at ?? now()->subDays(30));
        $employees = Employee::withoutGlobalScope('tenant')->where('user_id', $connection->user_id)->get();
        $previous = $context->get();

        try {
            foreach ($employees as $employee) {
                $context->set(Tenant::find($employee->tenant_id));
                $result = $port->pullChanges($employee, $since);
                if ($result->ok) {
                    $reconciler->reconcile($employee, $result->payload);
                }
            }
        } finally {
            $context->set($previous);
        }

        $connection->forceFill(['last_pulled_at' => now()])->save();
    }
}
