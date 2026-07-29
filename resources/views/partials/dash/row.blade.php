@php
    /**
     * One disclosure row inside a pinned queue card (partials.dash.queue-card).
     *
     * Expects:
     *   $it     ROW ARRAY — ['month','day','kind','stage','label','title','sub','body',
     *            'actions' => [['label','url','primary']]]
     *   $index  int, position within the whole page for the entrance stagger delay
     *   $anim   bool, whether to play the entrance stagger (first paint only)
     *
     * Row content (kind/title/sub/body/action labels) is assumed already
     * localized server-side — the contract's ROW ARRAY carries plain strings,
     * not bilingual pairs, so it is echoed directly rather than through the
     * x-text EN/MS ternary used for static screen chrome.
     */
    $anim = $anim ?? true;
    $stage = $it['stage'] ?? 'waiting';
    $label = $it['label'] ?? ($stage === 'waiting' ? ($it['kind'] ?? '') : $stage);
    $showKind = $stage !== 'waiting' && ! empty($it['kind']);
@endphp
<div class="uj-dq-row{{ $anim ? ' uj-dq-stag' : '' }}" style="--i:{{ $index }}" x-data="{ open:false }">
    <button type="button" class="uj-dq-row-hd" @click="open = !open" :aria-expanded="open.toString()">
        <span class="uj-dq-datechip">
            <span class="m">{{ $it['month'] ?? '' }}</span>
            <span class="d">{{ $it['day'] ?? '' }}</span>
        </span>
        <span style="flex:1;min-width:0;">
            <span style="display:flex;align-items:center;gap:8px;margin-bottom:3px;flex-wrap:wrap;">
                <span class="uj-dq-stage" data-s="{{ $stage }}">{{ $label }}</span>
                @if ($showKind)
                    <span style="font-size:var(--t-sm);color:var(--muted);">{{ $it['kind'] }}</span>
                @endif
            </span>
            <span class="uj-dq-row-t" style="display:block;">{{ $it['title'] ?? '' }}</span>
            <span class="uj-dq-row-s" style="display:block;">{{ $it['sub'] ?? '' }}</span>
        </span>
        <svg class="uj-dq-chev" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
    </button>
    <div class="uj-dq-row-body" :class="open ? 'uj-dq-open' : ''">
        <div><div class="uj-dq-row-body-in">
            <p>{{ $it['body'] ?? '' }}</p>
            @if (! empty($it['actions']))
                <div class="uj-dq-row-acts">
                    @foreach ($it['actions'] as $act)
                        <a href="{{ $act['url'] ?? '#' }}" class="uj-dq-btn {{ ! empty($act['primary']) ? 'uj-btn-primary' : 'uj-btn-ghost' }}">{{ $act['label'] ?? '' }}</a>
                    @endforeach
                </div>
            @endif
        </div></div>
    </div>
</div>
