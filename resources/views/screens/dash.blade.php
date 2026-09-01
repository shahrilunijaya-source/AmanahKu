@extends('layouts.app')

{{--
    Dashboard — ported from the approved public/_dash-unified.html mock.

    One grid of widgets, no scope switch: the old Me/Company tabs are gone and
    each card is gated by role on the server instead (App\Support\DashboardWidgets).
    A widget the viewer may not see is never built and never reaches this view, so
    nothing here re-checks permissions.

    Data contract (populated by BuildsDashboardWidgets::dashboardData):
        $head           ['h1','sub']
        $widgetCatalog  [['id','title','title_ms','blurb','blurb_ms','category','pinned'], ...]
                        — every widget this viewer MAY have, for the picker
        $widgetLayout   ['left' => [id, ...], 'right' => [id, ...]] — what is shown, in order
        $widgetPrefs    ['hidden' => [id, ...], 'order' => ['left' => [...], 'right' => [...]]]
        $widgets        [id => payload] for the shown widgets only

    Visibility and drag order POST to route('dashboard.prefs.update'); if that
    route is missing, save() is a silent no-op so the UI still works locally.
    The period arrows GET route('dashboard.widget') for one card's markup and swap
    it in place — same fallback, the arrows just do nothing without the route.
--}}

@section('screen')
@php
    $head = $head ?? ['h1' => '', 'sub' => ''];
    $widgetCatalog = $widgetCatalog ?? [];
    $widgetLayout = $widgetLayout ?? ['left' => [], 'right' => []];
    $widgetPrefs = $widgetPrefs ?? ['hidden' => [], 'order' => []];
    $widgets = $widgets ?? [];
    $categories = \App\Support\DashboardWidgets::CATEGORIES;
    $categoriesMs = ['All' => 'Semua', 'Me' => 'Saya', 'Attendance' => 'Kehadiran', 'Leave' => 'Cuti', 'Claim' => 'Tuntutan', 'Team' => 'Pasukan'];
@endphp

<div class="uj-dw-page" x-data="ujDashboard({
        hidden: @js(array_values($widgetPrefs['hidden'] ?? [])),
        catalog: @js($widgetCatalog),
        prefsUrl: @js(\Illuminate\Support\Facades\Route::has('dashboard.prefs.update') ? route('dashboard.prefs.update') : null),
        widgetUrl: @js(\Illuminate\Support\Facades\Route::has('dashboard.widget') ? route('dashboard.widget', '__id__') : null),
    })" x-init="initDrag(); initBalance()">

    {{-- The dashboard owns its heading; the shared layout suppresses its page-title
         block for this screen (layouts/app.blade.php) so the greeting prints once. --}}
    <div class="uj-dw-head">
        <h1>{{ $head['h1'] }}</h1>
        <span class="uj-dw-today">{{ $head['sub'] }}</span>
        <button type="button" class="uj-dw-gear" @click="openPicker()"
                :aria-label="$store.ui.lang==='en' ? 'Choose widgets' : 'Pilih widget'">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="3"/>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6 1.65 1.65 0 0 0 10 3.09V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9c.2.6.76 1 1.4 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
            </svg>
        </button>
    </div>

    <div class="uj-dw-grid">
        @foreach (\App\Support\DashboardWidgets::COLUMNS as $column)
            <div class="uj-dw-col" data-col="{{ $column }}">
                @foreach ($widgetLayout[$column] ?? [] as $id)
                    @include('partials.dash.widget', ['id' => $id, 'widgets' => $widgets])
                @endforeach
            </div>
        @endforeach
    </div>

    {{-- Widget picker. A modal is right here: choosing what the page contains is a
         decision about the page, so the page steps back while you make it. --}}
    <div class="uj-dw-scrim" :data-open="picking ? '' : null" @click.self="cancelPicker()"
         @keydown.escape.window="picking && cancelPicker()"
         role="dialog" aria-modal="true" x-cloak>
        <div class="uj-dw-sheet">
            <div class="uj-dw-sheet-hd">
                <span>
                    <h2 x-text="$store.ui.lang==='en' ? 'Dashboard widgets' : 'Widget papan pemuka'">Dashboard widgets</h2>
                    <span class="sub" x-text="$store.ui.lang==='en'
                        ? 'Pick what shows on your dashboard. Your picks are yours alone, and change nothing for anyone else.'
                        : 'Pilih apa yang dipapar pada papan pemuka anda. Pilihan ini milik anda sahaja dan tidak mengubah apa-apa untuk orang lain.'"></span>
                </span>
                <button type="button" class="uj-dw-x" @click="cancelPicker()"
                        :aria-label="$store.ui.lang==='en' ? 'Close' : 'Tutup'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="uj-dw-filters">
                @foreach ($categories as $cat)
                    <button type="button" :data-on="filter === @js($cat) ? '' : null" @click="filter = @js($cat)"
                            x-data="{ en: @js($cat), ms: @js($categoriesMs[$cat] ?? $cat) }"
                            x-text="$store.ui.lang==='en' ? en : ms">{{ $cat }}</button>
                @endforeach
            </div>
            <div class="uj-dw-picks">
                <template x-for="item in shown()" :key="item.id">
                    <button type="button" class="uj-dw-pick" :data-on="draft.includes(item.id) ? '' : null"
                            :disabled="item.pinned" @click="toggle(item.id)">
                        <span class="box">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                        </span>
                        <span class="txt">
                            <span class="t" x-text="$store.ui.lang==='en' ? item.title : item.title_ms"></span>
                            <span class="s" x-text="item.pinned
                                ? ($store.ui.lang==='en' ? 'Always shown — this is your action list.' : 'Sentiasa dipapar — ini senarai tindakan anda.')
                                : ($store.ui.lang==='en' ? item.blurb : item.blurb_ms)"></span>
                        </span>
                    </button>
                </template>
            </div>
            <div class="uj-dw-sheet-ft">
                <span class="n" x-text="$store.ui.lang==='en'
                    ? draft.length + ' of ' + catalog.length + ' on'
                    : draft.length + ' daripada ' + catalog.length + ' dibuka'"></span>
                <button type="button" class="uj-dw-btn uj-dw-btn-ghost" @click="cancelPicker()"
                        x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button>
                <button type="button" class="uj-dw-btn uj-dw-btn-red" @click="savePicker()"
                        x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</button>
            </div>
        </div>
    </div>
</div>

@endsection
