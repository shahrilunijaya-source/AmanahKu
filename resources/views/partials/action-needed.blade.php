@php
    /**
     * "Action needed" card — requests routed to the CURRENT user awaiting their
     * VERIFY (as someone's org-chart manager) or APPROVE (management) step, across
     * leave, claims and overtime. Shown above the persona branches so it reflects the
     * real user's obligations regardless of the previewed persona.
     *
     * Expects: $actionNeeded (Collection of rows), $actionNeededTotal (int).
     */
    $actionNeeded = $actionNeeded ?? collect();
    $total = $actionNeededTotal ?? 0;
@endphp
@if ($total > 0)
    <div class="uj-card" style="padding:18px 22px;margin-bottom:16px;border-left:3px solid var(--red);">
        <div style="display:flex;align-items:center;gap:14px;margin-bottom:12px;">
            <div style="width:40px;height:40px;border-radius:11px;background:var(--red-tint);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
            </div>
            <div style="flex:1;min-width:0;">
                <div style="font-size:14.5px;font-weight:600;color:var(--ink);"><span x-text="$store.ui.lang==='en' ? 'Action needed' : 'Tindakan diperlukan'">Action needed</span> ({{ $total }})</div>
                <div style="font-size:12.5px;color:var(--muted);margin-top:2px;"><span x-text="$store.ui.lang==='en' ? 'Requests waiting for you to verify or approve.' : 'Permohonan menunggu anda sahkan atau luluskan.'"></span></div>
            </div>
        </div>
        <div style="display:flex;flex-direction:column;">
            @foreach ($actionNeeded as $it)
                @php $isVerify = $it['stage'] === 'verify'; @endphp
                <a href="{{ $it['url'] }}" style="display:flex;align-items:center;gap:11px;padding:8px 0;border-top:1px solid var(--hairline-soft);text-decoration:none;">
                    <span style="font-size:10px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#fff;background:{{ $isVerify ? 'var(--amber)' : 'var(--red)' }};padding:2px 8px;border-radius:9999px;flex-shrink:0;">
                        <span x-text="$store.ui.lang==='en' ? '{{ $isVerify ? 'Verify' : 'Approve' }}' : '{{ $isVerify ? 'Sahkan' : 'Luluskan' }}'">{{ $isVerify ? 'Verify' : 'Approve' }}</span>
                    </span>
                    <div style="width:26px;height:26px;border-radius:50%;background:{{ $it['color'] }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:600;flex-shrink:0;">{{ $it['initials'] }}</div>
                    <span style="flex:1;min-width:0;font-size:13px;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $it['label'] }} — {{ $it['who'] }}</span>
                    <span aria-hidden="true" style="font-size:16px;color:var(--muted-soft);flex-shrink:0;">›</span>
                </a>
            @endforeach
        </div>
        @if ($total > $actionNeeded->count())
            <div style="font-size:12px;color:var(--muted);margin-top:8px;">+{{ $total - $actionNeeded->count() }} <span x-text="$store.ui.lang==='en' ? 'more' : 'lagi'">more</span></div>
        @endif
    </div>
@endif
