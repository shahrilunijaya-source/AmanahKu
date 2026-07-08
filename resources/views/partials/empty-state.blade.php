{{-- Generic "module ready for build-out" card. Optional $variantNote label. --}}
<div class="uj-card" style="padding:64px 24px;text-align:center;">
    <div style="width:54px;height:54px;border-radius:13px;background:var(--canvas);border:1px solid var(--hairline);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--muted-soft)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"></rect><path d="M3 9h18M9 21V9"></path></svg>
    </div>
    <h3 style="font-size:17px;font-weight:600;color:var(--ink);margin:0 0 6px;"><span>{{ $variantNote ?? 'Module' }}</span> <span x-text="$store.ui.lang==='en' ? 'ready for build-out' : 'sedia untuk dibina'">ready for build-out</span></h3>
    <p style="font-size:13.5px;color:var(--muted);max-width:380px;margin:0 auto 18px;line-height:1.55;" x-text="$store.ui.lang==='en' ? 'This screen is part of the Amanahku platform and uses the same shell, components and ACL. It\'s scaffolded and ready for its detailed build.' : 'Skrin ini sebahagian daripada platform Amanahku dan menggunakan cangkerang, komponen dan ACL yang sama. Ia telah dirangka dan sedia untuk pembinaan terperinci.'">This screen is part of the Amanahku platform and uses the same shell, components and ACL. It's scaffolded and ready for its detailed build.</p>
    <a href="{{ route('app.screen', 'dash') }}" class="uj-btn-primary" style="display:inline-block;height:40px;line-height:40px;padding:0 18px;font-size:13px;text-decoration:none;" x-text="$store.ui.lang==='en' ? 'Back to Dashboard' : 'Kembali ke Papan Pemuka'">Back to Dashboard</a>
</div>
