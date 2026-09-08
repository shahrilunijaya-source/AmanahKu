@extends('layouts.app')

@section('screen')

<div class="uj-lv">
    <h1 style="font-size:19px;font-weight:600;color:var(--ink);margin:0 0 14px;"
        x-text="$store.ui.lang==='en' ? 'Management exceptions' : 'Pengecualian pengurusan'">Management exceptions</h1>

    <div class="uj-card">
        @include('partials.dash.management-panels', ['mgmt' => $mgmt])
    </div>
</div>

@endsection
