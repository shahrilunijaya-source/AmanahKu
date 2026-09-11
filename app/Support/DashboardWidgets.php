<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * The dashboard's widget registry — one row per card, listed in the order a
 * brand-new user meets them. Ported from the approved public/_dash-unified.html
 * prototype, which replaced the old Me/Company scope switch with a single
 * dashboard of role-gated cards.
 *
 * `roles` is a SERVER-side gate: a widget the viewer's role is not listed for is
 * never built, never rendered and cannot be turned on from the picker, so it is
 * not a CSS concern and a hand-written POST cannot smuggle it back in.
 * `screen` is the tenant feature gate — a widget whose module is switched off
 * reads as absent, the same rule AppController::screen() applies to whole screens.
 * `column` is only the DEFAULT placement; a user's saved drag order overrides it.
 * `after` (optional) names the card a new widget sits under by default (CR-32):
 * without it a widget lands at the bottom of its column. The full-width bands
 * above the grid (moments, management, awards) are not widgets — see
 * BuildsDashboardWidgets::dashboardBands().
 */
final class DashboardWidgets
{
    /**
     * Widgets a user can never hide. Pending tasks is the viewer's real action
     * list — letting someone bury their own obligations is exactly what the old
     * pinned queue existed to prevent.
     */
    public const PINNED = ['tasks'];

    /** The two grid columns, left first. */
    public const COLUMNS = ['left', 'right'];

