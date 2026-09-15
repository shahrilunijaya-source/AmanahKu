@extends('layouts.app')

@section('screen')
@include('partials.guide', [
    'key' => 'progression',
    'en'  => [
        'title' => 'Progression',
        'body'  => 'Confirm a probationer, update someone\'s employment record, record a resignation, or rehire a former staff member. Every action here writes a card on the person\'s Timeline tab and an entry in the audit log. Only HR and management can open this screen.',
    ],
    'ms'  => [
        'title' => 'Kemajuan Kerjaya',
        'body'  => 'Sahkan pekerja percubaan, kemas kini rekod pekerjaan, rekod perletakan jawatan, atau ambil semula bekas pekerja. Setiap tindakan di sini menulis kad pada tab Garis Masa orang itu dan satu catatan dalam log audit. Hanya HR dan pengurusan boleh membuka skrin ini.',
    ],
])

@php
    $L = fn ($en, $ms) => '<span x-text="'.e("\$store.ui.lang==='en' ? ".json_encode($en).' : '.json_encode($ms)).'">'.e($en).'</span>';
    $fs = 'height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;width:100%;';
    $stL = ['active' => ['Active', 'Aktif'], 'probation' => ['Probation', 'Percubaan'], 'on_leave' => ['On Leave', 'Cuti'], 'resigned' => ['Resigned', 'Berhenti']];
    $stColor = ['active' => 'var(--success)', 'probation' => 'var(--amber)', 'on_leave' => 'var(--muted)', 'resigned' => 'var(--error)'];
    $actions = [
        'confirmation' => ['Confirmation', 'Pengesahan'],
        'update' => ['Update', 'Kemas kini'],
        'resignation' => ['Resignation', 'Perletakan Jawatan'],
        'rehire' => ['Rehire', 'Ambil Semula'],
    ];
    $screenUrl = route('app.screen', 'progression');
@endphp

<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    {{-- Staff picker --}}
    <div class="uj-card" x-data="{ q: '' }" style="flex:0 0 300px;max-width:100%;padding:12px;display:flex;flex-direction:column;gap:8px;max-height:calc(100vh - 200px);">
        <input x-model="q" type="search" placeholder="Search staff…" style="{{ $fs }}" />
        <div style="overflow-y:auto;display:flex;flex-direction:column;gap:2px;">
            @foreach ($staff as $s)
                <a href="{{ $screenUrl }}?emp={{ $s->id }}&action={{ $action }}"
                   x-show="!q || @js(mb_strtolower($s->name.' '.($s->staff_id ?? ''))).includes(q.toLowerCase())"
                   style="display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:8px;text-decoration:none;{{ $selected && $selected->id === $s->id ? 'background:var(--red-tint);' : '' }}">
                    <span style="width:30px;height:30px;border-radius:50%;background:var(--surface-2,#f1f1f4);display:grid;place-items:center;font-size:11px;font-weight:600;color:var(--ink);flex:none;">{{ $s->initials ?? mb_substr($s->name, 0, 2) }}</span>
                    <span style="min-width:0;flex:1;">
                        <span style="display:block;font-size:13px;color:var(--ink);font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $s->name }}</span>
                        <span style="display:block;font-size:11px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $s->positionBand?->title ?? $s->position ?? $s->department?->name ?? '—' }}</span>
                    </span>
                    <span style="width:8px;height:8px;border-radius:50%;background:{{ $stColor[$s->status] ?? 'var(--muted)' }};flex:none;" title="{{ $s->status }}"></span>
                </a>
            @endforeach
        </div>
    </div>

    {{-- Selected person --}}
    <div style="flex:1 1 520px;min-width:0;display:flex;flex-direction:column;gap:16px;">
        @if (! $selected)
            <div class="uj-card" style="padding:40px;text-align:center;font-size:13px;color:var(--muted);">{!! $L('Pick a staff member on the left to begin.', 'Pilih seorang pekerja di sebelah kiri untuk bermula.') !!}</div>
        @else
            <div class="uj-card" style="padding:18px 20px;display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
                <span style="width:52px;height:52px;border-radius:50%;background:var(--red-tint);display:grid;place-items:center;font-size:16px;font-weight:600;color:var(--red);flex:none;">{{ $selected->initials ?? mb_substr($selected->name, 0, 2) }}</span>
                <div style="min-width:0;flex:1;">
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <span style="font-size:17px;font-weight:600;color:var(--ink);">{{ $selected->name }}</span>
                        <span style="font-size:11px;font-weight:600;padding:3px 9px;border-radius:999px;color:#fff;background:{{ $stColor[$selected->status] ?? 'var(--muted)' }};">{!! $L(...($stL[$selected->status] ?? [ucfirst($selected->status), ucfirst($selected->status)])) !!}</span>
                    </div>
                    <div style="font-size:12.5px;color:var(--muted);margin-top:3px;">{{ $selected->positionBand?->title ?? $selected->position ?? '—' }}{{ $selected->department ? ' · '.$selected->department->name : '' }}{{ $selected->staff_id ? ' · '.$selected->staff_id : '' }}</div>
                    <div style="font-size:12.5px;color:var(--muted);margin-top:3px;">{!! $L('Currently Reporting To', 'Kini Melapor Kepada') !!}: <b style="color:var(--ink);">{{ $selected->reportsTo?->name ?? '—' }}</b></div>
                </div>
                <a href="{{ route('app.screen', 'profile') }}?emp={{ $selected->id }}&tab=timeline" class="uj-btn-ghost" style="height:32px;display:inline-flex;align-items:center;padding:0 14px;font-size:12.5px;text-decoration:none;">{!! $L('Open profile', 'Buka profil') !!}</a>
            </div>

            <div class="uj-card">
                <div style="display:flex;gap:4px;padding:6px;border-bottom:1px solid var(--hairline);overflow-x:auto;">
                    @foreach ($actions as $key => [$en, $ms])
                        <a href="{{ $screenUrl }}?emp={{ $selected->id }}&action={{ $key }}" @if ($key === $action) data-action="{{ $key }}" @endif
                           style="font-size:13px;padding:7px 14px;border-radius:7px;white-space:nowrap;text-decoration:none;{{ $key === $action ? 'color:#fff;background:var(--red);font-weight:600;' : 'color:var(--body);' }}">{!! $L($en, $ms) !!}</a>
                    @endforeach
                </div>
                <div style="padding:20px;display:flex;flex-direction:column;gap:20px;">
                    @include("partials.progression.$action")
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
