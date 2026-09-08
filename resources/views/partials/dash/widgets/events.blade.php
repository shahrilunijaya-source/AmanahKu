{{-- Events dashboard widget (CR-11): the nearest upcoming or just-past company event.
     Absent entirely outside that window — see BuildsDashboardWidgets::dashboardData(). --}}
<div class="uj-dw-body">
    @if ($w['event'] ?? null)
        <a href="{{ route('events.show', $w['event']) }}" class="uj-dw-notice">
            <span class="txt">
                <span class="t">{{ $w['event']->title }}</span>
                @if ($w['isPast'] ?? false)
                    <span class="s">
                        @if ($w['lessonLine'])
                            {{ \Illuminate\Support\Str::limit($w['lessonLine'], 100) }}
                        @else
                            {{ $w['date'] ?? '' }}
                        @endif
                    </span>
                    @if (! empty($w['photos']))
                        <span class="s">{{ count($w['photos']) }} {{ \Illuminate\Support\Str::plural('photo', count($w['photos'])) }}</span>
                    @endif
                @else
                    <span class="s">{{ $w['date'] ?? '' }} — {{ implode(', ', $w['attendees'] ?? []) }}</span>
                @endif
            </span>
        </a>
    @else
        <p class="uj-dw-empty">No event coming up or just wrapped.</p>
    @endif
</div>
