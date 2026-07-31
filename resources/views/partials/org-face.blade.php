@php
    /**
     * The face inside a seat: the person's photo, or their initials on their own colour.
     *
     * @var string $p  An Alpine expression resolving to a person from the chart payload.
     */
@endphp
<span class="oc-av" :style="{{ $p }}.photo ? '' : `background:${ {{ $p }}.color }`" aria-hidden="true">
    <template x-if="{{ $p }}.photo">
        <img :src="{{ $p }}.photo" alt="">
    </template>
    <template x-if="! {{ $p }}.photo">
        <span x-text="{{ $p }}.initials"></span>
    </template>
</span>
