<?php

declare(strict_types=1);

namespace App\Support;

/**
 * CR-14b: display copy for every award key (name + explanation line, en/ms) and the
 * carousel/screen order — "Order: manual awards (The Chosen One, Main Character Energy)
 * first, then the rest" (docs/specs/CR-14.md "Dashboard card"), fixed by the OPEN.md
 * shape entry as chosen_one, main_character, then App\Support\Awards::KEYS order.
 *
 * Pure copy, no computation — App\Support\Awards (S17, frozen scope) owns every number.
 * ponytail: BM strings are plain translations, not reviewed copy — same corner the app
 * already cuts for "The Playground" itself; swap freely, nothing reads these keys back.
 */
final class AwardCatalog
{
    /** The four manual-pick award keys (nominated or selected, never auto-computed). */
    public const MANUAL_KEYS = ['main_character', 'office_yoda', 'new_but_dangerous', 'chosen_one'];

    /** Only these two manual keys take peer nominations; the other two are picked. */
    public const NOMINATED_KEYS = ['main_character', 'office_yoda'];

    /** @return list<string> */
    public static function order(): array
    {
        // QA S18 F6: every manual key needs a place, or array_search() returns false and
        // office_yoda / new_but_dangerous sort ahead of everything.
        return ['chosen_one', 'main_character', 'office_yoda', 'new_but_dangerous', ...Awards::KEYS];
    }

    /** @return array{en: array{name: string, sub: string}, ms: array{name: string, sub: string}} */
    public static function copy(string $key): array
    {
        return self::CATALOG[$key] ?? [
            'en' => ['name' => $key, 'sub' => ''],
            'ms' => ['name' => $key, 'sub' => ''],
        ];
    }

    private const CATALOG = [
        'beating_the_traffic' => ['en' => ['name' => 'CEO of Beating the Traffic', 'sub' => 'Earliest to clock in, every single day'], 'ms' => ['name' => 'CEO Elak Jem', 'sub' => 'Paling awal daftar masuk, setiap hari']],
        'never_late' => ['en' => ['name' => 'Late? Never Heard of Her', 'sub' => 'Not a single late shift this month'], 'ms' => ['name' => 'Lewat? Tak Pernah Dengar', 'sub' => 'Tiada satu pun syif lewat bulan ini']],
        'always_here' => ['en' => ['name' => 'Always Here Somehow', 'sub' => 'Full attendance, no absent, no incomplete'], 'ms' => ['name' => 'Sentiasa Ada', 'sub' => 'Kehadiran penuh, tiada tidak hadir, tiada tidak lengkap']],
        'clockwork_royalty' => ['en' => ['name' => 'Clockwork Royalty', 'sub' => 'Longest streak of on-time days'], 'ms' => ['name' => 'Raja Ketepatan Masa', 'sub' => 'Jujukan hari tepat masa terpanjang']],
        'timesheet_done' => ['en' => ['name' => 'Timesheet? Already Done', 'sub' => 'Submitted daily, never chased'], 'ms' => ['name' => 'Lembaran Masa? Dah Siap', 'sub' => 'Dihantar setiap hari, tak pernah dikejar']],
        'billable' => ['en' => ['name' => 'Billable & Unbothered', 'sub' => 'Most hours on client projects'], 'ms' => ['name' => 'Boleh Dicaj & Tenang', 'sub' => 'Jam terbanyak pada projek klien']],
        'deadline_who' => ['en' => ['name' => 'Deadline Who?', 'sub' => 'Most cards finished before the due date'], 'ms' => ['name' => 'Tarikh Akhir Apa?', 'sub' => 'Paling banyak kad siap sebelum tarikh akhir']],
        'zero_overdue' => ['en' => ['name' => "Overdue? Couldn't Be Me", 'sub' => 'Zero overdue with a real workload'], 'ms' => ['name' => 'Tertunggak? Bukan Saya', 'sub' => 'Sifar tertunggak dengan beban kerja sebenar']],
        'chief_firefighter' => ['en' => ['name' => 'Chief Firefighter', 'sub' => 'Most High-priority cards put out'], 'ms' => ['name' => 'Ketua Bomba', 'sub' => 'Paling banyak kad keutamaan tinggi diselesaikan']],
        'done_and_dusted' => ['en' => ['name' => 'Done & Dusted', 'sub' => 'Most cards moved to Done'], 'ms' => ['name' => 'Selesai & Sudah', 'sub' => 'Paling banyak kad dipindah ke Selesai']],
        'not_my_task' => ['en' => ['name' => 'Not My Task, Still Did It', 'sub' => "Most help given on others' work"], 'ms' => ['name' => 'Bukan Tugas Saya, Tetap Buat', 'sub' => 'Paling banyak bantuan diberi pada kerja orang lain']],
        'mic_drop_mentor' => ['en' => ['name' => 'Mic Drop Mentor', 'sub' => 'Best TOT presenter'], 'ms' => ['name' => 'Mentor Mic Drop', 'sub' => 'Pembentang TOT terbaik']],
        'question_department' => ['en' => ['name' => 'The Question Department', 'sub' => 'Shows up and speaks up'], 'ms' => ['name' => 'Jabatan Soalan', 'sub' => 'Hadir dan bersuara']],
        'walking_wikipedia' => ['en' => ['name' => 'Walking Wikipedia', 'sub' => 'Most knowledge shared'], 'ms' => ['name' => 'Wikipedia Berjalan', 'sub' => 'Paling banyak ilmu dikongsi']],
        'chief_hype_officer' => ['en' => ['name' => 'Chief Hype Officer', 'sub' => 'Most colleagues cheered on'], 'ms' => ['name' => 'Ketua Pemberi Semangat', 'sub' => 'Paling ramai rakan sekerja disorak']],
        'main_character' => ['en' => ['name' => 'Main Character Energy', 'sub' => 'Went the extra mile, nominated by peers'], 'ms' => ['name' => 'Tenaga Watak Utama', 'sub' => 'Berusaha lebih, dicalonkan rakan sekerja']],
        'office_yoda' => ['en' => ['name' => 'Office Yoda', 'sub' => 'Mentor of the month, nominated by peers'], 'ms' => ['name' => 'Yoda Pejabat', 'sub' => 'Mentor bulan ini, dicalonkan rakan sekerja']],
        'new_but_dangerous' => ['en' => ['name' => 'New but Dangerous', 'sub' => 'Best new joiner (under 6 months)'], 'ms' => ['name' => 'Baru Tapi Berbahaya', 'sub' => 'Ahli baharu terbaik (bawah 6 bulan)']],
        'chosen_one' => ['en' => ['name' => 'The Chosen One', 'sub' => 'Director\'s pick, for anything that deserves it'], 'ms' => ['name' => 'Yang Terpilih', 'sub' => 'Pilihan Pengarah, untuk apa jua yang layak']],
    ];
}
