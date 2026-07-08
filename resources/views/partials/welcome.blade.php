{{--
    First-run welcome — shown once to orient a brand-new (often inexperienced)
    HR user. Explains that the system guides them, and how to switch language.
    Dismissed forever via localStorage `amanahku-welcomed`; re-openable from the
    header help button (sets the same flag back off). Bilingual via $store.ui.lang.
--}}
<div x-data="{ show: ! localStorage.getItem('amanahku-welcomed') }"
     @welcome-open.window="show = true"
     @keydown.escape.window="show = false">
<template x-teleport="body">
<div x-show="show" x-cloak
     style="position:fixed;inset:0;z-index:200;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(31,30,26,.55);backdrop-filter:blur(2px);">

    <div @click.outside="show = false"
         style="width:100%;max-width:460px;margin:auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 24px 70px rgba(31,30,26,.30);">

        {{-- Header band --}}
        <div style="padding:24px 26px 18px;background:linear-gradient(135deg,var(--red),#b03a2e);color:#fff;">
            <div style="width:40px;height:40px;border-radius:11px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;margin-bottom:13px;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18h6M10 22h4M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.3 1 2.1h6c0-.8.4-1.6 1-2.1A7 7 0 0 0 12 2z"></path></svg>
            </div>
            <h2 style="font-size:19px;font-weight:600;margin:0;letter-spacing:-0.3px;"
                x-text="$store.ui.lang==='en' ? 'Welcome to Amanahku' : 'Selamat datang ke Amanahku'"></h2>
            <p style="font-size:13px;margin:6px 0 0;opacity:.92;line-height:1.5;"
               x-text="$store.ui.lang==='en' ? 'New to HR? No problem — this system guides you at every step.' : 'Baru dalam HR? Tidak mengapa — sistem ini membimbing anda setiap langkah.'"></p>
        </div>

        {{-- Points --}}
        <div style="padding:20px 26px 8px;display:flex;flex-direction:column;gap:15px;">
            @php
                $points = [
                    ['M9 18h6M10 22h4M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.3 1 2.1h6c0-.8.4-1.6 1-2.1A7 7 0 0 0 12 2z',
                     'Every screen explains itself', 'Setiap skrin terangkan dirinya',
                     'Read the guide banner at the top — it says what the screen is for and what to do.',
                     'Baca panduan di bahagian atas — ia terangkan fungsi skrin dan apa perlu dibuat.'],
                    ['M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z',
                     'Forms guide you', 'Borang membimbing anda',
                     'Hints under each box tell you exactly what to type or choose.',
                     'Petua di bawah setiap kotak tunjuk apa perlu ditaip atau dipilih.'],
                    ['M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20',
                     'Read it in your language', 'Baca dalam bahasa anda',
                     'Switch between English and Bahasa Malaysia anytime — the EN | BM toggle, top-right.',
                     'Tukar antara English dan Bahasa Malaysia bila-bila masa — butang EN | BM, penjuru kanan atas.'],
                    ['M5 12h14M13 6l6 6-6 6',
                     'Not sure where to start?', 'Tidak pasti nak mula di mana?',
                     'Begin at the Dashboard, then explore each section in the left menu.',
                     'Mula di Dashboard, kemudian terokai setiap bahagian dalam menu kiri.'],
                ];
            @endphp
            @foreach ($points as $p)
                <div style="display:flex;gap:13px;align-items:flex-start;">
                    <div style="width:30px;height:30px;border-radius:8px;background:var(--red-tint);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $p[0] }}"></path></svg>
                    </div>
                    <div style="min-width:0;">
                        <div style="font-size:13.5px;font-weight:600;color:var(--ink);" x-text="$store.ui.lang==='en' ? @js($p[1]) : @js($p[2])"></div>
                        <div style="font-size:12.5px;color:var(--muted);line-height:1.5;margin-top:1px;" x-text="$store.ui.lang==='en' ? @js($p[3]) : @js($p[4])"></div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Footer --}}
        <div style="padding:16px 26px 22px;display:flex;align-items:center;justify-content:space-between;gap:12px;">
            <div style="display:flex;background:var(--canvas);border:1px solid var(--hairline);border-radius:8px;padding:2px;gap:1px;">
                <button @click="$store.ui.setLang('en')" :style="'padding:5px 11px;border-radius:6px;font-size:12px;font-weight:600;background:'+($store.ui.lang==='en'?'var(--red)':'transparent')+';color:'+($store.ui.lang==='en'?'#fff':'var(--muted)')">EN</button>
                <button @click="$store.ui.setLang('ms')" :style="'padding:5px 11px;border-radius:6px;font-size:12px;font-weight:600;background:'+($store.ui.lang==='ms'?'var(--red)':'transparent')+';color:'+($store.ui.lang==='ms'?'#fff':'var(--muted)')">BM</button>
            </div>
            <button @click="localStorage.setItem('amanahku-welcomed','1'); show = false"
                    class="uj-btn-primary" style="height:42px;padding:0 22px;font-size:13.5px;"
                    x-text="$store.ui.lang==='en' ? 'Get started' : 'Mula sekarang'"></button>
        </div>
    </div>
</div>
</template>
</div>
