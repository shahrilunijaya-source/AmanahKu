{{-- Full-width cover backdrop at the top of the main area. Sharp copy holds the top,
     the blurred copy carries the colour down, both are gone before the box ends, so the
     picture dissolves into the wallpaper or canvas. Purely decorative. --}}
@php $coverBg = $employee->coverBackground(); @endphp
<div class="uj-cover-hero" aria-hidden="true">
    <div class="uj-cover-blur" style="background-image:{{ $coverBg }};"></div>
    <div class="uj-cover-img" style="background-image:{{ $coverBg }};"></div>
</div>
