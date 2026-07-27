@extends('layouts.app')

@section('screen')
<div class="uj-card">
    <div class="uj-card-head">
        <h3 class="uj-card-title">TOT {{ $year }}</h3>
    </div>
    <div style="padding:18px 20px;">
        @foreach ($sessions as $session)
            <div style="padding:6px 0;border-bottom:1px solid var(--hairline-soft);">
                {{ $session->session_date->format('M d') }} ·
                {{ $session->presenter?->name ?? $session->presenter_name ?? 'Nobody assigned' }} ·
                {{ $session->title ?? 'Topic not set' }}
            </div>
        @endforeach
    </div>
</div>
@endsection
