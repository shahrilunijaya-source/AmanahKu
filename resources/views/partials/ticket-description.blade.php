{{--
    Renders a ticket's description. Bug/Idea tickets store a structured, JSON-encoded
    description (see Ticket::structuredDescription()) — this shows each field as a
    labeled section. Everything else (older tickets included) falls back to the
    plain free-text render.
--}}
@php $structured = $t->structuredDescription(); @endphp
@if ($structured)
    <div style="margin-top:8px;display:flex;flex-direction:column;gap:8px;">
        @foreach ($structured as $field)
            <div>
                <div style="font-size:10.5px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:var(--muted);"
                     x-text="$store.ui.lang==='en' ? @js($field['label']['en']) : @js($field['label']['ms'])">{{ $field['label']['en'] }}</div>
                <div class="uj-markdown">{!! \Illuminate\Support\Str::markdown($field['value'], ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>
            </div>
        @endforeach
    </div>
@else
    <div class="uj-markdown" style="margin-top:8px;">{!! \Illuminate\Support\Str::markdown($t->description, ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>
@endif
