{{-- Pending tasks, grouped rather than flattened: what is yours to approve, what
     you owe, and what of yours is in flight. Each group is an accordion; the rows
     inside are the shared disclosure row (partials.dash.row), which carries the
     guidance copy and the action buttons. --}}
<div class="uj-dw-body">
    @forelse ($w['groups'] ?? [] as $i => $g)
        <div class="uj-dw-acc" x-data="{ open: {{ $i === 0 ? 'true' : 'false' }} }" :data-open="open ? '' : null">
            <button type="button" class="uj-dw-acc-hd" @click="open = !open" :aria-expanded="open.toString()">
                <span class="t">{{ $g['title'] }}</span>
                <span class="uj-dw-count" @if ($g['hot']) data-hot @endif>{{ $g['count'] }}</span>
                <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
            </button>
            <div class="uj-dw-acc-body">
                <div><div class="uj-dw-acc-in">
                    @foreach ($g['rows'] as $it)
                        @include('partials.dash.row', ['it' => $it, 'index' => 0, 'anim' => false])
                    @endforeach
                </div></div>
            </div>
        </div>
    @empty
        <div class="uj-dq-done">
            <div class="uj-dq-done-mark">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
            </div>
            <h3 x-text="$store.ui.lang==='en' ? 'All clear' : 'Semua selesai'">All clear</h3>
            <p x-text="$store.ui.lang==='en'
                ? 'Nothing is waiting on you right now.'
                : 'Tiada apa-apa menunggu tindakan anda sekarang.'">Nothing is waiting on you right now.</p>
        </div>
    @endforelse
</div>
