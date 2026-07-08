{{-- Dashboard nudge: shown only when the signed-in employee still owes a lesson
     this month (and the module is enabled). "Share now" opens the panel in Add view. --}}
@if (($kbEnabled ?? false) && ($kbOwes ?? false))
    <div style="display:flex;align-items:center;gap:14px;background:var(--red-tint);border:1px solid #f1cdcf;border-radius:12px;padding:14px 20px;margin-bottom:16px;">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M9 21h6M12 3a6 6 0 0 0-6 6c0 2.22 1.21 4.16 3 5.2V17a1 1 0 0 0 1 1h4a1 1 0 0 0 1-1v-2.8c1.79-1.04 3-2.98 3-5.2a6 6 0 0 0-6-6z"></path></svg>
        <div style="flex:1;min-width:0;">
            <div style="font-size:13.5px;font-weight:600;color:var(--ink);" x-text="$store.ui.lang==='en' ? @js('Share your '.$kbMonthEn.' lesson — '.$kbDaysLeft.' days left') : @js('Kongsi pengajaran '.$kbMonthMs.' anda — '.$kbDaysLeft.' hari lagi')">Share your lesson</div>
            <div style="font-size:12.5px;color:var(--body);margin-top:1px;" x-text="$store.ui.lang==='en' ? 'Every staff member shares one lesson learned each month. It takes a minute.' : 'Setiap staf kongsi satu pengajaran setiap bulan. Ambil seminit sahaja.'">Every staff member shares one lesson learned each month.</div>
        </div>
        <button @click="kb = true; kbView = 'add'" style="flex-shrink:0;height:34px;padding:0 16px;background:var(--red);color:#fff;border-radius:8px;font-size:13px;font-weight:500;cursor:pointer;"><span x-text="$store.ui.lang==='en' ? 'Share now' : 'Kongsi sekarang'">Share now</span></button>
    </div>
@endif
