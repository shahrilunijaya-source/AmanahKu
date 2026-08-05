@extends('layouts.app')

@php
    $statusMeta = [
        'open' => ['label' => 'Open', 'color' => 'var(--info)'],
        'in_progress' => ['label' => 'In Progress', 'color' => 'var(--amber)'],
        'resolved' => ['label' => 'Resolved', 'color' => 'var(--success)'],
        'closed' => ['label' => 'Closed', 'color' => 'var(--muted)'],
    ];
    $priorityColor = [
        'low' => 'var(--muted)',
        'medium' => 'var(--info)',
        'high' => 'var(--amber)',
        'urgent' => 'var(--red)',
    ];
    $statusMs = [
        'open' => 'Buka',
        'in_progress' => 'Sedang Diproses',
        'resolved' => 'Selesai',
        'closed' => 'Ditutup',
    ];
    $fs = 'height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;';
    $pill = fn ($s) => '<span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:'.($statusMeta[$s]['color'] ?? 'var(--muted)').';"><span style="width:8px;height:8px;border-radius:50%;background:'.($statusMeta[$s]['color'] ?? 'var(--muted)').';"></span>'.($statusMeta[$s]['label'] ?? ucfirst($s)).'</span>';
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
<div x-data="{ open: {{ $errors->any() && old('_ticket') ? (int) old('_ticket') : 'null' }} }">
    <div style="display:flex;justify-content:flex-end;margin-bottom:12px;">
        <button type="button" @click="$dispatch('ticket-raise-open')" class="uj-btn-primary" style="height:34px;padding:0 13px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? '+ New ticket' : '+ Ticket baharu'">+ New ticket</button>
    </div>

    <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
        @foreach ($statuses as $s)
            <div class="uj-card uj-stat" style="flex:1;min-width:140px;">
                <div class="uj-stat-label" x-text="$store.ui.lang==='en' ? @js($statusMeta[$s]['label']) : @js($statusMs[$s] ?? $statusMeta[$s]['label'])">{{ $statusMeta[$s]['label'] }}</div>
                <div class="uj-stat-value" style="color:{{ $statusMeta[$s]['color'] }};">{{ $counts[$s] ?? 0 }}</div>
            </div>
        @endforeach
    </div>

    @foreach ($statuses as $s)
        @php $bucket = $grouped->get($s, collect()); @endphp
        <div class="uj-card" style="margin-bottom:16px;">
            <div class="uj-card-head">
                <h3 class="uj-card-title">{!! $pill($s) !!} <span style="color:var(--muted);font-weight:500;font-size:13px;">· {{ $bucket->count() }}</span></h3>
            </div>
            @forelse ($bucket as $t)
                <div style="padding:14px 20px;border-bottom:1px solid var(--hairline-soft);">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;">
                        <div style="min-width:0;">
                            <div style="font-size:14px;font-weight:600;color:var(--ink);">{{ $t->subject }}</div>
                            <div style="font-size:12px;color:var(--muted);margin-top:3px;">
                                @if ($t->employee?->name){{ $t->employee->name }}@else<span x-text="$store.ui.lang==='en' ? 'Unknown' : 'Tidak diketahui'">Unknown</span>@endif · {{ $t->category }} ·
                                <span style="color:{{ $priorityColor[$t->priority] ?? 'var(--muted)' }};font-weight:600;">{{ ucfirst($t->priority) }}</span> ·
                                {{ $t->created_at?->format('j M Y') }}
                                @if ($t->assignee) · <span style="color:var(--body);">→ {{ $t->assignee->name }}</span>@endif
                            </div>
                        </div>
                        @if (! in_array($t->category, ['Bug', 'Idea'], true) || $isSuperAdmin)
                            <button @click="open = (open === {{ $t->id }} ? null : {{ $t->id }})" class="uj-btn-ghost" style="height:30px;padding:0 11px;font-size:12px;flex-shrink:0;" x-text="$store.ui.lang==='en' ? 'Manage' : 'Urus'">Manage</button>
                        @endif
                    </div>
                    <div style="font-size:13px;color:var(--body);margin-top:8px;white-space:pre-line;">{{ $t->description }}</div>
                    @php $safeUrl = $t->page_url && preg_match('~^https?://~i', $t->page_url) ? $t->page_url : null; @endphp
                    @if ($safeUrl)
                        <div style="font-size:12px;color:var(--muted);margin-top:8px;">
                            <span x-text="$store.ui.lang==='en' ? 'Reported from' : 'Dilapor dari'">Reported from</span>
                            <a href="{{ $safeUrl }}" style="color:var(--red);text-decoration:none;">{{ $safeUrl }}</a>
                        </div>
                    @endif
                    {{-- Thumbnails and download chips both point at the auth-gated stream route,
                         never a public URL — the file lives on the private 'local' disk. --}}
                    @if ($t->attachments->isNotEmpty())
                        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;">
                            @foreach ($t->attachments as $att)
                                @if ($att->isImage())
                                    <a href="{{ route('helpdesk.attachment', $att) }}" target="_blank" rel="noopener noreferrer"
                                       style="display:block;width:64px;height:64px;border-radius:8px;overflow:hidden;border:1px solid var(--hairline-soft);">
                                        <img src="{{ route('helpdesk.attachment', $att) }}" alt="{{ $att->name }}" loading="lazy"
                                             style="width:100%;height:100%;object-fit:cover;">
                                    </a>
                                @else
                                    <a href="{{ route('helpdesk.attachment', $att) }}" target="_blank" rel="noopener noreferrer"
                                       style="display:inline-flex;align-items:center;gap:7px;height:34px;padding:0 12px;border-radius:8px;border:1px solid var(--hairline-soft);font-size:12.5px;color:var(--body);text-decoration:none;">
                                        <span style="font-weight:700;font-size:10.5px;color:var(--muted);">{{ strtoupper(pathinfo($att->name, PATHINFO_EXTENSION)) }}</span>
                                        <span>{{ $att->name }}</span>
                                    </a>
                                @endif
                            @endforeach
                        </div>
                    @endif
                    @if ($t->resolution)
                        <div style="background:var(--canvas);border:1px solid var(--hairline-soft);border-radius:8px;padding:10px 12px;margin-top:10px;">
                            <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Resolution' : 'Penyelesaian'">Resolution</div>
                            <div style="font-size:13px;color:var(--body);white-space:pre-line;">{{ $t->resolution }}</div>
                        </div>
                    @endif

                    @if (! in_array($t->category, ['Bug', 'Idea'], true) || $isSuperAdmin)
                        <div x-show="open === {{ $t->id }}" x-cloak style="margin-top:12px;border-top:1px solid var(--hairline-soft);padding-top:12px;">
                            <form method="post" action="{{ route('helpdesk.update', $t) }}">
                                @csrf
                                <input type="hidden" name="_ticket" value="{{ $t->id }}" />
                                @if ($errors->any() && (int) old('_ticket') === $t->id)
                                    <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:12px;">{{ $errors->first() }}</div>
                                @endif
                                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;align-items:end;">
                                    <div>
                                        <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Status *' : 'Status *'">Status *</label>
                                        <select name="status" required style="{{ $fs }}width:100%;">
                                            @foreach ($statuses as $opt)
                                                <option value="{{ $opt }}" @selected(old('status', $t->status) === $opt) x-text="$store.ui.lang==='en' ? @js($statusMeta[$opt]['label']) : @js($statusMs[$opt] ?? $statusMeta[$opt]['label'])">{{ $statusMeta[$opt]['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Assignee' : 'Ditugaskan kepada'">Assignee</label>
                                        <select name="assignee_employee_id" style="{{ $fs }}width:100%;">
                                            <option value="" x-text="$store.ui.lang==='en' ? 'Unassigned' : 'Belum ditugaskan'">Unassigned</option>
                                            @foreach ($employees as $e)
                                                <option value="{{ $e->id }}" @selected((string) old('assignee_employee_id', (string) $t->assignee_employee_id) === (string) $e->id)>{{ $e->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div style="margin-top:12px;">
                                    <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Resolution note' : 'Nota penyelesaian'">Resolution note</label>
                                    <textarea name="resolution" maxlength="2000" rows="3" placeholder="What was done to resolve this." :placeholder="$store.ui.lang==='en' ? 'What was done to resolve this.' : 'Apa yang dibuat untuk menyelesaikannya.'" style="width:100%;padding:10px 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;resize:vertical;">{{ old('resolution', $t->resolution) }}</textarea>
                                </div>
                                <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 18px;font-size:13px;margin-top:14px;" x-text="$store.ui.lang==='en' ? 'Save changes' : 'Simpan perubahan'">Save changes</button>
                            </form>
                        </div>
                    @endif
                </div>
            @empty
                <div style="padding:28px 20px;text-align:center;">
                    <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No {{ $statusMeta[$s]['label'] }} tickets' : 'Tiada ticket {{ $statusMeta[$s]['label'] }}'"></span></div>
                    <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Tickets marked \'{{ $statusMeta[$s]['label'] }}\' will appear here. Use \'Manage\' on a ticket to change its status or assign it.' : 'Ticket bertanda \'{{ $statusMeta[$s]['label'] }}\' akan muncul di sini. Guna \'Manage\' pada ticket untuk tukar status atau berikannya kepada seseorang.'"></span></div>
                </div>
            @endforelse
        </div>
    @endforeach

    @include('partials.my-tickets')
</div>
@endif

@endsection
