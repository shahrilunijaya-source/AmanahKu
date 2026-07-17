@extends('layouts.app')

@php
    // Triage lifecycle chips — colour + bilingual label per status.
    $statusMeta = [
        'open'      => ['en' => 'Open', 'ms' => 'Terbuka', 'bg' => 'var(--red-tint)', 'fg' => 'var(--red)'],
        'reviewing' => ['en' => 'Reviewing', 'ms' => 'Menyemak', 'bg' => '#eef4fc', 'fg' => 'var(--info)'],
        'resolved'  => ['en' => 'Resolved', 'ms' => 'Selesai', 'bg' => '#e7f4ee', 'fg' => 'var(--success)'],
        'declined'  => ['en' => 'Declined', 'ms' => 'Ditolak', 'bg' => 'var(--hairline-soft)', 'fg' => 'var(--muted)'],
    ];
    // bug vs idea — icon + accent, mirrors the feedback modal's segmented control.
    $typeMeta = [
        'bug'  => ['en' => 'Bug', 'ms' => 'Pepijat', 'fg' => 'var(--red)', 'bg' => 'var(--red-tint)',
                   'icon' => 'M8 2l1.5 2M16 2l-1.5 2M9 7h6a3 3 0 0 1 3 3v3a6 6 0 0 1-12 0v-3a3 3 0 0 1 3-3zM3 13h3M18 13h3M4 8l2 1M20 8l-2 1M4 18l2-1M20 18l-2-1'],
        'idea' => ['en' => 'Idea', 'ms' => 'Idea', 'fg' => 'var(--amber)', 'bg' => '#fbf3e6',
                   'icon' => 'M9 18h6M10 22h4M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.3 1 2.1h6c0-.8.4-1.6 1-2.1A7 7 0 0 0 12 2z'],
    ];

    // Chip link helper — preserves the OTHER active filter while switching this one.
    $chip = fn (array $over) => route('app.screen', array_filter(array_merge(
        ['screen' => 'feedback', 'type' => $activeType, 'status' => $activeStatus], $over,
    ), fn ($v) => $v !== null && $v !== ''));
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'feedback',
    'en'  => [
        'title' => 'Feedback inbox',
        'body'  => 'Every bug report and idea staff send through the "Send feedback" button in the sidebar lands here. Each item carries the page the person was on so you can reproduce it fast.',
        'who'   => 'Everyone submits · Managers view · Management & HR triage',
        'steps' => [
            'Filter by Bug / Idea and by status to focus the queue — Open items need attention first.',
            'Open "View page" to jump to exactly where the reporter was.',
            'Move each item along: Open → Reviewing → Resolved (or Declined). The change is logged in the audit trail.',
        ],
    ],
    'ms'  => [
        'title' => 'Peti maklum balas',
        'body'  => 'Setiap laporan pepijat dan idea yang dihantar staf melalui butang "Maklum balas" di bar sisi tiba di sini. Setiap item membawa halaman tempat orang itu berada supaya anda boleh ulang semula dengan cepat.',
        'who'   => 'Semua orang hantar · Pengurus lihat · Pengurusan & HR menyaring',
        'steps' => [
            'Tapis mengikut Pepijat / Idea dan status untuk fokus — item Terbuka perlu perhatian dahulu.',
            'Buka "Lihat halaman" untuk terus ke tempat pelapor berada.',
            'Gerakkan setiap item: Terbuka → Menyemak → Selesai (atau Ditolak). Perubahan direkod dalam jejak audit.',
        ],
    ],
])

@if (session('ok'))
    <div style="background:#e7f4ee;border:1px solid var(--success);color:var(--success);font-size:12.5px;border-radius:8px;padding:10px 14px;margin-bottom:16px;">{{ session('ok') }}</div>
@endif

