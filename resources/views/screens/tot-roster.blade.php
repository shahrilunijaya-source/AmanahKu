@extends('layouts.app')

@php
    $slotSeed = collect($slots)->map(fn ($s) => [
        'id' => $s->exists ? $s->id : null,
        'status' => $s->status,
        'title' => $s->title,
        'date' => $s->session_date->format('j M'),
        'month' => $s->session_date->format('M'),
        'presenter' => $s->presenter_employee_id
            ? ['id' => $s->presenter_employee_id, 'name' => $s->presenterLabel()]
            : null,
    ])->values();
    $rosterSeed = $assignableEmployees->map(fn ($p) => ['id' => $p->id, 'name' => $p->display_name])->values();
@endphp

@section('screen')
<div class="tr-desk">
    <h3 x-text="$store.ui.lang==='en' ? 'Assigning presenters needs a wider screen' : 'Menetapkan pembentang memerlukan skrin lebih lebar'">Assigning presenters needs a wider screen</h3>
    <p x-text="$store.ui.lang==='en' ? 'The picker puts the twelve months beside the roster so a click lands in the month the cursor is on. That needs both columns at once. The board itself works here.' : 'Pemilih meletakkan dua belas bulan di sebelah senarai supaya klik jatuh pada bulan kursor. Ia perlukan kedua-dua lajur serentak. Papan TOT sendiri berfungsi di sini.'">The picker puts the twelve months beside the roster.</p>
</div>

<div class="tr" x-data="totRoster({ year: {{ $year }}, slots: {{ \Illuminate\Support\Js::from($slotSeed) }}, roster: {{ \Illuminate\Support\Js::from($rosterSeed) }} })">
    <div class="tr-panel">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
            <h4 class="wd-sech" style="margin:0;" x-text="$store.ui.lang==='en' ? 'Roster' : 'Jadual'">Roster</h4>
            <div style="margin-left:auto;display:flex;gap:6px;align-items:center;">
                @foreach ($years as $y)
                    <a href="{{ route('app.screen', ['screen' => 'tot-roster', 'year' => $y]) }}"
                       class="tot-yr" style="color:var(--muted);" @if ($y === $year) aria-selected="true" @endif>{{ $y }}</a>
                @endforeach
                {{-- session_date is computed, so a new year needs no rows: this is a link,
                     not a migration. The year sticks in the picker once somebody is assigned. --}}
                <a href="{{ route('app.screen', ['screen' => 'tot-roster', 'year' => max($years) + 1]) }}"
                   class="tot-pillbtn">+ {{ max($years) + 1 }}</a>
            </div>
        </div>

        <div class="tr-slots">
            <template x-for="(s, i) in slots" :key="i">
                <button type="button" class="tr-slot"
                        :data-filled="s.presenter ? '' : null"
                        :data-cursor="cursor === i ? '' : null"
                        @click="setCursor(i)">
                    <span class="tr-num" x-text="i + 1"></span>
                    <span class="tr-mon"><span x-text="s.month"></span><span x-text="s.date"></span></span>
                    <span class="tr-who">
                        <template x-if="s.presenter">
                            <span class="tr-who-n" x-text="s.presenter.name"></span>
                        </template>
                        <template x-if="!s.presenter && s.status === 'not_tot'">
                            <span class="tr-empty tr-empty--event" x-text="s.title"></span>
                        </template>
                        <template x-if="!s.presenter && s.status !== 'not_tot'">
                            <span class="tr-empty" x-text="$store.ui.lang==='en' ? 'Nobody yet' : 'Belum ada'">Nobody yet</span>
                        </template>
                    </span>
                    <span class="tr-x" role="button" @click.stop="clear(i)"
                          :aria-label="$store.ui.lang==='en' ? 'Clear this month' : 'Kosongkan bulan ini'">&times;</span>
                </button>
            </template>
        </div>
    </div>

    <div>
        <p class="tot-note" style="margin:0 0 12px;"
           x-text="cursor === null
             ? ($store.ui.lang==='en' ? 'Every month has a presenter. Click a month to change one.' : 'Setiap bulan sudah ada pembentang. Klik satu bulan untuk menukarnya.')
             : ($store.ui.lang==='en' ? `Cursor is on ${slots[cursor].month}. Click a person to assign them, and it moves to the next empty month.` : `Kursor pada ${slots[cursor].month}. Klik seseorang untuk menetapkannya, dan ia beralih ke bulan kosong seterusnya.`)"></p>

        <input class="tr-search" x-model="filter" autocomplete="off"
               :placeholder="$store.ui.lang==='en' ? 'Search the roster…' : 'Cari senarai…'">

        <div class="tr-grid">
            <template x-for="p in people" :key="p.id">
                <button type="button" class="tr-p" @click="assign(p)">
                    <span class="tr-p-n" x-text="p.name"></span>
                    <template x-if="badgesFor(p.id).length">
                        <span class="tr-badges">
                            <template x-for="b in badgesFor(p.id)" :key="b">
                                <span class="tr-badge" x-text="b"></span>
                            </template>
                        </span>
                    </template>
                </button>
            </template>
        </div>
    </div>
</div>
@endsection
