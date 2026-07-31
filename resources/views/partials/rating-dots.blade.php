@php
    /** Compact 1–5 rating as filled/empty dots. Expects $rating (int), optional $max, $size. */
    $rating = (int) ($rating ?? 0);
    $max = (int) ($max ?? 5);
    $size = $size ?? 7;
@endphp
<span style="display:inline-flex;gap:3px;align-items:center;" aria-label="{{ $rating }}/{{ $max }}">
    @for ($i = 1; $i <= $max; $i++)
        <span style="width:{{ $size }}px;height:{{ $size }}px;border-radius:50%;background:{{ $i <= $rating ? 'var(--amber)' : 'var(--hairline)' }};"></span>
    @endfor
</span>
