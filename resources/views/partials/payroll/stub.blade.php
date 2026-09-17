{{-- "Not yet available" card for a Worksy page with no backend yet. $title, $body, $bodyMs, $pill. --}}
<div class="uj-card" style="max-width:640px;padding:26px;">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
        <h3 class="uj-card-title" style="margin:0;">{{ $title }}</h3>
        <span class="uj-pill" style="background:var(--canvas);border:1px solid var(--hairline);color:var(--muted);font-size:10.5px;">{{ $pill }}</span>
    </div>
    <div style="font-size:13px;font-weight:600;color:var(--ink);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Not yet available' : 'Belum tersedia'">Not yet available</div>
    <p style="font-size:12.5px;color:var(--muted);margin:0;" x-text="$store.ui.lang==='en' ? @js($body) : @js($bodyMs)">{{ $body }}</p>
</div>
