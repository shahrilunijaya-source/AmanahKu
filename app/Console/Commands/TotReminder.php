<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TotSession;
use App\Models\User;
use App\Notifications\TotTomorrowMail;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily TOT reminder sweep.
 *
 * Three stages per slot, measured against the first Saturday of the slot's month:
 *   14 days out, topic still blank  -> nudge the presenter to pick one
 *    7 days out                     -> nudge the presenter to upload material
 *    1 day out                      -> tell everybody the session is tomorrow
 *
 * Every send carries a dedupe key, so a cron retry or a second run on the same day is a
 * no-op. Tenant-aware like the leave and timesheet commands: the active tenant is set per
 * loop so notification rows land under the right tenant, and the context is cleared at the
 * end. Per-tenant failures are logged and skipped rather than aborting the sweep.
 */
class TotReminder extends Command
{
    protected $signature = 'tot:remind';

    protected $description = 'Notify TOT presenters about an upcoming session, and everybody the day before.';

    /** Slot statuses that never produce a reminder. */
    private const SILENT_STATUSES = ['skipped', 'not_tot'];

    public function handle(CurrentTenant $context): int
    {
        $today = now()->startOfDay();
        $sent = 0;

        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $context->set($tenant);

            try {
                $sent += $this->sweepTenant($today);
            } catch (\Throwable $e) {
                report($e);
                $this->error("TOT reminder failed for tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        $context->set(null);

        $this->info("TOT reminders sent: {$sent}.");

        return self::SUCCESS;
    }

    /** Notify for every slot in this tenant whose session date is 14, 7, or 1 day away. */
    private function sweepTenant(Carbon $today): int
    {
        $sent = 0;

        $slots = TotSession::with(['presenter', 'presenters'])
            ->whereNotIn('status', self::SILENT_STATUSES)
            ->where('year', '>=', $today->year)
            ->get();

        foreach ($slots as $slot) {
            $date = TotSession::firstSaturday((int) $slot->year, (int) $slot->month);
            $url = route('app.screen', 'tot').'?year='.$slot->year;

            // Compare whole days by walking back from the session date rather than by
            // diffing. Carbon's signed diff semantics have changed between major versions;
            // isSameDay is unambiguous everywhere.
            // A slot can be presented by a team, so each of these goes to every presenter.
            // The dedupe key carries the employee id, or the second person's bell would be
            // swallowed as a duplicate of the first's.
            if ($date->copy()->subDays(14)->isSameDay($today) && blank($slot->title)) {
                foreach ($slot->presenterList() as $presenter) {
                    $sent += (int) AppNotification::send(
                        $presenter->user_id,
                        'Your TOT is in two weeks',
                        'The topic is still blank. Pick one so people can prepare.',
                        $url,
                        "tot:{$slot->id}:topic:{$presenter->id}",
                    );
                }
            }

            if ($date->copy()->subDays(7)->isSameDay($today)) {
                foreach ($slot->presenterList() as $presenter) {
                    $sent += (int) AppNotification::send(
                        $presenter->user_id,
                        'Your TOT is next Saturday',
                        'Upload your slides or notes to the TOT board before the session.',
                        $url,
                        "tot:{$slot->id}:prepare:{$presenter->id}",
                    );
                }
            }

            if ($date->copy()->subDay()->isSameDay($today)) {
                $title = $slot->title ?: 'topic to be announced';

                // One user at a time rather than sendMany(): the bell's dedupe key decides
                // who is new, and only a new bell mails. A cron retry the same day stays a
                // no-op in the inbox as well as on the board.
                $users = User::whereIn(
                    'id',
                    Employee::active()->whereNotNull('user_id')->pluck('user_id'),
                )->get();

                foreach ($users as $user) {
                    $created = AppNotification::send(
                        $user->id,
                        'TOT tomorrow',
                        $title.'. Material is on the TOT board.',
                        $url,
                        "tot:{$slot->id}:tomorrow",
                    );

                    if ($created) {
                        $user->notify(new TotTomorrowMail($slot, $url));
                        $sent++;
                    }
                }
            }
        }

        return $sent;
    }
}
