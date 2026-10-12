{{-- Which payroll run a payroll screen is showing. A menu of links, not a <select>, so each
     run carries its status stamp and the list matches the rest of the app's menus.
     $screen / $tab: where the links land. $runs: newest first. $activeRun: the one on show. --}}
@php
    $runStamp = [
        'draft' => ['amber', 'Draft', 'Draf'],
        'approved' => [null, 'Approved', 'Diluluskan'],
        'finalized' => ['success', 'Finalized', 'Dimuktamadkan'],
    ];
    $stampFor = fn ($run) => $runStamp[$run->status] ?? [null, ucfirst((string) $run->status), ucfirst((string) $run->status)];
@endphp
@if ($runs->isNotEmpty())
<div class="pt-run" x-data="{
        open: false,
        step(d) {
            const links = [...$refs.list.querySelectorAll('a')];
            const i = links.indexOf(document.activeElement);
            (links[i < 0 ? (d > 0 ? 0 : links.length - 1) : (i + d + links.length) % links.length])?.focus();
        },
     }" @keydown.escape="if (open) { open = false; $refs.btn.focus(); }" @click.outside="open = false"
     @keydown.down.prevent="open = true; $nextTick(() => step(1))" @keydown.up.prevent="open && step(-1)">
    <span class="pt-run-label" id="run-picker-label-{{ $tab }}">Run</span>
    <div class="pt-run-pop">
    <button type="button" class="pt-btn pt-run-btn" x-ref="btn" @click="open = ! open" :aria-expanded="open" aria-haspopup="true" aria-labelledby="run-picker-label-{{ $tab }} run-picker-value-{{ $tab }}">
        <span id="run-picker-value-{{ $tab }}" class="pt-run-value">
            <span>{{ $activeRun?->label ?? '—' }}</span>
            @if ($activeRun)
                @php [$tone, $en, $ms] = $stampFor($activeRun); @endphp
                <span class="uj-stamp" @if ($tone) data-tone="{{ $tone }}" @endif x-text="$store.ui.lang==='en' ? @js($en) : @js($ms)">{{ $en }}</span>
            @endif
        </span>
        <svg class="pt-run-chev" :class="open && 'is-open'" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
    </button>
    <div class="pt-menu" x-ref="list" x-show="open" x-cloak>
        @foreach ($runs as $r)
            @php [$tone, $en, $ms] = $stampFor($r); $isCurrent = $activeRun?->id === $r->id; @endphp
            <a href="{{ route('app.screen', ['screen' => $screen, 'tab' => $tab, 'run' => $r->id]) }}"{!! $isCurrent ? ' aria-current="true"' : '' !!}>
                <svg class="pt-run-check" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                <span class="pt-run-name">{{ $r->label }}</span>
                <span class="uj-stamp" @if ($tone) data-tone="{{ $tone }}" @endif x-text="$store.ui.lang==='en' ? @js($en) : @js($ms)">{{ $en }}</span>
            </a>
        @endforeach
    </div>
    </div>
</div>
@endif
