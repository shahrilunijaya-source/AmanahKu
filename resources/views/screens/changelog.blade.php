@extends('layouts.app')

@php
    $tagTone = ['added' => 'success', 'fixed' => 'error'];
    $tagLabel = [
        'added' => ['en' => 'Added', 'ms' => 'Ditambah'],
        'improved' => ['en' => 'Improved', 'ms' => 'Ditambah baik'],
        'fixed' => ['en' => 'Fixed', 'ms' => 'Dibaiki'],
    ];
@endphp

@php
    $latest = $releases[0] ?? null;
    $older = $latest ? array_slice($releases, 1) : [];
@endphp

@section('screen')
<div class="uj-card" x-data="{ openVersion: null }">
    <div class="uj-card-head">
        <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Changelog' : 'Log Perubahan'">Changelog</h3>
    </div>
    @if ($latest)
        <div style="padding:16px 20px;border-bottom:1px solid var(--hairline-soft);">
            <div style="display:flex;align-items:baseline;gap:10px;margin-bottom:10px;">
                <span style="font-family:var(--font-mono);font-size:13px;font-weight:600;color:var(--ink);">{{ $latest['version'] }}</span>
                <span style="font-size:12px;color:var(--muted);">{{ \Illuminate\Support\Carbon::parse($latest['date'])->format('j M Y') }}</span>
            </div>
            <div style="display:flex;flex-direction:column;gap:8px;">
                @foreach ($latest['entries'] as $entry)
                    @php $tone = $tagTone[$entry['tag']] ?? null; @endphp
                    <div style="display:flex;align-items:baseline;gap:10px;">
                        <span class="uj-stamp"@if ($tone) data-tone="{{ $tone }}" @endif style="flex-shrink:0;"
                              x-text="$store.ui.lang==='en' ? @js($tagLabel[$entry['tag']]['en']) : @js($tagLabel[$entry['tag']]['ms'])">{{ $tagLabel[$entry['tag']]['en'] }}</span>
                        <span style="font-size:13.5px;color:var(--ink);line-height:1.5;">
                            <span style="white-space:pre-line;" x-text="$store.ui.lang==='en' ? @js($entry['text']) : @js($entry['text_ms'])">{{ $entry['text'] }}</span>
                            @if ($entry['link'])
                                {{-- Optional per-entry link: a release note that names a screen can open it. --}}
                                <a href="{{ $entry['link'] }}" style="display:inline-block;margin-left:6px;font-size:12.5px;font-weight:600;color:var(--red);text-decoration:none;white-space:nowrap;"
                                   x-text="$store.ui.lang==='en' ? @js($entry['link_text'].' →') : @js($entry['link_text_ms'].' →')">{{ $entry['link_text'] }} &rarr;</a>
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if (count($older))
        <div style="padding:14px 20px;border-bottom:1px solid var(--hairline-soft);display:flex;align-items:center;gap:10px;">
            <label style="font-size:12px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Older versions' : 'Versi lama'">Older versions</label>
            <select style="height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;color:var(--body);background:#fff;"
                    :value="openVersion ?? ''"
                    @change="const v = $event.target.value;
                             if (! v) { openVersion = null; return; }
                             if (confirm($store.ui.lang==='en' ? ('View changelog notes for ' + v + '?') : ('Lihat log perubahan untuk ' + v + '?'))) { openVersion = v; } else { $event.target.value = openVersion ?? ''; }">
                <option value="" x-text="$store.ui.lang==='en' ? 'Select a version…' : 'Pilih versi…'">Select a version…</option>
                @foreach ($older as $release)
                    <option value="{{ $release['version'] }}">{{ $release['version'] }} — {{ \Illuminate\Support\Carbon::parse($release['date'])->format('j M Y') }}</option>
                @endforeach
            </select>
        </div>

        @foreach ($older as $release)
            <div x-show="openVersion === @js($release['version'])" style="padding:16px 20px;border-bottom:1px solid var(--hairline-soft);">
                <div style="display:flex;align-items:baseline;gap:10px;margin-bottom:10px;">
                    <span style="font-family:var(--font-mono);font-size:13px;font-weight:600;color:var(--ink);">{{ $release['version'] }}</span>
                    <span style="font-size:12px;color:var(--muted);">{{ \Illuminate\Support\Carbon::parse($release['date'])->format('j M Y') }}</span>
                </div>
                <div style="display:flex;flex-direction:column;gap:8px;">
                    @foreach ($release['entries'] as $entry)
                        @php $tone = $tagTone[$entry['tag']] ?? null; @endphp
                        <div style="display:flex;align-items:baseline;gap:10px;">
                            <span class="uj-stamp"@if ($tone) data-tone="{{ $tone }}" @endif style="flex-shrink:0;"
                                  x-text="$store.ui.lang==='en' ? @js($tagLabel[$entry['tag']]['en']) : @js($tagLabel[$entry['tag']]['ms'])">{{ $tagLabel[$entry['tag']]['en'] }}</span>
                            <span style="font-size:13.5px;color:var(--ink);line-height:1.5;">
                                <span style="white-space:pre-line;" x-text="$store.ui.lang==='en' ? @js($entry['text']) : @js($entry['text_ms'])">{{ $entry['text'] }}</span>
                                @if ($entry['link'])
                                    {{-- Optional per-entry link: a release note that names a screen can open it. --}}
                                    <a href="{{ $entry['link'] }}" style="display:inline-block;margin-left:6px;font-size:12.5px;font-weight:600;color:var(--red);text-decoration:none;white-space:nowrap;"
                                       x-text="$store.ui.lang==='en' ? @js($entry['link_text'].' →') : @js($entry['link_text_ms'].' →')">{{ $entry['link_text'] }} &rarr;</a>
                                @endif
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    @endif

    @if (! $latest)
        <div style="padding:28px 20px;text-align:center;">
            <div style="font-size:13px;color:var(--ink);font-weight:500;"
                 x-text="$store.ui.lang==='en' ? 'No releases yet' : 'Belum ada keluaran'">No releases yet</div>
        </div>
    @endif
</div>
@endsection
