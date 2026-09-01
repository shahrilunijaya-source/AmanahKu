@php
    $rows = $w['rows'] ?? [];
    $top = array_slice($rows, 0, 3);
    $rest = array_slice($rows, 3);
@endphp
<div class="uj-dw-body uj-dw-flush" @if ($rest) x-data="{ more: false }" @endif>
    @forelse ($top as $r)
        @include('partials.dash.widgets.leave-row', ['r' => $r])
    @empty
        <p class="uj-dw-empty" x-text="$store.ui.lang==='en'
            ? 'No leave entitlement set up yet.'
            : 'Kelayakan cuti belum ditetapkan.'">No leave entitlement set up yet.</p>
    @endforelse

    @if ($rest)
        {{-- Most people have three leave types that matter and a long tail of
             one-day allowances; the tail is kept but folded away. --}}
        <div x-show="more" x-cloak>
            @foreach ($rest as $r)
                @include('partials.dash.widgets.leave-row', ['r' => $r])
            @endforeach
        </div>
        <button type="button" class="uj-dw-more" @click="more = ! more"
                :aria-expanded="more ? 'true' : 'false'"
                x-text="more
                    ? ($store.ui.lang==='en' ? 'Show less' : 'Tunjuk kurang')
                    : ($store.ui.lang==='en' ? 'Show {{ count($rest) }} more' : 'Tunjuk {{ count($rest) }} lagi')">Show {{ count($rest) }} more</button>
    @endif
</div>
<div class="uj-dw-foot">
    <span>{{ $w['pending'] ?? 0 }}
        <span x-text="$store.ui.lang==='en' ? 'awaiting approval' : 'menunggu kelulusan'">awaiting approval</span>
    </span>
    <a class="uj-dw-link" style="margin-left:auto" href="{{ route('app.screen', 'leave') }}"
       x-text="$store.ui.lang==='en' ? 'Apply for leave' : 'Mohon cuti'">Apply for leave</a>
</div>
