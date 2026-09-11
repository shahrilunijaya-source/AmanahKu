<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Ports\Data\MailMessage;
use App\Ports\MailPort;
use App\Support\ManagementMeeting;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CR-34 scope 2 (DEFERRED — the run has no mail provider): every Friday 15:00 (Thursday on
 * a holiday Friday), one generic MailPort intent per tenant to every manager/attendee — the
 * same recipient set the Friday-morning task uses — asking them to update Track before the
 * 5 PM meeting. No per-project lists, no counts. Idempotent per tenant per trigger day: a
 * `port_outbox` row already carrying today's `management_meeting_reminder` for the tenant
 * skips the run.
 */
class SendManagementMeetingReminder extends Command
{
    protected $signature = 'management:meeting-reminder';

    protected $description = 'Send (via MailPort) the Friday 3 PM "update Track before the meeting" reminder to every manager.';

    private const SUBJECT = 'Management meeting today 5 PM, update your Track';

    private const BODY_EN = "Hello managers, let's prep for the management meeting. Please update your Track entry before 5 PM. Open Track.";

    private const BODY_MS = 'Helo pengurus, mari kita bersedia untuk mesyuarat pengurusan. Sila kemas kini kemasukan Track anda sebelum 5 petang. Buka Track.';

    public function handle(CurrentTenant $context, ManagementMeeting $meeting, MailPort $mail): int
    {
        $today = Carbon::now()->startOfDay();
        $sent = 0;

        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $context->set($tenant);

            try {
                if ($this->alreadySentToday($tenant->id, $today->toDateString())) {
                    continue;
                }

                if ($this->sendForTenant($today, $meeting, $mail)) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                report($e);
                $this->error("Management meeting reminder failed for tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        $context->set(null);
        $this->info("Management meeting reminder sent for {$sent} tenant(s).");

        return self::SUCCESS;
    }

    private function sendForTenant(Carbon $today, ManagementMeeting $meeting, MailPort $mail): bool
    {
        $settings = $meeting->settings();
        if ($settings->isPausedOn($today) || ! $meeting->isTriggerDay($today, $settings)) {
            return false;
        }

        $recipients = $meeting->recipients($settings);
        if ($recipients->isEmpty()) {
            return false;
        }

        $result = $mail->send(new MailMessage(
            to: $recipients->map(fn ($person) => $person->user?->email)->filter()->values()->all(),
            subject: self::SUBJECT,
            bodyEn: self::BODY_EN,
            bodyMs: self::BODY_MS,
            kind: 'management_meeting_reminder',
        ));

        if (! $result->ok) {
            return false;
        }

        foreach ($recipients as $person) {
            if ($person->user_id) {
                AppNotification::send($person->user_id, 'Management meeting today: update Track', self::BODY_EN, dedupeKey: 'management-meeting-reminder-'.$person->tenant_id.'-'.$today->toDateString());
            }
        }

        AuditLog::record('Sent management meeting reminder', $recipients->count().' recipient(s) for '.$today->toDateString());

        return true;
    }

    private function alreadySentToday(int $tenantId, string $today): bool
    {
        return DB::table('port_outbox')
            ->where('tenant_id', $tenantId)
            ->where('port', 'mail')->where('method', 'send')
            ->where('payload', 'like', '%management_meeting_reminder%')
            ->whereDate('created_at', $today)
            ->exists();
    }
}
