{{--
    CR-30: the tenant's reaction picker, the same eight (or whatever HR keeps) on every
    surface. Retired reactions are not offered here; they still read in reaction-tally.

    $onPick  Alpine expression run on click, with KEY standing for the reaction key
             e.g. "react('KEY'); flyout = null"
    $mine    JS expression for the viewer's own keys on this item (default: mine)
--}}
@php
    $mine = $mine ?? 'mine';
@endphp
@foreach (\App\Models\Reaction::active() as $i => $r)
    <button type="button" class="uj-react-pick" data-reaction-pick="{{ $r->key }}" style="--d:{{ $i * 30 }}ms"
            @click="{{ str_replace('KEY', $r->key, $onPick) }}"
            :data-mine="({{ $mine }}).includes(@js($r->key)) ? '1' : null"
            title="{{ $r->label }}" aria-label="{{ $r->label }}">
        <span class="uj-react-ico" aria-hidden="true">{{ $r->icon }}</span><span class="uj-react-lbl">{{ $r->label }}</span>
    </button>
@endforeach
