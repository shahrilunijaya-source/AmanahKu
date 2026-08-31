{{-- Edit / Remove for one event, shared by the upcoming cards and the past rows — a
     past event still gets corrected (a wrong date, a dead link) long after the day.
     Edit is poster-only, Remove is privileged, matching EventController.
     Params: $e (CompanyEvent), $viewerId (?int), $privileged (bool).
     Relies on the postOpen/editEvent/external scope declared on the screen's outer
     x-data — Alpine child scopes inherit it, so the past card's own x-data is fine. --}}
@if ($e->created_by_employee_id === $viewerId)
    <button type="button" class="ext-del" @click="editEvent = {{ \Illuminate\Support\Js::from([
            'id' => $e->id,
            'title' => $e->title,
            'type' => $e->type,
            'host' => $e->host,
            'event_date' => $e->event_date->format('Y-m-d'),
            'start_time' => $e->start_time,
            'location' => $e->location,
            'venue_map_url' => $e->venue_map_url,
            'registration_url' => $e->registration_url,
            'description' => $e->description,
            'tagged' => $e->taggedIds(),
        ]) }}; external = {{ \Illuminate\Support\Js::from($e->isExternal()) }}; postOpen = true"
            x-text="$store.ui.lang==='en' ? 'Edit' : 'Sunting'">Edit</button>
@endif
@if ($privileged)
    <form method="post" action="{{ route('events.destroy', $e) }}" onsubmit="return confirm('{{ $e->title }}?')">
        @csrf
        <button type="submit" class="ext-del" x-text="$store.ui.lang==='en' ? 'Remove' : 'Buang'">Remove</button>
    </form>
@endif
