<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TotSession;
use Illuminate\Database\Seeder;

/**
 * Imports the TOT history from the Google Sheet this board replaces
 * ("2025 2026 TOT Sabtu"), covering 2024 through 2026.
 *
 * Idempotent: keyed on (year, month) via updateOrCreate, so a re-run is a no-op.
 *
 * Two rules worth knowing before changing anything here:
 *  - A past month with a PIC but no title imports as `planned`, not `done`. The sheet gives
 *    no evidence the session ran, so the import must not invent one. HR resolves those.
 *  - Nothing here credits a Knowledge Bank month. Backfilling two years would rewrite a
 *    counter that people currently read as lessons written.
 */
class TotHistorySeeder extends Seeder
{
    /**
     * year => month => [presenter, title, status, [[label, url], ...]]
     * A null presenter and null title means the sheet row was blank.
     *
     * @var array<int, array<int, array{0: ?string, 1: ?string, 2: string, 3: list<array{0: string, 1: string}>}>>
     */
    private const HISTORY = [
        2024 => [
            1 => ['Hakime', 'e-Filing (LHDN)', 'done', []],
            2 => [null, null, 'skipped', []],
            3 => ['Aizat', 'Power BI', 'done', []],
            4 => [null, null, 'skipped', []],
            5 => [null, null, 'skipped', []],
            6 => ['Kak Lin', 'Guidance for attendance and leave in the new HR system', 'done', []],
            7 => ['Roy', 'Power BI', 'done', []],
            8 => ['Qisti', 'Livewire Laravel', 'done', [
                ['Slides', 'https://drive.google.com/file/d/1nqwcX6cEiyqP9oqZ7J0Gv_x3fv4VQWw9/view?usp=drive_link'],
            ]],
            9 => ['Hiel', 'Selenium', 'done', [
                ['Slides', 'https://drive.google.com/file/d/1XHr4demW-zWjcRN2xLbk6LABHj_xD-HL/view?usp=drive_link'],
                ['Video', 'https://drive.google.com/file/d/1U1lpvT9nr8KAvkt3LYU4zbzEPX593WxT/view?usp=drive_link'],
            ]],
            10 => ['Rubmin', 'ETL tools', 'done', [
                ['Recording', 'https://app.read.ai/analytics/meetings/01J9D850JZXA9GK3C8WR73281V?utm_source=Share_CopyLink'],
                ['Slides', 'https://docs.google.com/presentation/d/17kHadxXmu-0fxXiBihXOXhlRXy9gGYtc/edit?usp=sharing'],
            ]],
            11 => ['PE', 'PE presentation review', 'done', [
                ['Recording', 'https://app.read.ai/analytics/meetings/01JBND503P22WSYD4H05NAEMXP?utm_source=Share_Nav'],
            ]],
            12 => [null, null, 'skipped', []],
        ],
        2025 => [
            1 => ['Team', 'Bad news social experiment', 'done', []],
            2 => ['Team', 'Catch the hacker', 'done', [
                ['Brief', 'https://www.canva.com/design/DAGdRRWvFh8/SRJeFyLaZdlCUHeAPBiu9w/edit'],
                ['Findings team A', 'https://www.canva.com/design/DAGee6ZoyGw/fgYHKyFX9QDPHfWfvFFUiQ/edit'],
                ['Findings team B', 'https://www.canva.com/design/DAGefOoJFlg/m8KkixzQpIW7I9BS3NZo7g/edit'],
            ]],
            3 => [null, null, 'skipped', []],
            4 => ['Emy', 'Testing tools', 'done', []],
            5 => ['Rubmin', 'Cordova', 'done', []],
            6 => ['Syakir', 'Web admin', 'done', []],
            7 => ['Kussairi', 'Proses kerja baru', 'done', []],
            8 => [null, null, 'skipped', []],
            9 => ['Kussairi', 'Cara kawal stress', 'done', []],
            10 => ['Faris', 'Flutter', 'done', []],
            11 => ['Shuk', 'Middleware', 'done', []],
            12 => ['Hafiz', 'Vue.js', 'done', []],
        ],
        2026 => [
            1 => ['Solihin', 'Google AI Antigravity', 'done', [
                ['Slides', 'https://docs.google.com/presentation/d/1zpU1_4khixjqtvP6hylAKLKSMAQIf5LR/edit?usp=drive_link'],
            ]],
            2 => ['Roy', null, 'planned', []],
            3 => ['Nabil', 'Install git on our own server', 'done', [
                ['Slides', 'https://drive.google.com/file/d/1q-O_zengvENin2wZlRaNVQl1wgtpin-I/view?usp=drive_link'],
            ]],
            4 => [null, 'Jamuan raya', 'not_tot', []],
            5 => ['Syafiq', 'Laravel AI chatbox v1', 'done', [
                ['Slides', 'https://docs.google.com/presentation/d/1jVvGoqBohSNGFeWiO1MalWhwtva14i8eIf_vO3pqqdw/edit'],
            ]],
            6 => ['Huraidi', null, 'planned', []],
            7 => ['Ah Yew', 'Content template manager', 'done', [
                ['Slides', 'https://drive.google.com/file/d/15-mIWngpyNET0pPGzWMYANIZqKg-yD68/view?usp=sharing'],
            ]],
            8 => ['Rubmin', null, 'planned', []],
            9 => ['Emy', null, 'planned', []],
            10 => ['Salam', null, 'planned', []],
            11 => ['Hafiz', null, 'planned', []],
            12 => ['Faris', null, 'planned', []],
        ],
    ];

    public function run(): void
    {
        // Self-scope tenant_id explicitly (no tenant session when run standalone via
        // `db:seed --class=`), matching the convention DatabaseSeeder uses for the same reason.
        $tenant = Tenant::where('slug', 'unijaya')->first();
        if (! $tenant) {
            $this->command?->error('TOT import: tenant "unijaya" not found. Run the base seeder first.');

            return;
        }

        $unmatched = [];

        foreach (self::HISTORY as $year => $months) {
            foreach ($months as $month => [$presenter, $title, $status, $links]) {
                $employee = $presenter ? $this->matchEmployee($tenant->id, $presenter) : null;

                if ($presenter && ! $employee) {
                    $unmatched[$presenter] = true;
                }

                TotSession::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'year' => $year, 'month' => $month],
                    [
                        'presenter_employee_id' => $employee?->id,
                        'presenter_name' => $employee ? null : $presenter,
                        'title' => $title,
                        'status' => $status,
                        'held_on' => $status === 'done'
                            ? TotSession::firstSaturday($year, $month)->toDateString()
                            : null,
                        'links' => $links === []
                            ? null
                            : array_map(fn (array $l) => ['label' => $l[0], 'url' => $l[1]], $links),
                    ],
                );
            }
        }

        if ($unmatched !== []) {
            $this->command?->warn(
                'TOT import: no employee matched for '.implode(', ', array_keys($unmatched))
                .'. These slots keep the name as free text; fix them on the TOT screen.'
            );
        }
    }

    /** Case-insensitive name match so "Roy" and "ROY" resolve to the same person. */
    private function matchEmployee(int $tenantId, string $name): ?Employee
    {
        return Employee::where('tenant_id', $tenantId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->first();
    }
}
