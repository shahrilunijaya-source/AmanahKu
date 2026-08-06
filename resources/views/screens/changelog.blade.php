@extends('layouts.app')

@php
    $tagTone = ['added' => 'success', 'fixed' => 'error'];
    $tagLabel = [
        'added' => ['en' => 'Added', 'ms' => 'Ditambah'],
        'improved' => ['en' => 'Improved', 'ms' => 'Ditambah baik'],
        'fixed' => ['en' => 'Fixed', 'ms' => 'Dibaiki'],
    ];
@endphp

@section('screen')
<div class="uj-card">
    <div class="uj-card-head">
        <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Changelog' : 'Log Perubahan'">Changelog</h3>
    </div>
    @forelse ($releases as $release)
        <div style="padding:16px 20px;border-bottom:1px solid var(--hairline-soft);">
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
                        <span style="font-size:13.5px;color:var(--ink);line-height:1.5;"
                              x-text="$store.ui.lang==='en' ? @js($entry['text']) : @js($entry['text_ms'])">{{ $entry['text'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div style="padding:28px 20px;text-align:center;">
            <div style="font-size:13px;color:var(--ink);font-weight:500;"
                 x-text="$store.ui.lang==='en' ? 'No releases yet' : 'Belum ada keluaran'">No releases yet</div>
        </div>
    @endforelse
</div>
@endsection
