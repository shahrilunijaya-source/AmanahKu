<div class="uj-dw-body">
    @forelse ($w['rows'] ?? [] as $r)
        @if (($r['kind'] ?? null) === 'plot_twist')
            <div class="uj-dw-notice" data-plot-twist="{{ $r['poll_id'] }}">
                <span class="when">Fri<br>3 PM</span>
                <span class="txt">
                    <span class="t">
                        @unless ($r['plain'])
                            <span class="uj-pt-art" aria-hidden="true">🎲</span>
                        @endunless
                        {{ $r['question'] }}
                    </span>
                    <span class="s">{{ $r['plain'] ? 'Weekly poll' : 'Plot twist' }} · {{ $r['total'] }} voted · nobody can see who picked what</span>
                </span>
                <span class="tag">{{ $r['plain'] ? 'Weekly poll' : 'PLOT TWIST' }}</span>
                <div class="uj-pt-bars">
                    @if ($r['total'] > 0)
                        @foreach ($r['options'] as $opt)
                            <div class="uj-pt-bar @if ($opt['win']) win @endif">
                                <span>{{ $opt['label'] }}</span>
                                <span class="bar"><i style="--w:{{ $opt['pct'] }}%"></i></span>
                                <span class="pct" data-poll-result="{{ $opt['option_id'] }}">{{ $opt['pct'] }}%</span>
                            </div>
                        @endforeach
                    @else
                        <span class="s">No votes this week.</span>
                    @endif
                </div>
            </div>
        @else
            <div class="uj-dw-notice">
                <span class="when">{{ $r['meta'] ?? '' }}</span>
                <span class="txt">
                    <span class="t">{{ $r['title'] ?? '' }}</span>
                    <span class="s">{{ \Illuminate\Support\Str::limit((string) ($r['sub'] ?? ''), 120) }}</span>
                </span>
                @if (! empty($r['flag']))
                    <span class="tag" data-req>{{ $r['flag'] }}</span>
                @elseif (! empty($r['tag']))
                    <span class="tag">{{ $r['tag'] }}</span>
                @endif
            </div>
        @endif
    @empty
        <p class="uj-dw-empty" x-text="$store.ui.lang==='en'
            ? 'No announcements yet.'
            : 'Tiada pengumuman lagi.'">No announcements yet.</p>
    @endforelse
</div>
