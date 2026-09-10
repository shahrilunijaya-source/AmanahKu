@extends('layouts.app')

@php
    $slides = $slides ?? collect();
    $pastMonths = $pastMonths ?? [];
    $canSelect = ($canSelectNewButDangerous ?? false) || ($canSelectChosenOne ?? false) || ($isMysteryCommitteeMember ?? false);
    // QA S18 F2: after a plain form post the page comes back on the tab that was used.
    // CR-27: the mystery pick/committee forms carry no award_key, so a validation
    // failure on either falls back to their own fields to land back on Select.
    $tab = session('tab', $errors->any()
        ? (old('award_key') !== null
            ? (in_array(old('award_key'), ['new_but_dangerous', 'chosen_one'], true) ? 'select' : 'nominate')
            : ((old('category') !== null || old('employee_ids') !== null) ? 'select' : 'winners'))
        : 'winners');
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'awards',
    'en'  => [
        'title' => 'Awards',
        'body'  => "Every month's winners, in one place. Nominate a colleague for Main Character Energy or Office Yoda in the last week of the month; PM and above pick New but Dangerous and The Chosen One.",
    ],
    'ms'  => [
        'title' => 'Anugerah',
        'body'  => 'Pemenang setiap bulan, dalam satu tempat. Calonkan rakan sekerja untuk Tenaga Watak Utama atau Yoda Pejabat pada minggu terakhir bulan; PM ke atas memilih Baru Tapi Berbahaya dan Yang Terpilih.',
    ],
])

