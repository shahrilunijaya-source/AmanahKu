{{-- The "needs you" dot on a sidebar row. $n is how many requests on that screen are
     waiting for this person to verify or approve (BuildsNav::navAttention). Silent when
     zero, so a row only ever grows a dot when there is something real behind it. --}}
@if (($n ?? 0) > 0)
    <span class="uj-nav-dot" aria-hidden="true"></span>
    <span class="uj-sr-only">{{ $n }} waiting for you</span>
@endif
