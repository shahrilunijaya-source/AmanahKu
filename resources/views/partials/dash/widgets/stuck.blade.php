{{-- Requests whose submitter has nobody on the org chart above them, so no
     approval queue can ever pick them up. One row per person, not per request. --}}
<div class="uj-dw-body">
    @forelse ($w['rows'] ?? [] as $it)
        @include('partials.dash.row', ['it' => $it, 'index' => 0, 'anim' => false])
    @empty
        <p class="uj-dw-empty" x-text="$store.ui.lang==='en'
            ? 'Every submitted request has someone to verify it.'
            : 'Setiap permohonan ada pengesah.'">Every submitted request has someone to verify it.</p>
    @endforelse
</div>
