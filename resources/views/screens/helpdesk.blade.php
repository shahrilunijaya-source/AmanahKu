@extends('layouts.app')

@php
    $statusMeta = [
        'open' => ['label' => 'Open'],
        'in_progress' => ['label' => 'In Progress'],
        'resolved' => ['label' => 'Resolved'],
        'closed' => ['label' => 'Closed'],
    ];
    // Stamp tone per status — null falls through to the stamp's neutral default,
    // fitting for "not yet worked" (open) and "archived" (closed) alike.
    $statusTone = [
        'open' => null,
        'in_progress' => 'amber',
        'resolved' => 'success',
        'closed' => null,
    ];
    $priorityColor = [
        'low' => 'var(--muted)',
        'medium' => 'var(--info)',
        'high' => 'var(--amber-ink)',
        'urgent' => 'var(--error)',
    ];
    $statusMs = [
        'open' => 'Buka',
        'in_progress' => 'Sedang Diproses',
        'resolved' => 'Selesai',
        'closed' => 'Ditutup',
    ];
    // Row icon tile per category — same tinted-tile convention as the Leave and
    // Claims disclosure rows (partials/leave-type-icon.blade.php, claims $typeMeta).
    $categoryMeta = [
        'IT' => ['tint' => 'var(--info)', 'bg' => 'var(--info-tint,#eef4fb)', 'icon' => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>'],
        'Facilities' => ['tint' => 'var(--amber)', 'bg' => 'var(--amber-tint,#f7efe0)', 'icon' => '<path d="M4 21V8l8-5 8 5v13"/><path d="M9 21v-6h6v6"/>'],
        'HR' => ['tint' => 'var(--success)', 'bg' => 'var(--success-tint,#e7f4ec)', 'icon' => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/>'],
        'Other' => ['tint' => 'var(--muted)', 'bg' => 'var(--shelf)', 'icon' => '<path d="M5 12h.01M12 12h.01M19 12h.01"/>'],
        'Bug' => ['tint' => 'var(--red)', 'bg' => 'var(--red-tint)', 'icon' => '<path d="M12 9v4M12 17h.01"/><path d="M10.3 4.1 2.5 18a2 2 0 0 0 1.7 3h15.6a2 2 0 0 0 1.7-3L13.7 4.1a2 2 0 0 0-3.4 0Z"/>'],
        'Idea' => ['tint' => 'var(--error)', 'bg' => 'color-mix(in srgb, var(--error) 10%, #fff)', 'icon' => '<path d="M9 18h6M10 21h4"/><path d="M12 3a6 6 0 0 0-3.5 10.9c.6.5 1 1.2 1 2.1h5c0-.9.4-1.6 1-2.1A6 6 0 0 0 12 3Z"/>'],
    ];
    // Board vs My tickets — kept apart so a privileged viewer's own tickets don't
    // dilute the working board. ?tab=mine deep-links straight to the second tab.
    $initialTab = request()->query('tab') === 'mine' ? 'mine' : 'board';
@endphp

@section('screen')

@include('partials.guide', [
    'key' => 'helpdesk',
    'en'  => [
        'title' => 'Help desk',
        'body'  => 'Raise and track support tickets for IT, facilities or HR problems — a broken laptop, an office issue, a payslip query. Each ticket is assigned and worked until it is resolved.',
        'who'   => 'Staff raise tickets · Support team resolve',
        'steps' => [
            'Click "+ New ticket" and pick the category and how urgent it is.',
            'Give a clear subject and describe the problem — include any error messages.',
            'Submit. Track its status (Open → In Progress → Resolved) and read the resolution note when it is done.',
        ],
    ],
    'ms'  => [
        'title' => 'Helpdesk',
        'body'  => 'Buka dan jejak ticket sokongan untuk masalah IT, kemudahan atau HR — laptop rosak, isu pejabat, pertanyaan slip gaji. Setiap ticket diberikan kepada seseorang dan diuruskan sehingga selesai.',
        'who'   => 'Staf buka ticket · Pasukan sokongan selesaikan',
        'steps' => [
            'Klik "+ New ticket" dan pilih kategori serta tahap kesegeraannya.',
            'Beri tajuk yang jelas dan terangkan masalah — sertakan sebarang mesej ralat.',
            'Hantar. Jejak statusnya (Open → In Progress → Resolved) dan baca nota penyelesaian apabila selesai.',
        ],
    ],
])

@if (! $privileged)
{{-- ───────────────────────── Employee view: raise + own tickets ───────────────────────── --}}
<div>
    <div class="uj-card" style="padding:20px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;">
        <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Raise a support ticket' : 'Buka ticket sokongan'">Raise a support ticket</h3>
        <button type="button" @click="$dispatch('ticket-raise-open')" class="uj-btn-primary" style="height:34px;padding:0 13px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? '+ New ticket' : '+ Ticket baharu'">+ New ticket</button>
    </div>

    @include('partials.my-tickets')
</div>

@else
{{-- ───────────────────────── Privileged view: board + manage ───────────────────────── --}}
<div class="uj-lv" x-data="{
        tab: @js($initialTab),
        go(t) {
            this.tab = t;
            const u = new URL(window.location);
            t === 'board' ? u.searchParams.delete('tab') : u.searchParams.set('tab', t);
            history.replaceState(null, '', u);
        },
    }">
    <div style="display:flex;justify-content:flex-end;">
        <button type="button" @click="$dispatch('ticket-raise-open')" class="uj-btn-primary" style="height:34px;padding:0 13px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? '+ New ticket' : '+ Ticket baharu'">+ New ticket</button>
    </div>

    <div class="uj-lv-tabs" role="tablist">
        <button type="button" class="uj-lv-tab" role="tab" :data-on="tab === 'board' ? '' : null"
                :aria-selected="tab === 'board'" @click="go('board')"
                x-text="$store.ui.lang==='en' ? 'Tickets' : 'Ticket'">Tickets</button>
        <button type="button" class="uj-lv-tab" role="tab" :data-on="tab === 'mine' ? '' : null"
                :aria-selected="tab === 'mine'" @click="go('mine')"
                x-text="$store.ui.lang==='en' ? 'My tickets' : 'Ticket saya'">My tickets</button>
    </div>

    <div role="tabpanel" x-show="tab === 'board'" x-cloak>
        @foreach ($statuses as $s)
            @php $bucket = $grouped->get($s, collect()); @endphp
            <div class="uj-card" style="margin-bottom:16px;">
                <div class="uj-card-head">
                    <h3 class="uj-card-title" style="display:flex;align-items:center;gap:8px;">
                        <span class="uj-stamp" @if ($statusTone[$s]) data-tone="{{ $statusTone[$s] }}" @endif
                              x-text="$store.ui.lang==='en' ? @js($statusMeta[$s]['label']) : @js($statusMs[$s] ?? $statusMeta[$s]['label'])">{{ $statusMeta[$s]['label'] }}</span>
                        <span style="color:var(--muted);font-weight:500;font-size:13px;">· {{ $bucket->count() }}</span>
                    </h3>
                </div>
                @forelse ($bucket as $t)
                    @include('partials.ticket-row', ['t' => $t, 'privileged' => true])
                @empty
                    <div class="uj-lv-empty">
                        <b x-text="$store.ui.lang==='en' ? 'No {{ $statusMeta[$s]['label'] }} tickets' : 'Tiada ticket {{ $statusMeta[$s]['label'] }}'"></b>
                        <span x-text="$store.ui.lang==='en' ? 'Tickets marked \'{{ $statusMeta[$s]['label'] }}\' will appear here. Open a ticket to change its status or assign it.' : 'Ticket bertanda \'{{ $statusMeta[$s]['label'] }}\' akan muncul di sini. Buka ticket untuk tukar status atau berikannya kepada seseorang.'"></span>
                    </div>
                @endforelse
            </div>
        @endforeach
    </div>

    <div role="tabpanel" x-show="tab === 'mine'" x-cloak>
        @include('partials.my-tickets')
    </div>
</div>
@endif

@endsection