{{-- ── Filter bar: type + status, each chip a link that preserves the other filter ── --}}
<div class="uj-card" style="padding:14px 18px;margin-bottom:16px;display:flex;flex-direction:column;gap:12px;">
    {{-- Type row --}}
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <span style="font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--muted-soft);width:52px;flex-shrink:0;" x-text="$store.ui.lang==='en' ? 'Type' : 'Jenis'">Type</span>
        <a href="{{ $chip(['type' => null]) }}" class="uj-pill" style="text-decoration:none;{{ $activeType === null ? 'background:var(--ink);color:#fff;' : 'background:var(--hairline-soft);color:var(--muted);' }}"><span x-text="$store.ui.lang==='en' ? 'All' : 'Semua'">All</span> · {{ $total }}</a>
        @foreach ($types as $t)
            <a href="{{ $chip(['type' => $t]) }}" class="uj-pill" style="text-decoration:none;{{ $activeType === $t ? 'background:'.$typeMeta[$t]['fg'].';color:#fff;' : 'background:'.$typeMeta[$t]['bg'].';color:'.$typeMeta[$t]['fg'].';' }}"><span x-text="$store.ui.lang==='en' ? @js($typeMeta[$t]['en'].'s') : @js($typeMeta[$t]['ms'])">{{ $typeMeta[$t]['en'] }}s</span> · {{ $t === 'bug' ? $bugCount : $ideaCount }}</a>
        @endforeach
    </div>
    {{-- Status row --}}
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <span style="font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--muted-soft);width:52px;flex-shrink:0;" x-text="$store.ui.lang==='en' ? 'Status' : 'Status'">Status</span>
        <a href="{{ $chip(['status' => null]) }}" class="uj-pill" style="text-decoration:none;{{ $activeStatus === null ? 'background:var(--ink);color:#fff;' : 'background:var(--hairline-soft);color:var(--muted);' }}"><span x-text="$store.ui.lang==='en' ? 'All' : 'Semua'">All</span></a>
        @foreach ($statuses as $st)
            @php $sm = $statusMeta[$st]; @endphp
            <a href="{{ $chip(['status' => $st]) }}" class="uj-pill" style="text-decoration:none;{{ $activeStatus === $st ? 'background:'.$sm['fg'].';color:#fff;' : 'background:'.$sm['bg'].';color:'.$sm['fg'].';' }}"><span x-text="$store.ui.lang==='en' ? @js($sm['en']) : @js($sm['ms'])">{{ $sm['en'] }}</span> · {{ (int) ($statusCounts[$st] ?? 0) }}</a>
        @endforeach
    </div>
</div>

