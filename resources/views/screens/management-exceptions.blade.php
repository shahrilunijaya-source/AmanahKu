@extends('layouts.app')

@section('screen')

{{-- CR-17: the full, uncapped lateness + overdue lists; the dashboard band peeks at
     the same partial. The page header already carries the title and subline. --}}
<div class="uj-lv">
    @include('partials.dash.management-panels', ['mgmt' => $mgmt, 'extraClass' => 'uj-mgmt--page'])
</div>

@endsection
