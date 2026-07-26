{{-- iOS shows no install prompt of its own, and Safari refuses notifications entirely
     until the app sits on the Home Screen. So the instruction has to be spelled out.
     Shown only on iPhone/iPad, only outside standalone mode, and only until dismissed. --}}
<div x-data="{
        show: false,
        init() {
            const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent);
            const installed = window.navigator.standalone === true;
            const dismissed = localStorage.getItem('amanahku:iosInstallDismissed') === '1';
            this.show = isIos && ! installed && ! dismissed;
        },
        dismiss() {
            localStorage.setItem('amanahku:iosInstallDismissed', '1');
            this.show = false;
        },
     }"
     x-show="show" x-cloak
     role="status" aria-live="polite"
     class="uj-banner-row"
     style="align-items:flex-start;gap:12px;padding:12px 16px;background:var(--red-tint);border-bottom:1px solid var(--hairline);flex-shrink:0;">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px;"><path d="M12 16V4M8 8l4-4 4 4"></path><path d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"></path></svg>
    <div style="flex:1;min-width:0;">
        <div style="font-size:13px;font-weight:600;color:var(--ink);"
             x-text="$store.ui.lang==='en' ? 'Get clock reminders on this iPhone' : 'Dapatkan peringatan jam di iPhone ini'">Get clock reminders on this iPhone</div>
        <div style="font-size:12px;color:var(--body);margin-top:2px;line-height:1.45;"
             x-text="$store.ui.lang==='en'
                ? 'Tap Share, then Add to Home Screen. Reminders only work from the installed app on iPhone.'
                : 'Tekan Kongsi, kemudian Tambah ke Skrin Utama. Peringatan hanya berfungsi dari aplikasi yang dipasang di iPhone.'">Tap Share, then Add to Home Screen.</div>
    </div>
    <button type="button" @click="dismiss()"
            :aria-label="$store.ui.lang==='en' ? 'Dismiss' : 'Tutup'"
            style="background:none;padding:2px;flex-shrink:0;line-height:0;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"></path></svg>
    </button>
</div>