<div x-data="{ tab: @js($tab) }" style="display:flex;flex-direction:column;gap:16px;">
    @if (session('ok'))
        <div class="uj-card" style="padding:12px 18px;font-size:13px;color:var(--ink);border-left:3px solid var(--green,#1c7c54);">{{ session('ok') }}</div>
    @endif
    @if ($errors->any())
        <div class="uj-card" style="padding:12px 18px;font-size:13px;color:var(--ink);border-left:3px solid var(--red,#b42318);">{{ $errors->first() }}</div>
    @endif
    <div class="uj-seg" style="width:max-content;max-width:100%;flex-wrap:wrap;">
        <button type="button" :data-on="tab === 'winners' ? '' : null" @click="tab = 'winners'" data-tip-below data-tip="This cycle's winners">
            <span x-text="$store.ui.lang==='en' ? @js("This month's winners") : 'Pemenang bulan ini'">This month's winners</span>
        </button>
        <button type="button" :data-on="tab === 'nominate' ? '' : null" @click="tab = 'nominate'" data-tip-below data-tip="Put a colleague forward">
            <span x-text="$store.ui.lang==='en' ? 'Nominate' : 'Calonkan'">Nominate</span>
        </button>
        @if ($canSelect)
            <button type="button" :data-on="tab === 'select' ? '' : null" @click="tab = 'select'" data-tip-below data-tip="Committee picks the winner">
                <span x-text="$store.ui.lang==='en' ? 'Select' : 'Pilih'">Select</span>
            </button>
        @endif
        <button type="button" :data-on="tab === 'past' ? '' : null" @click="tab = 'past'" data-tip-below data-tip="Earlier cycles">
            <span x-text="$store.ui.lang==='en' ? 'Past winners' : 'Pemenang terdahulu'">Past winners</span>
        </button>
    </div>

    {{-- This month's winners --}}
    <div x-show="tab === 'winners'" class="uj-card" style="padding:20px;">
        @if ($month === null)
            <p style="font-size:13px;color:var(--muted);">No awards have been published yet.</p>
        @else
            <p style="font-size:12px;color:var(--muted);margin:0 0 6px;">{{ \Carbon\Carbon::parse($month)->format('F Y') }}</p>
            @foreach ($slides as $group)
                @include($group->award_key === 'mystery' ? 'partials.awards.mystery' : 'partials.awards.result', ['group' => $group, 'attr' => 'award', 'canAdjust' => $canAdjust ?? false, 'colleagues' => $colleagues ?? collect()])
            @endforeach
        @endif
    </div>

    {{-- Nominate --}}
    <div x-show="tab === 'nominate'" class="uj-card" style="padding:20px;">
        @if (! ($nominationWindowOpen ?? false))
            <p style="font-size:13px;color:var(--muted);">Nominations open in the last week of the month.</p>
        @endif
        <form method="post" action="{{ url('/app/awards/nominate') }}" style="display:flex;flex-direction:column;gap:10px;max-width:420px;">
            @csrf
            <div>
                <label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">Award</label>
                <select name="award_key" style="height:38px;width:100%;border:1px solid var(--hairline);border-radius:8px;padding:0 10px;font-size:13px;">
                    @foreach ($nominatedKeys ?? [] as $key)
                        <option value="{{ $key }}">{{ \App\Support\AwardCatalog::copy($key)['en']['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">Colleague</label>
                @include('partials.person-select', ['name' => 'employee_id', 'people' => $colleagues ?? [], 'required' => true, 'style' => 'height:38px;width:100%;border:1px solid var(--hairline);border-radius:8px;padding:0 10px;font-size:13px;'])
            </div>
            <div>
                <label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">Why</label>
                <textarea name="reason" rows="3" maxlength="2000" style="width:100%;border:1px solid var(--hairline);border-radius:8px;padding:8px 10px;font-size:13px;"></textarea>
            </div>
            <button type="submit" class="uj-btn-primary" style="height:38px;font-size:13px;" data-tip-end data-tip="Sends to the committee, one per cycle">Nominate</button>
        </form>
    </div>

    {{-- Select (PM and above): New but Dangerous, Director-only: The Chosen One --}}
    @if ($canSelect)
        <div x-show="tab === 'select'" class="uj-aw-sel">
            @if ($canSelectNewButDangerous ?? false)
                <form method="post" action="{{ url('/app/awards/select') }}" class="uj-aw-panel uj-ma-form">
                    @csrf
                    <input type="hidden" name="award_key" value="new_but_dangerous" />
                    <div class="uj-aw-panel-h">
                        <div class="uj-ma-h">&#127793; New but Dangerous</div>
                        <p class="uj-ma-hint">Someone who joined within the last 6 months and already made a dent.</p>
                    </div>
                    <div>
                        <label>Colleague</label>
                        @include('partials.person-select', ['name' => 'employee_id', 'people' => $colleagues ?? [], 'required' => true])
                    </div>
                    <button type="submit" class="uj-btn-primary" style="height:38px;font-size:13px;" data-tip-end data-tip="Names the winner for this cycle">Pick</button>
                </form>
            @endif
            @if ($canSelectChosenOne ?? false)
                <form method="post" action="{{ url('/app/awards/select') }}" class="uj-aw-panel uj-ma-form">
                    @csrf
                    <input type="hidden" name="award_key" value="chosen_one" />
                    <div class="uj-aw-panel-h">
                        <div class="uj-ma-h">&#127942; The Chosen One</div>
                        <p class="uj-ma-hint">Director's pick for the month. The reason is shown with the award.</p>
                    </div>
                    <div>
                        <label>Colleague</label>
                        @include('partials.person-select', ['name' => 'employee_id', 'people' => $colleagues ?? [], 'required' => true])
                    </div>
                    <div>
                        <label>Reason (required)</label>
                        <textarea name="reason" rows="3" maxlength="2000" required placeholder="One or two lines on why this month is theirs"></textarea>
                    </div>
                    <button type="submit" class="uj-btn-primary" style="height:38px;font-size:13px;" data-tip-end data-tip="Names the winner for this cycle">Pick</button>
                </form>
            @endif

            {{-- CR-27: director or that month's rotating mystery committee only. --}}
            @if (($isDirector ?? false) || ($isMysteryCommitteeMember ?? false))
                <div class="uj-aw-panel uj-aw-panel--wide">
                    <div class="uj-ma-h">&#9993;&#65039; Mystery Award</div>
                    <p class="uj-ma-hint" style="margin:2px 0 10px;">One surprise a month. No rubric, no points, never counts. Category unknown to everyone until the 1st.</p>

                    @if ($mysteryPicked ?? false)
                        <div class="uj-ma-sealed" data-mystery-picked="{{ $mysteryMonth }}">
                            <span class="env" aria-hidden="true">&#9993;&#65039;</span>
                            <span>Sealed. A pick for {{ \Carbon\Carbon::parse($mysteryMonth)->format('F') }} is in. Category and reason stay hidden, even here, until the reveal on the 1st. Picking again replaces it.</span>
                        </div>
                        <details style="margin-top:8px;">
                            <summary style="cursor:pointer;font-size:12.5px;color:var(--muted);">Pick again</summary>
                            <div style="margin-top:10px;">
                                @include('partials.awards.mystery-form', ['colleagues' => $colleagues ?? collect(), 'mysteryLastWinnerId' => $mysteryLastWinnerId ?? null])
                            </div>
                        </details>
                    @else
                        @include('partials.awards.mystery-form', ['colleagues' => $colleagues ?? collect(), 'mysteryLastWinnerId' => $mysteryLastWinnerId ?? null])
                    @endif

                    @if ($isDirector ?? false)
                        <div style="margin-top:16px;">
                            <div class="uj-ma-h">Mystery committee &middot; {{ \Carbon\Carbon::parse($mysteryMonth)->format('F') }}</div>
                            <div class="uj-ma-chips" style="margin-top:6px;">
                                @forelse ($mysteryCommitteeMembers ?? [] as $m)
                                    <span class="uj-ma-chip"><span class="uj-db-avatar" style="background:{{ $m->avatar_color ?? '#3a6ea5' }};">{{ $m->initials }}</span>{{ $m->display_name }}</span>
                                @empty
                                    <span class="uj-ma-hint">No committee set for this month yet.</span>
                                @endforelse
                            </div>
                            <details style="margin-top:8px;">
                                <summary class="uj-btn-ghost" style="cursor:pointer;display:inline-block;font-size:12px;padding:4px 10px;">Change</summary>
                                <form method="post" action="{{ url('/app/awards/mystery/committee') }}" style="display:flex;flex-direction:column;gap:8px;max-width:420px;margin-top:8px;">
                                    @csrf
                                    @for ($i = 0; $i < 3; $i++)
                                        @include('partials.person-select', ['name' => 'employee_ids[]', 'people' => $colleagues ?? [], 'required' => true, 'placeholder' => 'Committee member '.($i + 1), 'style' => 'height:36px;width:100%;border:1px solid var(--hairline);border-radius:8px;padding:0 10px;font-size:13px;'])
                                    @endfor
                                    <button type="submit" class="uj-btn-primary" style="height:34px;font-size:12.5px;" data-tip-end data-tip="These people pick the winners">Save committee</button>
                                </form>
                            </details>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    @endif

    {{-- Past winners --}}
    <div x-show="tab === 'past'" class="uj-card" style="padding:20px;">
        @if ($pastMonths === [])
            <p style="font-size:13px;color:var(--muted);">Nothing published yet.</p>
        @else
            <div style="display:flex;flex-direction:column;gap:6px;">
                @foreach ($pastMonths as $m)
                    <a data-month="{{ $m }}" href="{{ url('/app/awards') }}?month={{ $m }}" style="font-size:13px;color:var(--ink);text-decoration:none;">{{ \Carbon\Carbon::parse($m)->format('F Y') }}</a>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection
