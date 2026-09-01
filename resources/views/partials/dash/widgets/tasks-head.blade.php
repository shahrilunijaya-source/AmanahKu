@php $n = collect($w['groups'] ?? [])->sum('count'); @endphp
@if ($n > 0)
    <span class="uj-dw-count" data-hot>{{ $n }}</span>
@endif
