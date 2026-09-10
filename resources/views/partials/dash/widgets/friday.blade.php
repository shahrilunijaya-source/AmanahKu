{{-- CR-29 Friday sign-off (S04 slot, S25 fills it): Friday 15:00 to Monday 09:00.
     The mood is anonymous (no identity anywhere in this markup); a shared win
     is the deliberate exception and carries the author's name. Only ONE of
     the sign-off prompt / done blocks is ever server-rendered — never both —
     so a fresh page load never carries the other state's markup (tiles, my
     win text) in the HTML even hidden. The live "tap once, done" swap after a
     successful POST is done by direct DOM replacement in signOff(), not by
     rendering both states and toggling visibility. --}}
@php
    /** @var array<string, mixed> $w */
    $plain = (bool) ($w['plain'] ?? false);
    $labels = $plain ? $w['tileLabelsPlain'] : $w['tileLabels'];
    $moodKeys = ['productive', 'chaotic', 'peaceful', 'survived'];
    $art = ['productive' => '🚀', 'chaotic' => '🌪️', 'peaceful' => '🫖', 'survived' => '🫠'];
    $resultLabel = fn (string $key) => ! $plain && $key === 'survived' ? $w['resultLabelSurvived'] : $labels[$key];
@endphp
<div class="uj-dw-body">
    <div class="uj-fr" x-data="fridaySignOff(@js(route('friday.signoff')))">
        <span class="uj-fr-k">{{ $plain ? 'Friday sign-off' : 'FRIDAY SIGN-OFF · CLOSES MON 9 AM' }}</span>

        @if ($w['voted'])
            <div data-friday-done>
                <div class="uj-fr-done">
                    @unless ($plain)
                        ✅
                    @endunless
                    <span><b>Signed off.</b> Company mood lands here at 5 PM once five people have answered.</span>
                </div>
                @if ($w['myWin'])
                    <div class="uj-fr-wins">
                        <div class="uj-fr-winrow" data-friday-my-win>
                            <span class="who">You</span>
                            <span>{{ $w['myWin']->text }}</span>
                            @unless ($w['myWin']->shared)
                                <span class="priv">private</span>
                            @endunless
                        </div>
                    </div>
                @endif
            </div>
        @else
            <div data-friday-signoff x-ref="frPrompt">
                <span class="uj-fr-q">This week was…</span>
                <div class="uj-fr-moods">
                    @foreach ($moodKeys as $key)
                        <button type="button" class="uj-fr-mood" :class="{ 'is-on': mood === '{{ $key }}' }"
                                data-mood="{{ $key }}" @click="mood = '{{ $key }}'">
                            @unless ($plain)
                                <span class="uj-fr-art" aria-hidden="true">{{ $art[$key] }}</span>
                            @endunless
                            {{ $labels[$key] }}
                        </button>
                    @endforeach
                </div>
                <div class="uj-fr-win">
                    <label>My win this week (optional, one line)
                        <input type="text" name="win" x-ref="frWin" maxlength="160" placeholder="Small counts. Big counts more.">
                    </label>
                </div>
                <div class="uj-fr-row">
                    <label class="uj-fr-share"><input type="checkbox" name="share" x-ref="frShare" checked> Share my win under my name</label>
                    <button type="button" class="uj-btn-primary" :disabled="busy || !mood" @click="signOff()">Sign off</button>
                </div>
                <span class="uj-fr-meta">Your mood is anonymous. Nobody, not even the Director, can see who picked what.</span>
            </div>
        @endif

        @if ($w['showMood'])
            <div>
                <span class="uj-fr-k">{{ $plain ? 'Company mood, Friday 5 PM' : 'COMPANY MOOD · FRI 5 PM' }}</span>
                <span class="uj-fr-q">{{ $plain ? 'This week was' : 'This week, Unijaya was…' }}</span>
                @php $topPct = max($w['percentages']); @endphp
                <div class="uj-fr-bars" data-friday-mood>
                    @foreach ($moodKeys as $key)
                        <div class="uj-fr-bar @if ($topPct > 0 && $w['percentages'][$key] === $topPct) win @endif">
                            <span>{{ $resultLabel($key) }}</span>
                            <span class="bar"><i style="--w:{{ $w['percentages'][$key] }}%"></i></span>
                            <span class="pct" data-mood-pct="{{ $key }}">{{ $w['percentages'][$key] }}%</span>
                        </div>
                    @endforeach
                </div>
                <span class="uj-fr-meta">{{ $w['total'] }} signed off · nobody can see who picked what</span>
            </div>
        @elseif ($w['afterFivePm'])
            <span class="uj-fr-meta">Company mood needs 5 sign-offs before it shows. {{ $w['total'] }} so far.</span>
        @endif

        @if ($w['afterFivePm'] && $w['sharedWins']->isNotEmpty())
            <div class="uj-fr-wins">
                @foreach ($w['sharedWins'] as $win)
                    <div class="uj-fr-winrow" data-friday-win="{{ $win->id }}">
                        <span class="who">{{ $win->employee?->name ?? '—' }}</span>
                        <span>{{ $win->text }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
