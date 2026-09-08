@extends('layouts.app')

@php
    $slides = $slides ?? collect();
    $pastMonths = $pastMonths ?? [];
    $canSelect = ($canSelectNewButDangerous ?? false) || ($canSelectChosenOne ?? false);
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

<div x-data="{ tab: 'winners' }" style="display:flex;flex-direction:column;gap:16px;">
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <button type="button" class="uj-btn-ghost" :class="{ 'uj-btn-primary': tab === 'winners' }" style="height:36px;padding:0 14px;font-size:12.5px;" @click="tab = 'winners'">
            <span x-text="$store.ui.lang==='en' ? \"This month's winners\" : 'Pemenang bulan ini'">This month's winners</span>
        </button>
        <button type="button" class="uj-btn-ghost" :class="{ 'uj-btn-primary': tab === 'nominate' }" style="height:36px;padding:0 14px;font-size:12.5px;" @click="tab = 'nominate'">
            <span x-text="$store.ui.lang==='en' ? 'Nominate' : 'Calonkan'">Nominate</span>
        </button>
        @if ($canSelect)
            <button type="button" class="uj-btn-ghost" :class="{ 'uj-btn-primary': tab === 'select' }" style="height:36px;padding:0 14px;font-size:12.5px;" @click="tab = 'select'">
                <span x-text="$store.ui.lang==='en' ? 'Select' : 'Pilih'">Select</span>
            </button>
        @endif
        <button type="button" class="uj-btn-ghost" :class="{ 'uj-btn-primary': tab === 'past' }" style="height:36px;padding:0 14px;font-size:12.5px;" @click="tab = 'past'">
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
                @include('partials.awards.result', ['group' => $group, 'attr' => 'award'])
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
                <select name="employee_id" style="height:38px;width:100%;border:1px solid var(--hairline);border-radius:8px;padding:0 10px;font-size:13px;">
                    @foreach ($colleagues ?? [] as $c)
                        <option value="{{ $c->id }}">{{ $c->display_name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">Why</label>
                <textarea name="reason" rows="3" maxlength="2000" style="width:100%;border:1px solid var(--hairline);border-radius:8px;padding:8px 10px;font-size:13px;"></textarea>
            </div>
            <button type="submit" class="uj-btn-primary" style="height:38px;font-size:13px;">Nominate</button>
        </form>
    </div>

    {{-- Select (PM and above): New but Dangerous, Director-only: The Chosen One --}}
    @if ($canSelect)
        <div x-show="tab === 'select'" class="uj-card" style="padding:20px;display:flex;flex-direction:column;gap:20px;">
            @if ($canSelectNewButDangerous ?? false)
                <form method="post" action="{{ url('/app/awards/select') }}" style="display:flex;flex-direction:column;gap:10px;max-width:420px;">
                    @csrf
                    <input type="hidden" name="award_key" value="new_but_dangerous" />
                    <div>
                        <label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">New but Dangerous — colleague (joined within the last 6 months)</label>
                        <select name="employee_id" style="height:38px;width:100%;border:1px solid var(--hairline);border-radius:8px;padding:0 10px;font-size:13px;">
                            @foreach ($colleagues ?? [] as $c)
                                <option value="{{ $c->id }}">{{ $c->display_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="uj-btn-primary" style="height:38px;font-size:13px;">Pick</button>
                </form>
            @endif
            @if ($canSelectChosenOne ?? false)
                <form method="post" action="{{ url('/app/awards/select') }}" style="display:flex;flex-direction:column;gap:10px;max-width:420px;">
                    @csrf
                    <input type="hidden" name="award_key" value="chosen_one" />
                    <div>
                        <label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">The Chosen One — colleague</label>
                        <select name="employee_id" style="height:38px;width:100%;border:1px solid var(--hairline);border-radius:8px;padding:0 10px;font-size:13px;">
                            @foreach ($colleagues ?? [] as $c)
                                <option value="{{ $c->id }}">{{ $c->display_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">Reason (required)</label>
                        <textarea name="reason" rows="3" maxlength="2000" required style="width:100%;border:1px solid var(--hairline);border-radius:8px;padding:8px 10px;font-size:13px;"></textarea>
                    </div>
                    <button type="submit" class="uj-btn-primary" style="height:38px;font-size:13px;">Pick</button>
                </form>
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