{{-- ── Inbox list ── --}}
<div class="uj-card" style="padding:0;">
    <div class="uj-card-head">
        <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Submissions' : 'Penghantaran'">Submissions</h3>
        <span style="font-size:12.5px;color:var(--muted);">{{ $items->count() }} <span x-text="$store.ui.lang==='en' ? 'shown' : 'dipapar'">shown</span></span>
    </div>

    @forelse ($items as $item)
        @php
            $tm = $typeMeta[$item->type] ?? $typeMeta['bug'];
            $sm = $statusMeta[$item->status] ?? $statusMeta['open'];
            $reporter = $item->employee?->name ?? $item->user?->name ?? 'Unknown';
            // Only linkify http(s) page URLs — never a tampered javascript:/data: scheme.
            $safeUrl = $item->page_url && preg_match('~^https?://~i', $item->page_url) ? $item->page_url : null;
        @endphp
        <div style="display:flex;gap:14px;padding:18px 20px;border-bottom:1px solid var(--hairline-soft);">
            {{-- Type icon --}}
            <div style="width:36px;height:36px;border-radius:9px;background:{{ $tm['bg'] }};display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="{{ $tm['fg'] }}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $tm['icon'] }}"></path></svg>
            </div>

            {{-- Body --}}
            <div style="flex:1;min-width:0;">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
                    <span style="font-size:14px;font-weight:600;color:var(--ink);">{{ $item->title }}</span>
                    <span class="uj-pill" style="background:{{ $tm['bg'] }};color:{{ $tm['fg'] }};" x-text="$store.ui.lang==='en' ? @js($tm['en']) : @js($tm['ms'])">{{ $tm['en'] }}</span>
                    <span class="uj-pill" style="background:{{ $sm['bg'] }};color:{{ $sm['fg'] }};" x-text="$store.ui.lang==='en' ? @js($sm['en']) : @js($sm['ms'])">{{ $sm['en'] }}</span>
                </div>
                @if ($item->description)
                    <p style="font-size:13px;color:var(--muted);margin:0 0 8px;white-space:pre-wrap;">{{ $item->description }}</p>
                @endif

                {{-- Attachments: image thumbnails open inline; documents download. Both stream
                     through the auth-gated feedback.attachment route, never a public URL. --}}
                @if ($item->attachments->isNotEmpty())
                    <div style="display:flex;flex-wrap:wrap;gap:8px;margin:0 0 10px;">
                        @foreach ($item->attachments as $att)
                            @if ($att->isImage())
                                <a href="{{ route('feedback.attachment', $att) }}" target="_blank" rel="noopener noreferrer"
                                   title="{{ $att->name }}"
                                   style="display:block;width:64px;height:64px;border-radius:9px;overflow:hidden;border:1px solid var(--hairline);">
                                    <img src="{{ route('feedback.attachment', $att) }}" alt="{{ $att->name }}" loading="lazy"
                                         style="width:100%;height:100%;object-fit:cover;">
                                </a>
                            @else
                                <a href="{{ route('feedback.attachment', $att) }}" target="_blank" rel="noopener noreferrer"
                                   style="display:inline-flex;align-items:center;gap:7px;max-width:220px;height:34px;padding:0 11px;border:1px solid var(--hairline);border-radius:9px;background:#fff;color:var(--body);text-decoration:none;font-size:12px;">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6"></path></svg>
                                    <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $att->name }}</span>
                                </a>
                            @endif
                        @endforeach
                    </div>
                @endif

                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;font-size:11.5px;color:var(--muted-soft);">
                    <span><span x-text="$store.ui.lang==='en' ? 'by' : 'oleh'">by</span> {{ $reporter }}</span>
                    <span style="font-family:var(--font-mono);">{{ $item->created_at->format('j M Y, H:i') }}</span>
                    @if ($safeUrl)
                        <a href="{{ $safeUrl }}" target="_blank" rel="noopener noreferrer" style="display:inline-flex;align-items:center;gap:4px;color:var(--info);text-decoration:none;">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3"></path></svg>
                            <span x-text="$store.ui.lang==='en' ? 'View page' : 'Lihat halaman'">View page</span>
                        </a>
                    @endif
                </div>

                {{-- Triage control — management/HR only; managers view read-only. --}}
                @if ($canTriage)
                    <form method="post" action="{{ route('feedback.status', $item) }}" style="display:flex;gap:8px;align-items:center;margin-top:11px;">
                        @csrf
                        <select name="status" style="height:32px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;background:#fff;color:var(--ink);">
                            @foreach ($statuses as $st)
                                <option value="{{ $st }}" @selected($item->status === $st)>{{ $statusMeta[$st]['en'] ?? ucfirst($st) }}</option>
                            @endforeach
                        </select>
                        <button type="submit" style="height:32px;padding:0 14px;border:1px solid var(--hairline);border-radius:7px;background:#fff;color:var(--ink);font-size:12.5px;font-weight:500;cursor:pointer;"><span x-text="$store.ui.lang==='en' ? 'Update' : 'Kemas kini'">Update</span></button>
                    </form>
                @endif
            </div>
        </div>
    @empty
        <div style="padding:34px 20px;text-align:center;">
            <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'Nothing here' : 'Tiada apa di sini'"></span></div>
            <div style="font-size:12px;color:var(--muted);line-height:1.5;max-width:380px;margin:0 auto;"><span x-text="$store.ui.lang==='en' ? 'No feedback matches this filter. Clear the filter, or wait — new bug reports and ideas from staff will appear here automatically.' : 'Tiada maklum balas sepadan dengan tapisan ini. Kosongkan tapisan, atau tunggu — laporan pepijat dan idea baharu daripada staf akan muncul di sini secara automatik.'"></span></div>
        </div>
    @endforelse
</div>
@endsection
