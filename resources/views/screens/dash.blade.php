@extends('layouts.app')

{{--
    Dashboard content — ported from the approved public/_dash-revamp.html mock.
    Data contract (populated by the controller):
        $scope        'me'|'company'
        $scopes       [['id','label'], ...] — Me/Company switch, shown only when count > 1
        $head         ['h1','sub']
        $chips        [['n','label','hot'], ...] (4 items)
        $queue        Collection of ROW ARRAYs — pinned primary queue
        $queueMeta    ['title','done_title','done_body']
        $secondary    Collection of ROW ARRAYs — pinned second queue
        $secondaryMeta ['title','done_title','done_body','link' => ['label','url']|null]
        $railCards    ordered array of ['id','title','rows' => array] — optional/hideable
        $cardPrefs    ['hidden' => string[], 'order' => string[]]

    Customise-mode preference changes POST to route('dashboard.prefs.update') —
    if that route does not exist yet, save() below is a silent no-op (guarded
    on prefsUrl) so the UI still works locally without failing loudly.
--}}

@section('screen')
@php
    $scope = $scope ?? 'me';
    $scopes = $scopes ?? [];
    $chips = $chips ?? [];
    $head = $head ?? ['h1' => '', 'sub' => ''];
    $queue = collect($queue ?? []);
    $secondary = collect($secondary ?? []);
    $queueMeta = $queueMeta ?? [];
    $secondaryMeta = $secondaryMeta ?? [];
    $railCards = $railCards ?? [];
    $cardPrefs = $cardPrefs ?? [];
    $hiddenInit = $cardPrefs['hidden'] ?? [];
    $railIds = collect($railCards)->pluck('id')->all();
    $orderInit = ! empty($cardPrefs['order']) ? $cardPrefs['order'] : $railIds;
@endphp

<div class="uj-dq" x-data="{
        edit: false,
        hidden: @js($hiddenInit),
        order: @js($orderInit),
        railIds: @js($railIds),
        scope: @js($scope),
        prefsUrl: @js(\Illuminate\Support\Facades\Route::has('dashboard.prefs.update') ? route('dashboard.prefs.update') : null),
        toggleEdit() { this.edit = !this.edit; },
        isHidden(id) { return this.hidden.includes(id); },
        ord(id) { let i = this.order.indexOf(id); return i === -1 ? 999 : i; },
        visibleOrdered() {
            return this.railIds.filter(id => !this.hidden.includes(id)).sort((a, b) => this.ord(a) - this.ord(b));
        },
        isFirstVisible(id) { let v = this.visibleOrdered(); return v.length ? v[0] === id : true; },
        isLastVisible(id) { let v = this.visibleOrdered(); return v.length ? v[v.length - 1] === id : true; },
        hide(id) { if (!this.hidden.includes(id)) this.hidden.push(id); this.save(); },
        show(id) { this.hidden = this.hidden.filter(x => x !== id); this.save(); },
        move(id, dir) {
            let v = this.visibleOrdered();
            let i = v.indexOf(id), to = i + dir;
            if (to < 0 || to >= v.length) return;
            v.splice(to, 0, v.splice(i, 1)[0]);
            this.order = v.concat(this.hidden);
            this.save();
        },
        save() {
            if (!this.prefsUrl) return;
            fetch(this.prefsUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ scope: this.scope, hidden: this.hidden, order: this.order }),
            }).catch(() => {});
        },
    }"
    :data-dq-editing="edit ? '' : null">

    {{-- The dashboard owns its heading. The shared layout suppresses its page-title
         block for this screen (layouts/app.blade.php) so the sentence prints once. --}}
    <div class="uj-dq-top">
        {{-- Title left, scope switch right: the switch changes WHOSE dashboard this is,
             so it belongs beside the sentence it rewrites, not among the count chips. --}}
        <div class="uj-dq-titlerow">
            <div>
                <h1>{{ $head['h1'] }}</h1>
                <p style="font-size:var(--t-sm);color:var(--muted);margin:0;line-height:1.55;">{{ $head['sub'] }}</p>
            </div>
            @if (count($scopes) > 1)
                <div class="uj-dq-scopes" role="group"
                     :aria-label="$store.ui.lang==='en' ? 'Dashboard scope' : 'Skop papan pemuka'">
                    @foreach ($scopes as $s)
                        <a class="uj-dq-scope" href="{{ route('app.screen', ['screen' => $screen ?? 'dash', 'scope' => $s['id']]) }}"
                           @if ($scope === $s['id']) data-on aria-current="page" @endif
                           x-data="{ en: @js($s['label']), ms: @js($s['label_ms'] ?? $s['label']) }"
                           x-text="$store.ui.lang==='en' ? en : ms">{{ $s['label'] }}</a>
                    @endforeach
                </div>
            @endif
        </div>
        <div class="uj-dq-chips">
            @foreach ($chips as $chip)
                <div class="uj-dq-chip"@if (! empty($chip['hot'])) data-hot @endif>
                    <b>{{ $chip['n'] }}</b><span>{{ $chip['label'] }}</span>
                </div>
            @endforeach
            @if (! empty($railCards))
                <button type="button" class="uj-dq-cust" :data-on="edit" @click="toggleEdit()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0M14 4v4M8 10v4M16 16v4"/></svg>
                    <span x-text="edit ? ($store.ui.lang==='en' ? 'Done' : 'Selesai') : ($store.ui.lang==='en' ? 'Customise' : 'Sesuaikan')">Customise</span>
                </button>
            @endif
        </div>
    </div>

    <p class="uj-dq-hint" x-show="edit" x-cloak x-text="$store.ui.lang==='en'
        ? 'Hide or reorder the supporting cards. Your layout is saved for you only, and does not change what anyone else sees.'
        : 'Sembunyi atau susun semula kad sokongan. Susun atur ini disimpan untuk anda sahaja, dan tidak mengubah apa yang orang lain lihat.'"></p>

    @php $i = 0; @endphp

    <div class="uj-dash-split">
        <div class="uj-dash-main" style="display:flex;flex-direction:column;gap:16px;">
            @include('partials.dash.queue-card', [
                'id' => 'queue', 'title' => $queueMeta['title'] ?? '', 'rows' => $queue,
                'meta' => $queueMeta, 'hot' => true, 'startIndex' => $i,
            ])
            @php $i += max($queue->count(), 1); @endphp

            @if ($secondary->isNotEmpty() || ! empty($secondaryMeta))
                @include('partials.dash.queue-card', [
                    'id' => 'secondary', 'title' => $secondaryMeta['title'] ?? '', 'rows' => $secondary,
                    'meta' => $secondaryMeta, 'hot' => true, 'startIndex' => $i,
                ])
                @php $i += max($secondary->count(), 1); @endphp
            @endif
        </div>

        <div class="uj-dash-side" style="display:flex;flex-direction:column;gap:14px;">
            @foreach ($railCards as $n => $card)
                @include('partials.dash.rail-card', ['card' => $card, 'index' => $i + $n + 1])
            @endforeach
        </div>
    </div>

    @if (! empty($railCards))
        @include('partials.dash.tray')
    @endif
</div>
@endsection
