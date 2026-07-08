@extends('layouts.app')

@php
    $voted = collect($votedIds);
    $statusMeta = [
        'new'       => ['label' => 'New', 'bg' => 'var(--hairline-soft)', 'fg' => 'var(--muted)'],
        'reviewing' => ['label' => 'Reviewing', 'bg' => '#eef4fc', 'fg' => 'var(--info)'],
        'accepted'  => ['label' => 'Accepted', 'bg' => '#e7f4ee', 'fg' => 'var(--success)'],
        'done'      => ['label' => 'Done', 'bg' => '#e7f4ee', 'fg' => 'var(--success)'],
        'declined'  => ['label' => 'Declined', 'bg' => 'var(--red-tint)', 'fg' => 'var(--red)'],
    ];
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'ideas',
    'en'  => [
        'title' => 'Suggestion box',
        'body'  => 'Share ideas to make the company better and upvote the ones you like. The most-upvoted ideas rise to the top so HR can see what matters most to the team.',
        'who'   => 'Anyone submits & votes · HR reviews',
        'steps' => [
            'Use "Share an idea" on the right — a clear title and a short why.',
            'Browse the list and upvote ideas you support. You get one vote per idea; click again to remove it.',
            'HR reviews submissions and updates each idea\'s status (Reviewing, Accepted, Done or Declined).',
        ],
    ],
    'ms'  => [
        'title' => 'Peti cadangan',
        'body'  => 'Kongsi idea untuk menambah baik syarikat dan undi yang anda suka. Idea paling banyak undian naik ke atas supaya HR nampak apa yang paling penting buat pasukan.',
        'who'   => 'Sesiapa hantar & undi · HR semak',
        'steps' => [
            'Guna "Share an idea" di sebelah kanan — tajuk yang jelas dan sebab ringkas.',
            'Imbas senarai dan undi idea yang anda sokong. Satu undi setiap idea; klik sekali lagi untuk tarik balik.',
            'HR semak cadangan dan kemas kini status setiap idea (Reviewing, Accepted, Done atau Declined).',
        ],
    ],
])
@if (session('ok'))
    <div style="background:#e7f4ee;border:1px solid var(--success);color:var(--success);font-size:12.5px;border-radius:8px;padding:10px 14px;margin-bottom:16px;">{{ session('ok') }}</div>
@endif

