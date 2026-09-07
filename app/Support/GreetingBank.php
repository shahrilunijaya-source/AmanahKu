<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\GreetingLine;

/**
 * CR-33: the default dashboard-greeting lines every tenant starts with, plus
 * the seed + pick helpers the dashboard and migration both use.
 */
class GreetingBank
{
    /**
     * [trigger, English, Bahasa Melayu]. `{name}` is replaced with the viewer's
     * first name (or stripped) by BuildsDashboardData::meHead().
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    public const DEFAULTS = [
        // personal
        ['birthday', 'Happy birthday, {name}! The team has your back today.', 'Selamat hari lahir, {name}! Pasukan sokong awak hari ini.'],
        ['birthday', 'It is your day, {name}. Cake first, inbox later.', 'Hari ini hari awak, {name}. Kek dulu, inbox kemudian.'],
        ['birthday', 'Another year wiser, {name}. Happy birthday!', 'Setahun lagi bijak, {name}. Selamat hari lahir!'],
        ['birthday', 'Happy birthday, {name}. Go easy on yourself today.', 'Selamat hari jadi, {name}. Relaks sikit hari ini.'],

        // situation
        ['holiday_eve', 'Holiday tomorrow, {name}. Clear the desk and go.', 'Cuti esok, {name}. Kemas meja dan balik.'],
        ['holiday_eve', 'Almost there, {name} — a holiday is one sleep away.', 'Dah dekat, {name} — cuti tinggal satu tidur je lagi.'],
        ['holiday_eve', 'Wrap it up, {name}. Tomorrow is a holiday.', 'Habiskan kerja, {name}. Esok cuti.'],
        ['holiday_eve', 'Last push before the holiday, {name}. Then rest.', 'Tolakan terakhir sebelum cuti, {name}. Lepas tu rehat.'],
        ['overdue', 'A few cards are past due, {name}. One at a time.', 'Beberapa kad dah lepas tarikh, {name}. Satu-satu je.'],
        ['overdue', 'Some tasks are waiting on you, {name}. No rush, just don’t forget.', 'Ada tugasan tunggu awak, {name}. Tak perlu tergesa, jangan lupa je.'],
        ['overdue', 'A couple of overdue cards, {name}. Small wins still count.', 'Ada kad tertunggak, {name}. Kemenangan kecil pun dikira.'],
        ['overdue', 'Your board has a few late items, {name}. Pick the easiest first.', 'Board awak ada beberapa item lewat, {name}. Buat yang senang dulu.'],
        ['not_clocked_in', 'Morning, {name}. Do not forget to clock in.', 'Pagi, {name}. Jangan lupa clock in.'],
        ['not_clocked_in', 'Hey {name}, the clock is still waiting for you.', 'Hai {name}, clock masih tunggu awak.'],
        ['not_clocked_in', '{name}, punch in when you get a moment.', '{name}, clock in bila sempat ya.'],
        ['not_clocked_in', 'Quick one, {name} — you have not clocked in yet.', 'Sekejap, {name} — awak belum clock in lagi.'],

        // day
        ['monday', 'Monday, {name}. New week, clean slate.', 'Isnin, {name}. Minggu baru, permulaan baru.'],
        ['monday', 'Here we go again, {name}. You have got this week.', 'Mula lagi, {name}. Awak boleh handle minggu ini.'],
        ['monday', 'Monday reporting for duty, {name}.', 'Isnin lapor diri, {name}.'],
        ['monday', 'Ease into it, {name}. It is only Monday.', 'Ambil masa, {name}. Baru pun Isnin.'],
        ['friday', 'It is Friday, {name}. Finish strong, then go home.', 'Hari ini Jumaat, {name}. Habiskan dengan baik, lepas tu balik.'],
        ['friday', 'Friday, {name}! The weekend can see you from here.', 'Jumaat, {name}! Hujung minggu dah nampak dari sini.'],
        ['friday', 'Last stretch, {name}. Friday is nearly done.', 'Peringkat akhir, {name}. Jumaat hampir selesai.'],
        ['friday', 'Almost the weekend, {name}. Keep it light today.', 'Dah hampir hujung minggu, {name}. Buat ringan-ringan hari ini.'],
        ['friday', 'Friday vibes, {name}. Wrap up what you can.', 'Vibe Jumaat, {name}. Habiskan apa yang boleh.'],
        ['weekend', 'Weekend, {name}? Whatever brings you here, keep it short.', 'Hujung minggu, {name}? Apa-apa pun sebabnya, buat ringkas je.'],
        ['weekend', 'You are here on a weekend, {name}. Do not stay too long.', 'Awak datang hujung minggu, {name}. Jangan lama sangat.'],
        ['weekend', 'Weekend check-in, {name}? Go rest after this.', 'Semak hujung minggu, {name}? Pergi rehat lepas ni.'],
        ['weekend', '{name}, it is the weekend. Even work needs a break.', '{name}, ini hujung minggu. Kerja pun perlu rehat.'],

        // time — morning
        ['morning', 'Morning, {name}. Coffee first, emails second.', 'Pagi, {name}. Kopi dulu, emel kemudian.'],
        ['morning', 'Good morning, {name}. Let’s see what today brings.', 'Selamat pagi, {name}. Jom lihat apa hari ini bawa.'],
        ['morning', 'Rise and shine, {name}.', 'Bangun dan bersinar, {name}.'],
        ['morning', 'Morning, {name}. Ease in slowly.', 'Pagi, {name}. Mula perlahan-lahan.'],
        ['morning', 'Fresh start, {name}. Good morning.', 'Permulaan baru, {name}. Selamat pagi.'],
        ['morning', 'Morning, {name}. One task at a time today.', 'Pagi, {name}. Satu tugasan pada satu masa hari ini.'],
        ['morning', 'Good morning, {name}. The day is still yours to shape.', 'Selamat pagi, {name}. Hari ini masih milik awak untuk bentuk.'],
        ['morning', 'Morning, {name}! Hope the traffic was kind.', 'Pagi, {name}! Harap trafik tak teruk.'],
        ['morning', 'Good morning, {name}. Small steps, steady pace.', 'Selamat pagi, {name}. Langkah kecil, tempo stabil.'],
        ['morning', 'Morning, {name}. Let today be a good one.', 'Pagi, {name}. Semoga hari ini hari yang baik.'],
        ['morning', 'Good morning, {name}. Deep breath, then start.', 'Selamat pagi, {name}. Tarik nafas, lepas tu mula.'],
        ['morning', 'Morning, {name}. You made it in — that counts.', 'Pagi, {name}. Awak dah sampai — itu pun sudah bagus.'],

        // time — afternoon
        ['afternoon', 'Good afternoon, {name}. Halfway there.', 'Selamat tengah hari, {name}. Dah separuh jalan.'],
        ['afternoon', 'Afternoon, {name}. Time for a stretch, maybe.', 'Tengah hari, {name}. Boleh regangkan badan sikit.'],
        ['afternoon', 'Good afternoon, {name}. Keep the pace steady.', 'Selamat tengah hari, {name}. Kekalkan tempo.'],
        ['afternoon', 'Afternoon, {name}. Lunch settled in yet?', 'Tengah hari, {name}. Makan tengah hari dah selesa?'],
        ['afternoon', 'Good afternoon, {name}. Second wind time.', 'Selamat tengah hari, {name}. Masa untuk semangat baru.'],
        ['afternoon', 'Afternoon, {name}. Nearly through the day.', 'Tengah hari, {name}. Hampir habis hari ini.'],

        // time — evening
        ['evening', 'Good evening, {name}. Winding down time.', 'Selamat petang, {name}. Masa untuk perlahan-lahan.'],
        ['evening', 'Evening, {name}. Tie up loose ends and head home.', 'Petang, {name}. Selesaikan yang tinggal dan balik.'],
        ['evening', 'Good evening, {name}. Nearly there.', 'Selamat petang, {name}. Hampir sampai.'],
        ['evening', 'Evening, {name}. Home time is close.', 'Petang, {name}. Waktu balik dah dekat.'],
        ['evening', 'Good evening, {name}. Save the rest for tomorrow.', 'Selamat petang, {name}. Simpan bakinya untuk esok.'],
        ['evening', 'Evening, {name}. Good work today.', 'Petang, {name}. Kerja yang baik hari ini.'],
        ['evening', 'Good evening, {name}. Almost time to log off.', 'Selamat petang, {name}. Hampir masa log off.'],

        // time — late
        ['late', 'Still here, {name}? Whatever it is, it can wait till tomorrow.', 'Masih di sini, {name}? Apa-apa pun boleh tunggu esok.'],
        ['late', 'It is late, {name}. Go get some rest.', 'Dah lewat, {name}. Pergi rehat.'],
        ['late', '{name}, this hour is for sleeping, not spreadsheets.', '{name}, waktu ini untuk tidur, bukan spreadsheet.'],
        ['late', 'Burning the midnight oil, {name}? Take care of yourself.', 'Bekerja larut malam, {name}? Jaga diri elok-elok.'],
        ['late', 'Late night, {name}. Save your energy for tomorrow.', 'Malam yang lewat, {name}. Simpan tenaga untuk esok.'],
        ['late', 'It can wait, {name}. Go rest.', 'Boleh tunggu, {name}. Pergi rehat.'],
    ];

    /** Seed the default bank for one tenant, only if it has no lines yet. */
    public static function seed(int $tenantId): void
    {
        if (GreetingLine::where('tenant_id', $tenantId)->exists()) {
            return;
        }

        $now = now();
        $rows = array_map(fn (array $line) => [
            'tenant_id' => $tenantId,
            'bucket' => GreetingLine::TRIGGERS[$line[0]]['bucket'],
            'trigger' => $line[0],
            'text_en' => $line[1],
            'text_ms' => $line[2],
            'approved_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], self::DEFAULTS);

        GreetingLine::query()->insert($rows);
    }

    /**
     * First bucket (in priority order) that has an approved line matching one of
     * $triggers wins; a random line from that bucket, excluding $lastId when more
     * than one candidate exists. Null when the tenant's bank is empty.
     *
     * @param  list<string>  $triggers
     */
    public static function pick(int $tenantId, array $triggers, ?string $lastId): ?GreetingLine
    {
        foreach (GreetingLine::BUCKETS as $bucket) {
            $candidates = GreetingLine::where('tenant_id', $tenantId)
                ->approved()
                ->where('bucket', $bucket)
                ->whereIn('trigger', $triggers)
                ->get();

            if ($candidates->isEmpty()) {
                continue;
            }

            if ($candidates->count() > 1 && $lastId !== null) {
                $narrowed = $candidates->reject(fn (GreetingLine $l) => (string) $l->id === $lastId);
                if ($narrowed->isNotEmpty()) {
                    $candidates = $narrowed;
                }
            }

            return $candidates->random();
        }

        return null;
    }
}
