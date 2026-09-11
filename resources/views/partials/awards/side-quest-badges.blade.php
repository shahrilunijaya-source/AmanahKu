{{--
    CR-26: one pill per Side Quest badge still inside its 30-day window, shown
    next to the CR-14b award badges on both the slim public card and the full
    profile (own /app/profile and anyone's /app/profile?emp=). Badges never
    count toward anything — this partial only reads side_quest_badges, it never
    touches award_results.

    $questBadges  Collection<object{quest_id:int,title:string,expires_at:string}>
--}}
@php $plain = (bool) \App\Support\DashboardPrefs::forUser(auth()->user()?->dashboard_prefs)['plain']; @endphp
@if (($questBadges ?? collect())->isNotEmpty())
    <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:6px;justify-content:center;">
        @foreach ($questBadges as $badge)
            @php $until = \Illuminate\Support\Carbon::parse($badge->expires_at)->format('j M'); @endphp
            <span class="uj-sq-badge" data-quest-badge="{{ $badge->quest_id }}" title="Side Quest, until {{ $until }}">
                @unless ($plain)🏷️ @endunless{{ $badge->title }} <small>· until {{ $until }}</small>
            </span>
        @endforeach
    </div>
@endif
