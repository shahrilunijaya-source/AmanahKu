<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\CompanyEvent;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\WorkItem;
use App\Support\AutoDone;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * CR-19: every 15 minutes. Ships FLAGGED OFF (docs/build/RULES.md,
 * `config('services.auto_done.enabled')`, env `AMANAHKU_AUTO_DONE`, default false) —
 * with the flag off this only prints a dry-run line naming what it would have done, it
 * never writes. Everything this command does is additionally gated by never touching a
 * manual card: it only ever looks at event attendee cards and the awards nominate/select
 * system cards (`source` non-null), the same set `App\Support\Awards::creditableCards()`
 * already excludes.
 *
 * Two jobs, both read-only when the flag is off:
 *  - An event that has ended with an attendee still undecided (going/registered/maybe):
 *    never closed by time (only marking attendance or withdrawing the invite moves the
 *    card, via the Event controller's own App\Support\AutoDone calls) — this command only
 *    prompts the organiser once, per event (`app_notifications` dedupe key
 *    `event-attendance-<event id>`).
 *  - An awards "Nominate this month's awards" card still open once its due date has
 *    passed: archived (not Done) — a select card is never touched here, it only closes on
 *    `awards:publish` (see AwardsPublish::publishTenant()).
 */
class BoardAutoDone extends Command
{
    protected $signature = 'board:auto-done';

    protected $description = 'Every 15 minutes: prompt for pending event attendance, archive an unsubmitted awards nomination once its window closes.';

    public function handle(CurrentTenant $context): int
    {
        $live = (bool) config('services.auto_done.enabled');

        if (! $live) {
            $lines = [];
            foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
                $context->set($tenant);
                $lines = [...$lines, ...$this->previewTenant()];
            }
            $context->set(null);

            $this->info('board:auto-done dry-run: the scheduler is flagged off (AMANAHKU_AUTO_DONE); nothing was changed.');
            foreach ($lines as $line) {
                $this->line("  dry-run: {$line}");
            }
            if ($lines === []) {
                $this->line('  dry-run: nothing to do.');
            }

            return self::SUCCESS;
        }

        $notified = 0;
        $archived = 0;
        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $context->set($tenant);
            $notified += $this->notifyPendingAttendance();
            $archived += $this->archiveExpiredNominateCards();
        }
        $context->set(null);

        $this->info("board:auto-done: {$notified} organiser notification(s) sent, {$archived} nominate card(s) archived.");

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function previewTenant(): array
    {
        $lines = [];

        foreach ($this->eventsAwaitingAttendance() as $event) {
            $lines[] = "would prompt the organiser to record attendance for \"{$event->title}\"";
        }
        foreach ($this->expiredNominateCards() as $card) {
            $lines[] = "would archive \"{$card->title}\" (#{$card->id}), the nomination window closed";
        }

        return $lines;
    }

    private function notifyPendingAttendance(): int
    {
        $sent = 0;
        foreach ($this->eventsAwaitingAttendance() as $event) {
            $organiser = Employee::find($event->created_by_employee_id);
            $created = AppNotification::send(
                $organiser?->user_id,
                'Record attendance: '.$event->title,
                'The event is over and some attendees are still marked as pending.',
                route('app.screen', 'events'),
                'event-attendance-'.$event->id,
            );
            if ($created) {
                $sent++;
            }
        }

        return $sent;
    }

    private function archiveExpiredNominateCards(): int
    {
        $cards = $this->expiredNominateCards();
        foreach ($cards as $card) {
            AutoDone::archived($card, 'the nomination window closed');
        }

        return $cards->count();
    }

    /** @return Collection<int, CompanyEvent> events over with at least one still-pending attendee card */
    private function eventsAwaitingAttendance(): Collection
    {
        return CompanyEvent::where('tenant_id', app(CurrentTenant::class)->id())->get()
            ->filter(fn (CompanyEvent $event) => $event->isOver())
            ->filter(fn (CompanyEvent $event) => WorkItem::where('company_event_id', $event->id)->get()
                ->contains(fn (WorkItem $card) => $card->isPendingAttendance()))
            ->values();
    }

    /** @return Collection<int, WorkItem> open nominate cards past their due date */
    private function expiredNominateCards(): Collection
    {
        $today = Carbon::now()->startOfDay();

        return WorkItem::where('source', 'awards')
            ->where('source_ref', 'like', '%-nominate')
            ->where('status', '!=', 'done')
            ->whereNull('archived_at')
            ->whereNull('auto_closed_at')
            ->whereNotNull('due_at')
            ->get()
            ->filter(fn (WorkItem $card) => $card->due_at->copy()->startOfDay()->lt($today))
            ->values();
    }
}
