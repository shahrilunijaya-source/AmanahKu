{{--
    The body of the What's new popup — the major entries of the newest release.
    Fetched by partials/whats-new.blade.php only when the popup is about to show,
    rather than rendered into every page: release notes are ordinary prose about
    the whole app, and a screen carrying that prose invisibly is prose that turns
    up in other screens' assertions (and in every response's weight).
--}}
@php
    /** The first line of an entry is its headline — the rest is detail for the Changelog screen. */
    $headline = fn (string $text): string => explode("\n", $text)[0];
    $tagLabel = [
        'added' => ['en' => 'Added', 'ms' => 'Ditambah'],
        'improved' => ['en' => 'Improved', 'ms' => 'Ditambah baik'],
        'fixed' => ['en' => 'Fixed', 'ms' => 'Dibaiki'],
    ];
    $tagTone = ['added' => 'success', 'fixed' => 'error'];
@endphp
@foreach ($major as $entry)
    @php $tone = $tagTone[$entry['tag']] ?? null; @endphp
    <div style="display:flex;gap:12px;align-items:flex-start;">
        <span class="uj-stamp"@if ($tone) data-tone="{{ $tone }}" @endif style="flex-shrink:0;margin-top:1px;"
              x-text="$store.ui.lang==='en' ? @js($tagLabel[$entry['tag']]['en']) : @js($tagLabel[$entry['tag']]['ms'])">{{ $tagLabel[$entry['tag']]['en'] }}</span>
        <div class="uj-whatsnew-line" style="min-width:0;font-size:13px;color:var(--body);line-height:1.55;"
             x-text="$store.ui.lang==='en' ? @js($headline($entry['text'])) : @js($headline($entry['text_ms']))">{{ $headline($entry['text']) }}</div>
    </div>
@endforeach
