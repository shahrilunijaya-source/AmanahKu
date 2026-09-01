<?php

declare(strict_types=1);

namespace App\Support;

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
     * @var array<string, array{title: string, title_ms: string, blurb: string, blurb_ms: string, category: string, roles: list<string>|null, screen: string|null, column: string}>
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
        'pulse' => [
            'title' => 'Company pulse', 'title_ms' => 'Nadi syarikat',
            'blurb' => 'Headcount, timesheets past lock, claims outstanding.',
            'blurb_ms' => 'Bilangan kakitangan, kad waktu lewat kunci, tuntutan tertunggak.',
            'category' => 'Team', 'roles' => Permissions::FINAL_APPROVAL_ROLES, 'screen' => null, 'column' => 'right',
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
            if (! isset($placed[$id])) {
                $layout[self::ALL[$id]['column']][] = $id;
            }
        }

        return $layout;
    }
}
