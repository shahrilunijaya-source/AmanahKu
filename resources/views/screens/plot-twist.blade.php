@extends('layouts.app')

@php
    /** @var \App\Models\PlotTwistPoll|null $poll */
    $poll = $poll ?? null;
    $results = $results ?? null;
    $voted = $voted ?? false;
    $canOptOut = $canOptOut ?? false;
    $optOutPolls = $optOutPolls ?? collect();
    $upcoming = $upcoming ?? false;
    $people = $people ?? collect();
    $canPublish = $canPublish ?? false;
    $suggestions = $suggestions ?? collect();
    $templates = $templates ?? collect();
    $revealed = $poll && $poll->isRevealed();
    $plain = (bool) \App\Support\DashboardPrefs::forUser(auth()->user()?->dashboard_prefs)['plain'];
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'plot-twist',
    'en' => [
        'title' => "This Week's Plot Twist",
        'body' => 'One fun company poll a week. Vote once, results land on the Notice board Friday at 3 PM. Nobody, not even the Director, can see who picked what.',
    ],
    'ms' => [
        'title' => 'Plot Twist Minggu Ini',
        'body' => 'Satu tinjauan syarikat yang seronok setiap minggu. Undi sekali, keputusan muncul di papan notis pada hari Jumaat jam 3 petang. Tiada siapa, termasuk Pengarah, boleh nampak siapa memilih apa.',
    ],
])

