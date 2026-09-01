{{-- Current month summary: six figures, colour-coded by what they mean.
     The tiles carry the dashboard's one authored entrance (CSS stagger).
     Both views are built server-side and swapped here, so the toggle is instant
     and costs the extra queries only for a viewer who has staff at all. --}}
<div class="uj-dw-body">
    <div class="uj-dw-tiles" x-show="scope === 'me'">
        @foreach ($w['tiles'] ?? [] as $t)
            @include('partials.dash.widgets.tile', ['t' => $t])
        @endforeach
    </div>
    @if (! empty($w['staffTiles']))
        <div class="uj-dw-tiles" x-show="scope === 'staff'" x-cloak>
            @foreach ($w['staffTiles'] as $t)
                @include('partials.dash.widgets.tile', ['t' => $t])
            @endforeach
        </div>
    @endif
</div>
