{{-- Global popup, driven by the `notice` Alpine store (resources/js/toast.js). Reserved for
     what must not be missed; routine confirmations stay toasts. Centered and blocking by
     default; `corner: true` shows the same card in the toast corner without blocking the
     page (the update prompt, which can arrive while someone is mid-task). --}}
{{-- :style takes objects, not strings: a string would overwrite the display:none x-show sets. --}}
<div x-data x-show="$store.notice.open" x-cloak
     @keydown.escape.window="$store.notice.corner || $store.notice.close()"
     :class="$store.notice.corner ? 'uj-toast-host' : 'uj-dialog-overlay'"
     :role="$store.notice.corner ? 'status' : 'dialog'"
     :aria-modal="$store.notice.corner ? 'false' : 'true'" aria-labelledby="uj-notice-title"
     :style="$store.notice.corner
         ? { zIndex: 250 }
         : { position: 'fixed', inset: 0, zIndex: 250, padding: '20px', background: 'rgba(31,30,26,.55)', backdropFilter: 'blur(2px)' }">
    {{-- Only the centered popup takes focus; the corner card leaves the cursor where it was. --}}
    <div @click.outside="$store.notice.corner || $store.notice.close()" class="uj-slide"
         x-effect="if ($store.notice.open && $store.notice.seq && ! $store.notice.corner) { requestAnimationFrame(() => requestAnimationFrame(() => $refs.main.focus())) }"
         :style="$store.notice.corner
             ? { pointerEvents: 'auto', width: 'min(360px, calc(100vw - 32px))', margin: 0, padding: '22px 20px 18px', boxShadow: '0 12px 34px rgba(38,37,30,.18), 0 2px 6px rgba(38,37,30,.06)', border: '1px solid var(--hairline)' }
             : { width: '100%', maxWidth: '420px', margin: 'auto', padding: '28px 26px 22px', boxShadow: '0 24px 70px rgba(31,30,26,.30)', border: 0 }"
         style="position:relative;overflow:hidden;background:#fff;border-radius:16px;text-align:center;">
        {{-- Countdown bar. Keyed on seq so a replacing notice restarts it from full. --}}
        <template x-for="n in [$store.notice.seq]" :key="n">
            <div x-show="$store.notice.timeout > 0" aria-hidden="true"
                 :style="`position:absolute;top:0;left:0;height:4px;width:100%;transform-origin:left;animation:uj-toast-bar ${$store.notice.timeout}ms linear forwards;background:var(--${$store.notice.tone === 'info' ? 'info' : 'success'});`"></div>
        </template>
        <div :style="`width:48px;height:48px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;color:var(--${$store.notice.tone === 'info' ? 'info' : 'success'});background:color-mix(in srgb, currentColor 12%, #fff);`">
            <svg x-show="$store.notice.tone !== 'info'" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
            <svg x-show="$store.notice.tone === 'info'" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7L21 8M21 3v5h-5"/></svg>
        </div>
        <h2 id="uj-notice-title" x-text="$store.notice.title"
            style="font-size:18px;font-weight:600;color:var(--ink);margin:0 0 8px;letter-spacing:-0.3px;"></h2>
        <p x-show="$store.notice.body" x-text="$store.notice.body"
           style="font-size:14px;color:var(--muted);margin:0 0 20px;line-height:1.5;"></p>
        {{-- Labels never wrap; when the pair does not fit (BM on a phone) the buttons stack,
             and wrap-reverse puts the main one on top. --}}
        <div style="display:flex;flex-wrap:wrap-reverse;gap:10px;justify-content:center;margin-top:12px;">
            <button type="button" class="uj-btn-ghost" x-show="$store.notice.action" @click="$store.notice.close()"
                    style="flex:1 1 auto;max-width:220px;min-width:100px;height:40px;padding:0 20px;justify-content:center;white-space:nowrap;"
                    x-text="$store.ui.lang === 'en' ? 'Not now' : 'Bukan sekarang'">Not now</button>
            <button type="button" class="uj-btn-primary" x-ref="main"
                    @click="$store.notice.action ? $store.notice.action.run() : $store.notice.close()"
                    style="flex:1 1 auto;max-width:220px;min-width:120px;height:40px;padding:0 24px;justify-content:center;white-space:nowrap;"
                    x-text="$store.notice.action ? $store.notice.action.label : 'OK'">OK</button>
        </div>
    </div>
</div>
