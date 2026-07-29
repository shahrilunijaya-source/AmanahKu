@php
    /**
     * A PINNED action queue card ($queue or $secondary in dash.blade.php).
     * Pinned cards are never hidden or reordered — in edit mode they show an
     * "Always shown" lock badge instead of the hide/move tools.
     *
     * Expects:
     *   $id     string, stable id for data-card (used by edit-mode CSS scoping only)
     *   $title  string, already-localized card title
     *   $rows   Collection|array of ROW ARRAYs
     *   $meta   ['title','done_title','done_body','link' => ['label','url']|null]
     *   $hot    bool, whether the count badge starts in the alarm tone
     *   $startIndex int, running index for the entrance stagger
     */
    $rows = collect($rows ?? []);
    $count = $rows->count();
    $hot = ($hot ?? false) && $count > 0;
@endphp
<div class="uj-card" data-card="{{ $id }}" data-pin style="position:relative;">
    {{-- Matches the tools pill on optional cards (partials.dash.rail-card) so both
         badges arrive with the card, not on top of it. --}}
    <span class="uj-dq-pin" x-show="edit" x-cloak x-transition.opacity.duration.160ms><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 11h14a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-8a1 1 0 0 1 1-1zM8 11V7a4 4 0 1 1 8 0v4"/></svg><span x-text="$store.ui.lang==='en' ? 'Always shown' : 'Sentiasa dipapar'">Always shown</span></span>
    <div class="uj-card-head">
        <h2 class="uj-card-title">{{ $title }}</h2>
        <span class="uj-dq-count"@if($hot) data-hot @elseif($count===0) data-ok @endif>{{ $count }}</span>
        @if (! empty($meta['link']))
            <a class="uj-link" href="{{ $meta['link']['url'] }}" style="margin-left:auto;text-decoration:none;">{{ $meta['link']['label'] }}</a>
        @endif
    </div>
    @forelse ($rows as $n => $it)
        @include('partials.dash.row', ['it' => $it, 'index' => $startIndex + $n])
    @empty
        <div class="uj-dq-done">
            <div class="uj-dq-done-mark"><svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg></div>
            <h3>{{ $meta['done_title'] ?? '' }}</h3>
            <p>{{ $meta['done_body'] ?? '' }}</p>
        </div>
    @endforelse
</div>
