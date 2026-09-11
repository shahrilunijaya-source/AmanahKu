<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\Tenant;
use App\Ports\Data\MailMessage;
use App\Ports\MailPort;
use App\Support\ManagementExceptions;
use App\Support\Permissions;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * CR-17: the deferred 08:00 digest. For each tenant, one MailMessage through MailPort
 * to FINAL_APPROVAL_ROLES (management, director, hr) summarising today's lateness and
 * overdue counts, plus an in-app notice for the same people. Idempotent per tenant per
 * day: a `port_outbox` row already carrying today's `management_digest` for the tenant
 * means this run is skipped, so a retry (or the scheduler firing twice) never doubles up.
 */
class ManagementDigest extends Command
{
    protected $signature = 'management:digest';

    protected $description = 'Email each tenant\'s management/director/HR a daily lateness + overdue summary.';

    public function handle(CurrentTenant $context, ManagementExceptions $exceptions, MailPort $mail): int
    {
        $today = now()->toDateString();
        $sent = 0;

        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $context->set($tenant);

            try {
                if ($this->alreadySentToday($tenant->id, $today)) {
                    continue;
                }

                $recipients = $tenant->users()->wherePivotIn('role', Permissions::FINAL_APPROVAL_ROLES)->get();
                if ($recipients->isEmpty()) {
                    continue;
                }

                $lateCount = collect($exceptions->lateness(null))
                    ->filter(fn (array $r) => str_starts_with($r['status_en'], 'Late'))->count();
                $overdueCards = collect($exceptions->overdue(null))->sum(fn (array $g) => count($g['cards']));

                $bodyEn = "{$lateCount} people are late today. {$overdueCards} cards are overdue.";
                $bodyMs = "{$lateCount} orang lewat hari ini. {$overdueCards} kad tertunggak.";

                $result = $mail->send(new MailMessage(
                    to: $recipients->pluck('email')->all(),
                    subject: 'Daily management digest',
                    bodyEn: $bodyEn,
                    bodyMs: $bodyMs,
                    kind: 'management_digest',
                ));

                if ($result->ok) {
                    foreach ($recipients as $recipient) {
                        AppNotification::send($recipient->id, 'Daily management digest', $bodyEn, dedupeKey: "management-digest-{$tenant->id}-{$today}");
                    }
                    $sent++;
                }
            } catch (\Throwable $e) {
                report($e);
                $this->error("Management digest failed for tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        $context->set(null);

        $this->info("Management digest sent for {$sent} tenant(s).");

        return self::SUCCESS;
    }

    private function alreadySentToday(int $tenantId, string $today): bool
    {
        return DB::table('port_outbox')
            ->where('tenant_id', $tenantId)
            ->where('port', 'mail')->where('method', 'send')
            ->where('payload', 'like', '%management_digest%')
            ->whereDate('created_at', $today)
            ->exists();
    }
}
