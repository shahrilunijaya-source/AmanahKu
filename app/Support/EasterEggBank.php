<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\EasterEgg;
use App\Models\EasterEggView;
use Carbon\CarbonInterface;

/**
 * CR-31: the default dashboard/board easter-egg lines every tenant starts
 * with, plus the seed + pick + once-a-day helpers the dashboard, the board
 * move() and the migration all use.
 *
 * "Keep it plain" (App\Support\DashboardPrefs key `plain`) is enforced by the
 * caller, not here: a plain viewer never calls showOnce() at all, so nothing
 * is computed and nothing is recorded — see BuildsDashboardData::dashboardEgg()
 * and WorkItemController::move().
 */
class EasterEggBank
{
    /** @var list<string> */
    public const KINDS = ['friday_late', 'inbox_zero', 'late_night', 'tab_collector', 'holiday_eve'];

    /**
     * [kind, English, Bahasa Melayu]. Late-night lines must never be judgemental
     * (see CR31Test's forbidden-word check) — every line here, in either kind, is
     * fine to show on its own.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    public const DEFAULTS = [
        ['friday_late', 'You may now pretend not to see new tasks.', 'Awak boleh buat-buat tak nampak tugasan baharu sekarang.'],
        ['friday_late', "It's past five on a Friday. The board can wait.", 'Dah lepas pukul lima hari Jumaat. Board boleh tunggu.'],
        ['friday_late', 'Friday, after hours. Everything new can wait for Monday.', 'Jumaat, lepas waktu pejabat. Semua yang baharu boleh tunggu Isnin.'],

        ['inbox_zero', 'Inbox zero, but for responsibilities.', 'Inbox zero, tapi untuk tanggungjawab.'],
        ['inbox_zero', 'Nothing overdue. Enjoy this rare moment.', 'Tiada apa yang tertunggak. Nikmati saat jarang ini.'],
        ['inbox_zero', 'The last overdue card is gone. Well done.', 'Kad tertunggak terakhir dah selesai. Syabas.'],

        ['late_night', 'Respectfully, why are you still here?', 'Dengan hormatnya, kenapa awak masih di sini?'],
        ['late_night', "The office is closed, even if the tab isn't.", 'Pejabat dah tutup, walaupun tab ini belum.'],
        ['late_night', 'It is late. Whatever this is, tomorrow works too.', 'Dah lewat. Apa-apa pun ini, esok pun boleh.'],

        ['tab_collector', 'Professional Tab Collector detected.', 'Pengumpul Tab Profesional dikesan.'],
        ['tab_collector', 'That\'s a lot of tabs open. We\'re impressed, honestly.', 'Banyak betul tab dibuka. Kami kagum, sungguh.'],
        ['tab_collector', 'Achievement unlocked: too many tabs.', 'Pencapaian dibuka: terlalu banyak tab.'],

        ['holiday_eve', 'A holiday is one sleep away.', 'Cuti tinggal satu tidur je lagi.'],
        ['holiday_eve', 'Wrap it up — tomorrow is a holiday.', 'Habiskan kerja — esok cuti.'],
        ['holiday_eve', 'Almost there. Tomorrow is off.', 'Dah dekat. Esok cuti.'],
    ];

    /** Seed the default bank for one tenant, only if it has no lines yet. */
    public static function seed(int $tenantId): void
    {
        if (EasterEgg::where('tenant_id', $tenantId)->exists()) {
            return;
        }

        $now = now();
        $rows = array_map(fn (array $line) => [
            'tenant_id' => $tenantId,
            'kind' => $line[0],
            'text_en' => $line[1],
            'text_ms' => $line[2],
            'approved_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], self::DEFAULTS);

        EasterEgg::query()->insert($rows);
    }

    /** A random approved line for $kind, or null when the tenant's bank has none. */
    public static function pick(int $tenantId, string $kind): ?EasterEgg
    {
        $candidates = EasterEgg::where('tenant_id', $tenantId)->approved()->where('kind', $kind)->get();

        return $candidates->isEmpty() ? null : $candidates->random();
    }

    /**
     * The once-a-day-per-user gate: records that $kind was shown to $employeeId
     * today and returns the line to show, or null when it was already shown
     * today (or the bank has nothing approved for $kind). firstOrCreate on the
     * unique (employee_id, kind, shown_on) key makes the "already shown today"
     * check and the recording the same atomic step.
     */
    public static function showOnce(int $tenantId, int $employeeId, string $kind, CarbonInterface $now): ?EasterEgg
    {
        // Pick first: an empty/all-pending bank must not burn the day's quota, so
        // the egg can still show later the same day once HR approves a line.
        $egg = self::pick($tenantId, $kind);
        if ($egg === null) {
            return null;
        }

        $view = EasterEggView::firstOrCreate([
            'tenant_id' => $tenantId,
            'employee_id' => $employeeId,
            'kind' => $kind,
            'shown_on' => $now->toDateString(),
        ]);

        return $view->wasRecentlyCreated ? $egg : null;
    }
}
