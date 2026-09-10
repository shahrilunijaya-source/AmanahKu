{{-- One staff member's recent weeks, read-only. Fetched by the report screen's
     "This week" tab when somebody presses Open week, and swapped in beside the
     roster — the old link went to the *viewer's own* timesheet screen, which showed
     the wrong person's week and offered an edit form for it. --}}
<div class="uj-tr-panel">
    <div class="uj-tr-crumb">
        <button type="button" class="uj-tr-crumb-btn" @click="closePerson()">
            &larr; <span x-text="$store.ui.lang==='en' ? 'This week' : 'Minggu ini'">This week</span>
        </button>
        <span class="uj-tr-crumb-sep" aria-hidden="true">/</span>
        <span class="uj-tr-crumb-cur">{{ $person->display_name }}</span>
        @if ($person->positionBand?->title)
            <span class="uj-tr-crumb-share">{{ $person->positionBand->title }}</span>
        @endif
    </div>

    {{-- Server-rendered "Late submission" fallback (CR-03): the per-day status strip
         below is Alpine-driven (timesheet-weeks.blade.php), so a late day's badge only
         exists in the DOM after Alpine hydrates. This tiny screen-reader-only list keeps
         the same text present in plain markup for the last week's late days, matching
         what the strip renders once Alpine mounts. --}}
    @php $currentWeek = end($weeks) ?: null; @endphp
    @if ($currentWeek && ! empty($currentWeek['dayStatuses']))
        <ul class="uj-sr-only">
            @foreach ($currentWeek['dayStatuses'] as $iso => $day)
                @if ($day['late'])
                    <li>{{ \Illuminate\Support\Carbon::parse($iso)->format('D, j M') }} — Late submission</li>
                @endif
            @endforeach
        </ul>
    @endif

    @include('partials.timesheet-weeks', ['weeks' => $weeks, 'baseUrl' => null, 'manage' => ($canManage ?? false) ? ($manageEmployeeId ?? null) : null])
</div>