<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    {{-- Idea list (sorted by votes) --}}
    <div style="flex:2;min-width:340px;display:flex;flex-direction:column;gap:16px;">
        <div class="uj-card" style="padding:0;">
            <div class="uj-card-head"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Ideas' : 'Idea'">Ideas</h3><span style="font-size:12.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Most upvoted first · one vote each' : 'Paling banyak undi dahulu · satu undi setiap satu'">Most upvoted first · one vote each</span></div>
            @forelse ($ideas as $idea)
                @php
                    $hasVoted = $voted->contains($idea->id);
                    $sm = $statusMeta[$idea->status] ?? $statusMeta['new'];
                @endphp
                <div style="display:flex;gap:14px;padding:18px 20px;border-bottom:1px solid var(--hairline-soft);">
                    {{-- Vote control --}}
                    <div style="flex-shrink:0;">
                        @if ($canSubmit)
                            <form method="post" action="{{ route('ideas.vote', $idea) }}">
                                @csrf
                                <button type="submit" :title="$store.ui.lang==='en' ? @js($hasVoted ? 'Remove your vote' : 'Upvote this idea') : @js($hasVoted ? 'Tarik balik undi anda' : 'Undi idea ini')" style="display:flex;flex-direction:column;align-items:center;justify-content:center;width:54px;height:58px;border-radius:9px;cursor:pointer;border:1px solid {{ $hasVoted ? 'var(--red)' : 'var(--hairline)' }};background:{{ $hasVoted ? 'var(--red)' : '#fff' }};color:{{ $hasVoted ? '#fff' : 'var(--ink)' }};">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
                                    <span style="font-size:15px;font-weight:700;font-family:var(--font-mono);margin-top:2px;">{{ $idea->votes_count }}</span>
                                </button>
                            </form>
                        @else
                            <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;width:54px;height:58px;border-radius:9px;border:1px solid var(--hairline);background:var(--hairline-soft);color:var(--muted);">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
                                <span style="font-size:15px;font-weight:700;font-family:var(--font-mono);margin-top:2px;">{{ $idea->votes_count }}</span>
                            </div>
                        @endif
                    </div>

                    {{-- Idea body --}}
                    <div style="flex:1;min-width:0;">
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
                            <span style="font-size:14px;font-weight:600;color:var(--ink);">{{ $idea->title }}</span>
                            @if ($idea->category)
                                <span class="uj-pill" style="background:var(--hairline-soft);color:var(--muted);">{{ $idea->category }}</span>
                            @endif
                            <span class="uj-pill" style="background:{{ $sm['bg'] }};color:{{ $sm['fg'] }};">{{ $sm['label'] }}</span>
                        </div>
                        <p style="font-size:13px;color:var(--muted);margin:0 0 8px;white-space:pre-wrap;">{{ $idea->body }}</p>
                        <div style="font-size:11.5px;color:var(--muted-soft);"><span x-text="$store.ui.lang==='en' ? 'by' : 'oleh'">by</span> {{ $idea->employee?->name ?? 'Unknown' }}</div>

                        @if ($privileged)
                            <form method="post" action="{{ route('ideas.status', $idea) }}" style="display:flex;gap:8px;align-items:center;margin-top:10px;">
                                @csrf
                                <select name="status" style="height:32px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;background:#fff;color:var(--ink);">
                                    @foreach ($statuses as $st)
                                        <option value="{{ $st }}" @selected($idea->status === $st)>{{ $statusMeta[$st]['label'] ?? ucfirst($st) }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" style="height:32px;padding:0 14px;border:1px solid var(--hairline);border-radius:7px;background:#fff;color:var(--ink);font-size:12.5px;font-weight:500;cursor:pointer;"><span x-text="$store.ui.lang==='en' ? 'Update' : 'Kemas kini'">Update</span></button>
                            </form>
                        @endif
                    </div>
                </div>
            @empty
                <div style="padding:28px 20px;text-align:center;">
                    <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No ideas yet' : 'Belum ada idea'"></span></div>
                    <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Be the first — use \'Share an idea\' on the right. Once posted, your idea appears here for others to upvote.' : 'Jadilah yang pertama — guna \'Share an idea\' di sebelah kanan. Sebaik dihantar, idea anda muncul di sini untuk diundi orang lain.'"></span></div>
                </div>
            @endforelse
        </div>
    </div>

    {{-- Submit an idea --}}
    <div style="flex:1;min-width:300px;display:flex;flex-direction:column;gap:16px;">
        <div class="uj-card" style="padding:20px;">
            <h3 class="uj-card-title" style="margin-bottom:14px;" x-text="$store.ui.lang==='en' ? 'Share an idea' : 'Kongsi idea'">Share an idea</h3>
            @if ($canSubmit)
                <form method="post" action="{{ route('ideas.store') }}">
                    @csrf
                    @if ($errors->any())
                        <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>
                    @endif

                    <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Title' : 'Tajuk'">Title</label>
                    <input name="title" value="{{ old('title') }}" required maxlength="160" :placeholder="$store.ui.lang==='en' ? 'e.g. Hybrid Fridays' : 'cth. Jumaat hibrid'" style="width:100%;height:40px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;margin-bottom:13px;outline:none;" />

                    <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Details' : 'Butiran'">Details</label>
                    <textarea name="body" required maxlength="2000" rows="4" :placeholder="$store.ui.lang==='en' ? 'What\'s the idea, and why would it help?' : 'Apakah ideanya, dan kenapa ia membantu?'" style="width:100%;padding:9px 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;resize:vertical;outline:none;margin-bottom:6px;font-family:inherit;">{{ old('body') }}</textarea>
                    @include('partials.hint', ['en' => 'Explain the problem it solves and why it would help — clear ideas get more upvotes.', 'ms' => 'Terangkan masalah yang diselesaikan dan kenapa ia membantu — idea yang jelas dapat lebih undian.'])

                    <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Category' : 'Kategori'">Category</label>
                    <select name="category" style="width:100%;height:40px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;background:#fff;color:var(--ink);margin-bottom:16px;">
                        <option value="" x-text="$store.ui.lang==='en' ? '— None —' : '— Tiada —'">— None —</option>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat }}" @selected(old('category') === $cat)>{{ $cat }}</option>
                        @endforeach
                    </select>

                    <button type="submit" class="uj-btn-primary" style="width:100%;height:42px;font-size:13.5px;"><span x-text="$store.ui.lang==='en' ? 'Submit idea' : 'Hantar idea'">Submit idea</span></button>
                </form>
            @else
                <p style="font-size:13px;color:var(--muted);margin:0;"><span x-text="$store.ui.lang==='en' ? 'No employee profile in this workspace — submitting ideas is disabled.' : 'Tiada profil pekerja dalam workspace ini — penghantaran idea dimatikan.'">No employee profile in this workspace — submitting ideas is disabled.</span></p>
            @endif
        </div>
    </div>
</div>
@endsection
