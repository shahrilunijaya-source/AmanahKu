{{-- First-visit prompt for browser clock-in/out reminders. The permission request itself
     stays behind the bell dropdown's "Turn on alerts" button (click-driven, see notifier.js);
     this banner is just a more visible door to the same $store.alerts.enable(). It hides
     itself once canAsk goes false (permission granted or denied), so no extra hide logic
     is needed here beyond the user's own dismissal. --}}
<div x-data="{
        dismissed: localStorage.getItem('amanahku:alertsBannerDismissed') === '1',
        dismiss() {
            localStorage.setItem('amanahku:alertsBannerDismissed', '1');
            this.dismissed = true;
        },
     }"
     x-show="$store.alerts.canAsk && ! dismissed" x-cloak
     role="status" aria-live="polite"
     class="uj-banner-row"
     style="align-items:flex-start;gap:12px;padding:12px 16px;background:var(--red-tint);border-bottom:1px solid var(--hairline);flex-shrink:0;">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px;"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"></path></svg>
    <div style="flex:1;min-width:0;">
        <div style="font-size:13px;font-weight:600;color:var(--ink);"
             x-text="$store.ui.lang==='en' ? 'Turn on clock reminders' : 'Hidupkan peringatan jam'">Turn on clock reminders</div>
        <div style="font-size:12px;color:var(--body);margin-top:2px;line-height:1.45;"
             x-text="$store.ui.lang==='en'
                ? 'Get a notification when you forget to clock in or out. Only works while Amanahku is open.'
                : 'Dapatkan pemberitahuan apabila anda terlupa masuk atau keluar. Hanya berfungsi semasa Amanahku dibuka.'">Get a notification when you forget to clock in or out.</div>
    </div>
    <button type="button" @click="$store.alerts.enable()"
            class="uj-btn-ghost" style="height:34px;font-size:12px;padding:0 12px;flex-shrink:0;"
            x-text="$store.ui.lang==='en' ? 'Turn on' : 'Hidupkan'">Turn on</button>
    <button type="button" @click="dismiss()"
            :aria-label="$store.ui.lang==='en' ? 'Dismiss' : 'Tutup'"
            style="background:none;padding:2px;flex-shrink:0;line-height:0;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"></path></svg>
    </button>
</div>
