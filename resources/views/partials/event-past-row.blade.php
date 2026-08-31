{{-- One row in the Events screen's "Past events" card, shared by the recent (inline)
     and older (collapsed) buckets. Params: $row (['event' => CompanyEvent, 'counts' =>
     [...], 'myRsvp' => ?string]), $typeLabel, $typeLabelMs. --}}
@php $e = $row['event']; $counts = $row['counts']; @endphp
<div style="padding:14px 20px;border-bottom:1px solid var(--hairline-soft);display:flex;align-items:center;justify-content:space-between;gap:10px;">
    <div style="min-width:0;">
        <div style="font-size:13px;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $e->title }}</div>
        <div style="font-size:12px;color:var(--muted);">
            <span x-text="$store.ui.lang==='en' ? @js($typeLabel[$e->type] ?? $e->type) : @js($typeLabelMs[$e->type] ?? $typeLabel[$e->type] ?? $e->type)">{{ $typeLabel[$e->type] ?? $e->type }}</span>
            · {{ $e->event_date->format('j M Y') }}
            @if ($e->host)
                · <span x-text="$store.ui.lang==='en' ? 'Hosted by' : 'Dianjurkan oleh'">Hosted by</span> {{ $e->host }}
            @endif
        </div>
    </div>
    @if ($e->isExternal())
        @if ($e->registration_url)
            <a href="{{ $e->registration_url }}" target="_blank" rel="noopener" style="font-size:12px;color:var(--info);white-space:nowrap;text-decoration:none;" x-text="$store.ui.lang==='en' ? 'Register ↗' : 'Daftar ↗'">Register ↗</a>
        @endif
    @else
        <span style="font-size:12px;color:var(--muted);white-space:nowrap;"><strong style="color:var(--ink);font-family:var(--font-mono);">{{ $counts['going'] }}</strong> <span x-text="$store.ui.lang==='en' ? 'attended' : 'hadir'">attended</span></span>
    @endif
</div>
