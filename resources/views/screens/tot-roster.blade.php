@extends('layouts.app')

@section('screen')
    <div class="uj-card" style="padding:20px;">
        <h2 style="font-size:18px;font-weight:600;color:var(--ink);margin:0;">{{ $year }}</h2>
        <p class="tot-note" style="margin-top:8px;">
            @foreach ($assignableEmployees as $person)
                <span>{{ $person->display_name }}</span>@if (! $loop->last) · @endif
            @endforeach
        </p>
    </div>
@endsection
