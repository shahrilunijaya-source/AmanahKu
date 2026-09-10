{{-- Type-to-search person picker. A native <datalist> gives the search for free; the
     typed name is matched back to an id that goes into the hidden input the form posts.
     A typed name that matches nobody blocks submit with a plain message.

     $name        posted field name, e.g. employee_id or employee_ids[]
     $people      Collection of Employee-like rows: ->id plus ->display_name or ->name
     $selected    ?int preselected id
     $required    bool (default false)
     $placeholder string
     $disabledId  ?int a person who cannot be picked (shown greyed with a note)
     $disabledNote string shown beside that person
     $style       inline style for the visible input
     $class       class for the visible input
     $attrs       raw attributes for the hidden input (e.g. an Alpine :disabled) --}}
@php
    $psId = 'ps-'.uniqid();
    $label = fn ($p) => $p->display_name ?? $p->name ?? '';
    $selectedRow = isset($selected) && $selected ? collect($people)->first(fn ($p) => (int) $p->id === (int) $selected) : null;
@endphp
<input type="text" list="{{ $psId }}-list" autocomplete="off"
       class="{{ $class ?? '' }}" style="{{ $style ?? '' }}"
       value="{{ $selectedRow ? $label($selectedRow) : '' }}"
       placeholder="{{ $placeholder ?? 'Type a name' }}"
       @if ($required ?? false) required @endif
       data-person-select
       oninput="const l = document.getElementById('{{ $psId }}-list'); const o = [...l.options].find(o => o.value === this.value); const h = this.nextElementSibling.nextElementSibling; h.value = o ? o.dataset.id : ''; this.setCustomValidity(this.value && !o ? 'Pick a name from the list' : (o && o.dataset.blocked ? o.dataset.blocked : ''));" />
<datalist id="{{ $psId }}-list">
    @foreach ($people as $p)
        <option value="{{ $label($p) }}" data-id="{{ $p->id }}"
            @if (isset($disabledId) && (int) $p->id === (int) $disabledId) data-blocked="{{ $disabledNote ?? 'Not this month' }}" @endif>
            @if (isset($disabledId) && (int) $p->id === (int) $disabledId){{ $disabledNote ?? 'Not this month' }}@endif
        </option>
    @endforeach
</datalist>
<input type="hidden" name="{{ $name }}" value="{{ $selectedRow ? $selectedRow->id : '' }}" {!! $attrs ?? '' !!} />
