{{-- Past rows of one progression $type for $selected. --}}
@php $rows = $selected->progressions->where('type', $type); @endphp
<div style="display:flex;flex-direction:column;gap:12px;">
    <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;">{!! $L('History', 'Sejarah') !!}</div>
    @forelse ($rows as $row)
        @include('partials.profile.timeline-row', ['row' => $row, 'open' => $loop->first, 'editable' => true])
    @empty
        <p style="font-size:12.5px;color:var(--muted);margin:0;">{!! $L('No Record Found', 'Tiada Rekod') !!}</p>
    @endforelse
</div>
