{{-- One event's description with its @mentions marked up, shared by the upcoming cards
     and the past rows. Params: $e (CompanyEvent), $mentionNames (Employee collection
     keyed by id), $viewerId (?int), $margin (CSS margin for the paragraph). --}}
@if ($e->description)
    @php
        // Escaped first, then the known @names are wrapped — a description can
        // never inject markup, only the names this event actually tagged get marked.
        $desc = e($e->description);
        foreach ($e->taggedIds() as $taggedId) {
            $person = $mentionNames->get($taggedId);
            if (! $person) {
                continue;
            }
            $handle = '@'.e($person->display_name);
            $desc = str_replace($handle, '<span class="ext-mention"'.($taggedId === $viewerId ? ' data-me' : '').'>'.$handle.'</span>', $desc);
        }
    @endphp
    <p style="font-size:13px;color:var(--muted);margin:{{ $margin ?? '0 0 12px' }};white-space:pre-line;">{!! $desc !!}</p>
@endif