    /**
     * The registry. `roles` null means everyone; `screen` null means core (no
     * module can switch it off); `column` is the default side of the grid.
     *
     * @var array<string, array{title: string, title_ms: string, blurb: string, blurb_ms: string, category: string, roles: list<string>|null, screen: string|null, column: string, after?: string}>
     */
    public const ALL = [
        'summary' => [
            'title' => 'Current month summary', 'title_ms' => 'Ringkasan bulan ini',
            'blurb' => 'Your hours, overtime, leave and lateness this month.',
            'blurb_ms' => 'Jam kerja, kerja lebih masa, cuti dan lewat anda bulan ini.',
            'category' => 'Me', 'roles' => null, 'screen' => 'attendance', 'column' => 'left',
        ],
        'clock' => [
            'title' => 'Daily clock log', 'title_ms' => 'Log kehadiran harian',
            'blurb' => "Today's shift and your recent punches.",
            'blurb_ms' => 'Syif hari ini dan rekod masuk keluar terkini anda.',
            'category' => 'Attendance', 'roles' => null, 'screen' => 'attendance', 'column' => 'left',
        ],
        'tasks' => [
            'title' => 'Pending tasks', 'title_ms' => 'Tugasan tertunggak',
            'blurb' => 'Everything still waiting on you, grouped.',
            'blurb_ms' => 'Semua yang masih menunggu tindakan anda, dikumpulkan.',
            'category' => 'Me', 'roles' => null, 'screen' => null, 'column' => 'left',
        ],
        'friday' => [
            'title' => 'Friday sign-off', 'title_ms' => 'Penutup Jumaat',
            'blurb' => 'Wrap up the week. Shows from Friday 3 PM to Monday 9 AM.',
            'blurb_ms' => 'Tutup minggu. Dipaparkan dari Jumaat 3 petang hingga Isnin 9 pagi.',
            'category' => 'Me', 'roles' => null, 'screen' => null, 'column' => 'left', 'after' => 'tasks',
        ],
        'leave' => [
            'title' => 'My leave summary', 'title_ms' => 'Ringkasan cuti saya',
            'blurb' => 'Entitlement and balance per leave type.',
            'blurb_ms' => 'Kelayakan dan baki mengikut jenis cuti.',
            'category' => 'Leave', 'roles' => null, 'screen' => 'leave', 'column' => 'left',
        ],
        'stuck' => [
            'title' => 'Reaching nobody', 'title_ms' => 'Tiada penerima',
            'blurb' => 'Requests with no one able to approve them.',
            'blurb_ms' => 'Permohonan tanpa sesiapa yang boleh meluluskannya.',
            'category' => 'Team', 'roles' => Permissions::FINAL_APPROVAL_ROLES, 'screen' => null, 'column' => 'left',
        ],
        'calendar' => [
            'title' => 'My calendar', 'title_ms' => 'Kalendar saya',
            'blurb' => 'Who is on leave, what events are coming.',
            'blurb_ms' => 'Siapa bercuti, acara apa yang mendatang.',
            'category' => 'Me', 'roles' => null, 'screen' => 'calendar', 'column' => 'right',
        ],
        'attendance' => [
            'title' => 'Team attendance', 'title_ms' => 'Kehadiran pasukan',
            'blurb' => 'Who is in, short, on leave or absent today.',
            'blurb_ms' => 'Siapa hadir, lewat, bercuti atau tidak hadir hari ini.',
            'category' => 'Team', 'roles' => Permissions::OVERSIGHT_ROLES, 'screen' => 'attendance', 'column' => 'right',
        ],
        'notices' => [
            'title' => 'Notice board', 'title_ms' => 'Papan notis',
            'blurb' => 'Company announcements.',
            'blurb_ms' => 'Pengumuman syarikat.',
            'category' => 'Me', 'roles' => null, 'screen' => null, 'column' => 'right',
        ],
        'flowers' => [
            'title' => 'Flowers', 'title_ms' => 'Bunga',
            'blurb' => 'Colleagues caught being brilliant this month.',
            'blurb_ms' => 'Rakan sekerja yang ditangkap cemerlang bulan ini.',
            'category' => 'Team', 'roles' => null, 'screen' => null, 'column' => 'right', 'after' => 'notices',
        ],
        'claims' => [
            'title' => 'My claim summary', 'title_ms' => 'Ringkasan tuntutan saya',
            'blurb' => 'What you claimed this year and where it stands.',
            'blurb_ms' => 'Apa yang anda tuntut tahun ini dan statusnya.',
            'category' => 'Claim', 'roles' => null, 'screen' => 'claims', 'column' => 'right',
        ],
        'work' => [
            'title' => 'My work summary', 'title_ms' => 'Ringkasan kerja saya',
            'blurb' => 'Clock in and out, day by day.',
            'blurb_ms' => 'Masuk dan keluar, hari demi hari.',
            'category' => 'Attendance', 'roles' => null, 'screen' => 'attendance', 'column' => 'right',
        ],
        'style' => [
            'title' => 'My working style', 'title_ms' => 'Gaya kerja saya',
            'blurb' => 'Your Profile Test result: archetype and the four-way split.',
            'blurb_ms' => 'Keputusan Ujian Profil anda: arketip dan pecahan empat hala.',
            'category' => 'Me', 'roles' => null, 'screen' => 'profile-test', 'column' => 'right', 'after' => 'work',
        ],
        'pulse' => [
            'title' => 'Company pulse', 'title_ms' => 'Nadi syarikat',
            'blurb' => 'Headcount, timesheets past lock, claims outstanding.',
            'blurb_ms' => 'Bilangan kakitangan, kad waktu lewat kunci, tuntutan tertunggak.',
            'category' => 'Team', 'roles' => Permissions::FINAL_APPROVAL_ROLES, 'screen' => null, 'column' => 'right',
        ],
        // CR-11 (docs/build/contracts/dashboard-slots.md): present only for an upcoming or
        // just-past company event with attendees — see BuildsDashboardWidgets' 'events'
        // filter, mirrors the 'friday' conditional card.
        'events' => [
            'title' => 'Events', 'title_ms' => 'Acara',
            'blurb' => 'An event coming up or just wrapped, and who is going.',
            'blurb_ms' => 'Acara yang akan datang atau baru selesai, dan siapa yang hadir.',
            'category' => 'Team', 'roles' => null, 'screen' => 'events', 'column' => 'right', 'after' => 'attendance',
        ],
    ];

    /**
     * Widgets that carry period arrows, and the slice each arrow moves by.
     *
     * `future` says whether the forward arrow may leave the present: the calendar
     * is there to show what is booked ahead, while the rest are logs of what
     * already happened and have nothing to say about a day that has not come.
     * A widget missing from this list has no arrows — the leave summary is the
     * one the mock drew them on, but a balance is a single running number with no
     * history behind it, so the arrows would relabel the same figures.
     *
     * @var array<string, array{unit: string, future: bool}>
     */
    public const PERIODS = [
        'clock' => ['unit' => 'day', 'future' => false],
        'calendar' => ['unit' => 'month', 'future' => true],
        'attendance' => ['unit' => 'day', 'future' => false],
        'claims' => ['unit' => 'year', 'future' => false],
        'work' => ['unit' => 'month', 'future' => false],
    ];

    /** Picker filter chips, in the order they are shown. */
    public const CATEGORIES = ['All', 'Me', 'Attendance', 'Leave', 'Claim', 'Team'];

