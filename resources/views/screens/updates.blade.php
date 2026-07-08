@extends('layouts.app')

@php
    // Single source of truth — config/changelog.php. Newest first.
    $releases = config('changelog.releases', []);
    $latestVersion = $releases[0]['version'] ?? null;
    $noteMeta = [
        'new'      => ['en' => 'New', 'ms' => 'Baharu', 'dot' => 'var(--success)'],
        'improved' => ['en' => 'Improved', 'ms' => 'Diperbaik', 'dot' => 'var(--info)'],
        'fixed'    => ['en' => 'Fixed', 'ms' => 'Dibaiki', 'dot' => 'var(--amber)'],
    ];
@endphp

@section('screen')
{{-- Visiting the full updates page counts as seeing the latest version — clears the badge. --}}
<div x-data x-init="$store.changelog && $store.changelog.markSeen()"></div>

@include('partials.guide', [
    'key' => 'updates',
    'en'  => [
        'title' => "What's new in Amanahku",
        'body'  => 'Every release we ship is listed here, newest first — new features, improvements and fixes. The "New" badge on the Feedback button clears once you have read the latest entry.',
        'who'   => 'Everyone in the workspace',
    ],
    'ms'  => [
        'title' => 'Apa baharu dalam Amanahku',
        'body'  => 'Setiap keluaran yang kami hantar disenaraikan di sini, terbaharu dahulu — ciri baharu, penambahbaikan dan pembaikan. Lencana "Baharu" pada butang Maklum Balas hilang sebaik anda membaca entri terkini.',
        'who'   => 'Semua orang dalam ruang kerja',
    ],
])

<div style="max-width:760px;display:flex;flex-direction:column;gap:18px;">
    @forelse ($releases as $rel)
        <article class="uj-card" style="padding:22px 24px;">
            <div style="display:flex;align-items:baseline;justify-content:space-between;gap:14px;margin-bottom:16px;border-bottom:1px solid var(--hairline-soft);padding-bottom:14px;">
                <div style="display:flex;align-items:baseline;gap:11px;flex-wrap:wrap;">
                    <h2 style="font-size:17px;font-weight:600;color:var(--ink);margin:0;letter-spacing:-0.3px;">{{ $rel['title'] }}</h2>
                    @if ($rel['version'] === $latestVersion)
                        <span style="font-size:10px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:#fff;background:var(--red);border-radius:9999px;padding:2px 8px;"
                              x-text="$store.ui.lang==='en' ? 'Latest' : 'Terkini'">Latest</span>
                    @endif
                    <span style="font-size:11.5px;color:var(--muted-soft);font-family:var(--font-mono);">v{{ $rel['version'] }}</span>
                </div>
                <span style="font-size:12px;color:var(--muted);font-family:var(--font-mono);white-space:nowrap;flex-shrink:0;">{{ $rel['date'] }}</span>
            </div>

            @foreach ($noteMeta as $key => $meta)
                @php $lines = $rel['notes'][$key] ?? []; @endphp
                @if (!empty($lines))
                    <div style="margin-bottom:16px;">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:9px;">
                            <span style="width:7px;height:7px;border-radius:50%;background:{{ $meta['dot'] }};"></span>
                            <span style="font-size:11px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:var(--muted);"
                                  x-text="$store.ui.lang==='en' ? @js($meta['en']) : @js($meta['ms'])">{{ $meta['en'] }}</span>
                        </div>
                        <ul style="margin:0;padding:0 0 0 21px;display:flex;flex-direction:column;gap:7px;">
                            @foreach ($lines as $line)
                                <li style="font-size:13.5px;color:var(--body);line-height:1.55;">{{ $line }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endforeach
        </article>
    @empty
        <div class="uj-card" style="padding:48px 24px;text-align:center;">
            <p style="font-size:14px;color:var(--muted);margin:0;"
               x-text="$store.ui.lang==='en' ? 'No updates have been published yet.' : 'Tiada kemas kini diterbitkan lagi.'">No updates have been published yet.</p>
        </div>
    @endforelse
</div>
@endsection