<div class="uj-pt-wrap"
     x-data="{
        busy: false,
        async vote(pollId, optionId) {
            if (this.busy) return;
            this.busy = true;
            try {
                const res = await fetch(`/app/plot-twist/${pollId}/vote`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, Accept: 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ option_id: optionId }),
                });
                if (res.ok) { window.location.reload(); }
            } finally { this.busy = false; }
        }
     }">

    @foreach ($optOutPolls as $named)
        <div class="uj-pt-optout" data-plot-twist-optout="{{ $named->id }}">
            <span>🙋 Next week's question names you: "{{ $named->question }}". You can sit this one out before it opens {{ $named->opens_on->format('D j M') }}, no questions asked.</span>
            <form method="POST" action="{{ route('plot-twist.opt-out', $named->id) }}">
                @csrf
                <button type="submit" class="uj-btn-ghost">Opt out</button>
            </form>
        </div>
    @endforeach

    <div class="uj-card uj-pt-hero" data-poll="{{ $poll?->id }}">
        @unless ($plain)
            <span class="uj-pt-art-big" aria-hidden="true">🎲</span>
        @endunless

        @if (! $poll)
            <span class="uj-pt-k">{{ $plain ? 'Weekly poll' : 'PLOT TWIST' }}</span>
            <span class="uj-pt-q">No plot twist this week. Suggest one below.</span>
        @elseif ($revealed && $results)
            <span class="uj-pt-k">{{ $plain ? 'Weekly poll' : 'PLOT TWIST' }} · REVEALED {{ strtoupper($poll->reveals_at->format('D j M, g:i A')) }}</span>
            <span class="uj-pt-q">{{ $poll->question }}</span>
            @if ($results['total'] > 0)
                <div class="uj-pt-bars">
                    @foreach ($results['options'] as $row)
                        <div class="uj-pt-bar @if ($row['win']) win @endif">
                            <span>{{ $row['option']->label }}</span>
                            <span class="bar"><i style="--w:{{ $row['pct'] }}%"></i></span>
                            <span class="pct" data-poll-result="{{ $row['option']->id }}">{{ $row['pct'] }}%</span>
                        </div>
                    @endforeach
                </div>
                <span class="uj-pt-meta">{{ $results['total'] }} voted · nobody, not even the Director, can see who picked what · next poll opens Monday</span>
            @else
                <span class="uj-pt-meta">No votes this week.</span>
            @endif
        @elseif ($upcoming)
            <span class="uj-pt-k">{{ $plain ? 'Weekly poll' : 'NEXT PLOT TWIST' }} · OPENS {{ strtoupper($poll->opens_on->format('D j M')) }}</span>
            <span class="uj-pt-q">{{ $poll->question }}</span>
            <span class="uj-pt-meta">Voting opens {{ $poll->opens_on->format('l j M') }} and closes {{ $poll->reveals_at->format('l j M, g:i A') }}. Anonymous, as always.</span>
            <div class="uj-pt-opts" data-poll-upcoming>
                @foreach ($options as $option)
                    <span class="uj-pt-opt" aria-disabled="true" style="cursor:default;opacity:.7;"><span class="dot"></span>{{ $option->label }}</span>
                @endforeach
            </div>
        @elseif ($voted)
            <span class="uj-pt-k">{{ $plain ? 'Weekly poll' : 'THIS WEEK\'S PLOT TWIST' }}</span>
            <span class="uj-pt-q">{{ $poll->question }}</span>
            <div class="uj-pt-voted">✅ Vote in. Results land on the Notice board Friday at 3 PM.</div>
            <span>You voted</span>
        @else
            <span class="uj-pt-k">{{ $plain ? 'Weekly poll' : "THIS WEEK'S PLOT TWIST · CLOSES ".strtoupper($poll->reveals_at->format('D j M, g:i A')) }}</span>
            <span class="uj-pt-q">{{ $poll->question }}</span>
            <span class="uj-pt-meta">Anonymous — nobody, not even the Director, can see who picked what.</span>
            <div class="uj-pt-opts">
                @foreach ($options as $option)
                    <button type="button" class="uj-pt-opt" data-poll-option="{{ $option->id }}"
                            :disabled="busy" @click="vote({{ $poll->id }}, {{ $option->id }})">
                        <span class="dot"></span>{{ $option->label }}
                    </button>
                @endforeach
            </div>
        @endif
    </div>

    <div class="uj-card" style="padding:18px 20px;display:flex;flex-direction:column;gap:10px;" x-data="{ sent: false }">
        <b>Got a better question?</b>
        <form method="POST" action="{{ route('plot-twist.suggest') }}" @submit="sent = true" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;" x-show="!sent">
            @csrf
            <input type="text" name="text" placeholder="Your idea" maxlength="255" required style="flex:1;min-width:200px;">
            <select name="kind">
                <option value="fun">Fun</option>
                <option value="who">Who</option>
                <option value="social">Social</option>
            </select>
            <button type="submit" class="uj-btn-primary">Suggest</button>
        </form>
        <p x-show="sent" x-cloak>Sent to HR. Thanks.</p>
    </div>

    @if ($canPublish)
        <div class="uj-card" style="padding:18px 20px;display:flex;flex-direction:column;gap:10px;">
            <b>Publish next week's poll</b>
            @if ($suggestions->isNotEmpty())
                <div>
                    <small>Suggestions from the team:</small>
                    <ul>
                        @foreach ($suggestions as $s)
                            <li>{{ $s->text }} <small>({{ $s->kind }})</small></li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if ($templates->isNotEmpty())
                <div>
                    <small>Who-question templates:</small>
                    <ul>
                        @foreach ($templates as $t)
                            <li>{{ $t->text }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <form method="POST" action="{{ route('plot-twist.store') }}" style="display:flex;flex-direction:column;gap:10px;">
                @csrf
                <label>Question <input type="text" name="question" maxlength="255" required></label>
                <label>Kind
                    <select name="kind">
                        <option value="fun">Fun</option>
                        <option value="who">Who</option>
                        <option value="social">Social</option>
                    </select>
                </label>
                <label>Named person (Who only)
                    <select name="named_employee_id">
                        <option value="">—</option>
                        @foreach ($people as $person)
                            <option value="{{ $person->id }}">{{ $person->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Opens on (a Monday) <input type="date" name="opens_on" required></label>
                @for ($i = 0; $i < 6; $i++)
                    <input type="text" name="options[]" maxlength="120" placeholder="Option {{ $i + 1 }}" @if ($i < 2) required @endif>
                @endfor
                <button type="submit" class="uj-btn-primary">Publish</button>
            </form>
        </div>
    @endif
</div>
@endsection