    /** Every widget id, registry order. @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::ALL);
    }

    public static function exists(string $id): bool
    {
        return isset(self::ALL[$id]);
    }

    /**
     * Ids this role may see at all. Callers still have to drop widgets whose
     * gating module is off for the tenant — that needs the FeatureManager, which
     * has no business being resolved from a plain value object.
     *
     * @return list<string>
     */
    public static function forRole(string $role): array
    {
        return array_values(array_filter(
            self::ids(),
            fn (string $id): bool => self::ALL[$id]['roles'] === null || in_array($role, self::ALL[$id]['roles'], true),
        ));
    }

    /**
     * Whether the Friday sign-off card (CR-29, slot owned by CR-32) is on the page:
     * Friday 15:00 up to, not including, Monday 09:00, on the tenant clock.
     */
    public static function fridaySignOffOpen(CarbonImmutable $now): bool
    {
        return match ($now->dayOfWeek) {
            CarbonImmutable::FRIDAY => $now->hour >= 15,
            CarbonImmutable::SATURDAY, CarbonImmutable::SUNDAY => true,
            CarbonImmutable::MONDAY => $now->hour < 9,
            default => false,
        };
    }

    /**
     * The Friday whose sign-off window we are in, `Y-m-d`. Only meaningful
     * while fridaySignOffOpen() is true: Friday itself, or the Friday just
     * gone for Saturday, Sunday, or Monday before 09:00.
     */
    public static function fridayWeekOf(CarbonImmutable $now): string
    {
        return $now->subDays(($now->dayOfWeek - CarbonImmutable::FRIDAY + 7) % 7)->toDateString();
    }

    /** The period slice a widget's arrows move by, or null when it has none. */
    public static function periodUnit(string $id): ?string
    {
        return self::PERIODS[$id]['unit'] ?? null;
    }

    /** Whether a widget's forward arrow may go past the current period. */
    public static function allowsFuture(string $id): bool
    {
        return self::PERIODS[$id]['future'] ?? false;
    }

    /** The tenant module a widget needs, or null when it is core. */
    public static function gatingScreen(string $id): ?string
    {
        return self::ALL[$id]['screen'] ?? null;
    }

    /**
     * Picker rows for the given available ids.
     *
     * @param  list<string>  $ids
     * @return list<array{id: string, title: string, title_ms: string, blurb: string, blurb_ms: string, category: string, pinned: bool}>
     */
    public static function catalog(array $ids): array
    {
        return array_map(fn (string $id): array => self::ALL[$id] + [
            'id' => $id,
            'pinned' => in_array($id, self::PINNED, true),
        ], $ids);
    }

    /** Title pair for one widget, for the card header. @return array{0: string, 1: string} */
    public static function title(string $id): array
    {
        return [self::ALL[$id]['title'], self::ALL[$id]['title_ms']];
    }

    /**
     * Lay the available widgets out into the two columns, honouring the user's
     * saved drag order and falling back to each widget's default column for
     * anything the saved order has never seen (a widget added since they last
     * dragged, or a role change handing them a new one).
     *
     * @param  list<string>  $available  ids this viewer may see, gates applied
     * @param  array<string, list<string>>  $order  saved per-column order
     * @param  list<string>  $hidden
     * @return array<string, list<string>>
     */
    public static function layout(array $available, array $order, array $hidden): array
    {
        return self::layoutWith(self::ALL, $available, $order, $hidden);
    }

    /**
     * layout() against a given registry, so the anchor rule can be tested
     * without a real widget having to carry `after` yet.
     *
     * @param  array<string, array{column: string, after?: string}>  $registry
     * @param  list<string>  $available
     * @param  array<string, mixed>  $order
     * @param  list<string>  $hidden
     * @return array<string, list<string>>
     */
    public static function layoutWith(array $registry, array $available, array $order, array $hidden): array
    {
        $shown = array_values(array_diff($available, array_diff($hidden, self::PINNED)));
        $placed = [];
        $layout = [];

        foreach (self::COLUMNS as $column) {
            $layout[$column] = array_values(array_filter(
                array_map('strval', $order[$column] ?? []),
                function (string $id) use ($shown, &$placed): bool {
                    if (! in_array($id, $shown, true) || isset($placed[$id])) {
                        return false;
                    }
                    $placed[$id] = true;

                    return true;
                },
            ));
        }

        foreach ($shown as $id) {
            if (isset($placed[$id])) {
                continue;
            }
            $column = $registry[$id]['column'];
            $anchor = $registry[$id]['after'] ?? null;
            $at = $anchor === null ? false : array_search($anchor, $layout[$column], true);
            if ($at === false) {
                $layout[$column][] = $id;
            } else {
                array_splice($layout[$column], $at + 1, 0, [$id]);
            }
            $placed[$id] = true;
        }

        return $layout;
    }
}
