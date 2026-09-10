@php
    /**
     * CR-20: the full-screen holiday-eve greeting, shown once on the reload right after a
     * clock-out on the eve of a public holiday. Reads the `holiday_eve` flash; absent flash,
     * renders nothing. The stamp text is fixed on purpose.
     */
    $hv = session('holiday_eve');
    if (! $hv) { return; }
    $hDate = \Illuminate\Support\Carbon::parse($hv['date']);
    $back = \Illuminate\Support\Carbon::parse($hv['next_working_day']);
    $tomorrow = $hDate->isSameDay(now()->addDay());
    // "Unijaya Resources Sdn Bhd" does not fit the ring; the trading name does.
    $stampName = trim(preg_replace('/\s+(Sdn\.?\s*Bhd\.?|Berhad|Bhd\.?)$/i', '', $tenant['name'] ?? 'Unijaya'));
@endphp
<section class="uj-hv" x-data="{ open: true }" x-show="open" x-cloak
         @keydown.escape.window="open = false" role="dialog" aria-modal="true" aria-labelledby="uj-hv-name"
         x-init="$nextTick(() => $refs.ok.focus())">
    <div class="uj-hv-flakes" aria-hidden="true">
        @for ($i = 0; $i < 18; $i++)
            <i style="left:{{ mt_rand(0, 100) }}%;--d:{{ mt_rand(50, 80) / 10 }}s;--t:{{ mt_rand(0, 12) / 10 }}s;--r:{{ mt_rand(360, 900) }}deg;--x:{{ mt_rand(-60, 60) }}px"></i>
        @endfor
    </div>

    <header class="uj-hv-top">
        <span class="uj-hv-dot"></span>
        <b x-text="$store.ui.lang==='en' ? 'Clocked out' : 'Sudah keluar'">Clocked out</b>
        <span>{{ now()->format('H:i') }}</span><span>·</span><span>{{ now()->format('D j M Y') }}</span>
        <span class="uj-hv-tag" x-text="$store.ui.lang==='en' ? 'Holiday eve' : 'Malam cuti'">Holiday eve</span>
    </header>

    <div class="uj-hv-main">
        <div class="uj-hv-stamp" aria-hidden="true">
            <span class="uj-hv-stamp-big">CUTI</span>
            <span class="uj-hv-stamp-sm"><span class="uj-hv-stamp-co">{{ $stampName }} · </span>{{ $hDate->format('j M') }}</span>
        </div>
        <div class="uj-hv-when">
            <span x-text="$store.ui.lang==='en' ? @js($tomorrow ? 'Tomorrow' : 'Coming up') : @js($tomorrow ? 'Esok' : 'Akan datang')">{{ $tomorrow ? 'Tomorrow' : 'Coming up' }}</span>
            {{ $hDate->format('D j M') }} · <span x-text="$store.ui.lang==='en' ? 'Public holiday' : 'Cuti umum'">Public holiday</span>
        </div>
        <h1 class="uj-hv-name" id="uj-hv-name">{{ $hv['name'] }}</h1>
        <p class="uj-hv-msg" x-text="$store.ui.lang==='en' ? @js($hv['greeting_en']) : @js($hv['greeting_ms'])">{{ $hv['greeting_en'] }}</p>
        <div class="uj-hv-see">
            <span class="uj-hv-see-lbl" x-text="$store.ui.lang==='en' ? 'See you on' : 'Jumpa pada'">See you on</span>
            <span class="uj-hv-day">{{ $back->format('D j M') }}</span>
        </div>
    </div>

    <footer class="uj-hv-foot">
        <button type="button" class="uj-btn-primary uj-hv-ok" x-ref="ok" @click="open = false"
                >Selamat bercuti</button>
        <button type="button" class="uj-btn-ghost uj-hv-ghost" @click="open = false"
                x-text="$store.ui.lang==='en' ? 'Back to Attendance' : 'Kembali ke Kehadiran'">Back to Attendance</button>
        <span class="uj-hv-hint" x-text="$store.ui.lang==='en' ? 'shown once per holiday · Esc' : 'sekali setiap cuti · Esc'">shown once per holiday · Esc</span>
    </footer>
</section>
