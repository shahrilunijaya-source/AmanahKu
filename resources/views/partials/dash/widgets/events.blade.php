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
                        {{-- QA F9 (scope 6): a photo strip once the event is over; text-only under Keep it Plain. --}}
                        @if ($w['plain'] ?? false)
                            <span class="s">{{ count($w['photos']) }} {{ \Illuminate\Support\Str::plural('photo', count($w['photos'])) }}</span>
                        @else
                            <span class="s" style="display:flex;gap:4px;margin-top:4px;">
                                @foreach ($w['photos'] as $photo)
                                    <img src="{{ route('events.photos.show', $photo) }}" alt="{{ $photo->caption }}" style="width:44px;height:44px;object-fit:cover;border-radius:6px;">
                                @endforeach
                            </span>
                        @endif
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
