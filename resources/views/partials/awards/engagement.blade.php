{{--
    CR-14b: reactions + comments for one award_results row (a tie's shared primary row —
    see App\Support\AwardBoard). Same fetch-and-swap pattern as
    partials/dash/birthday-wishes.blade.php: POST returns {html: <this partial>} and
    Alpine swaps this whole block's outerHTML, so reacting/commenting from the dashboard
    band never leaves the dashboard. Included on both the dashboard slide and the Awards
    screen row, so the two never drift apart on counts.

    $resultId       int     the primary award_results row this engagement belongs to
    $reactionCount  int
    $comments       Collection<{body, name}>  oldest first
    $activeKeys     list<string>  Reaction::activeKeys()
--}}
<div class="uj-award-engage" id="uj-award-engage-{{ $resultId }}" data-reactions="{{ $reactionCount }}" data-comments="{{ $comments->count() }}"
     style="margin-top:10px;padding-top:10px;border-top:1px solid var(--hairline);display:flex;flex-direction:column;gap:8px;"
     :data-open="open ? '' : null"
     x-data="{
        open: false,
        busy: false,
        async post(url, body) {
            if (this.busy) return;
            const root = this.$root;
            this.busy = true;
            try {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': @js(csrf_token()), Accept: 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify(body),
                });
                if (!res.ok) return;
                const data = await res.json();
                if (data.html) { root.outerHTML = data.html; }
            } finally { this.busy = false; }
        }
     }">
    <button type="button" class="uj-aw-react-count" @click="open = !open">{{ $reactionCount }} {{ $reactionCount === 1 ? 'reaction' : 'reactions' }} &middot; <span x-text="open ? 'hide' : 'react'">react</span></button>
    <div class="uj-aw-react-row" style="align-items:center;gap:6px;flex-wrap:wrap;">
        @foreach ($activeKeys as $key)
            @php $rd = \App\Models\Reaction::describe($key); @endphp
            {{-- QA S18 F7: the CR-30 icon and label, not the storage key. --}}
            <button type="button" class="uj-btn-ghost" style="height:26px;padding:0 9px;font-size:11px;" title="{{ $rd['label'] }}" data-reaction="{{ $key }}"
                    @click="post('{{ url('/app/awards/'.$resultId.'/react') }}', { reaction: '{{ $key }}' })">{{ $rd['icon'] }} {{ $rd['label'] }}</button>
        @endforeach
    </div>
    @if ($comments->isNotEmpty())
        <div style="display:flex;flex-direction:column;gap:4px;">
            @foreach ($comments as $c)
                <p style="font-size:12px;color:var(--body);margin:0;"><strong style="color:var(--ink);">{{ $c->name }}:</strong> {{ $c->body }}</p>
            @endforeach
        </div>
    @endif
    <form style="display:flex;gap:6px;" @submit.prevent="post('{{ url('/app/awards/'.$resultId.'/comments') }}', { body: $refs.body.value }); $refs.body.value=''">
        <input type="text" x-ref="body" maxlength="2000" placeholder="Add a comment…" style="flex:1;height:30px;font-size:12px;border:1px solid var(--hairline);border-radius:8px;padding:0 9px;" />
        <button type="submit" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12px;">Post</button>
    </form>
</div>
