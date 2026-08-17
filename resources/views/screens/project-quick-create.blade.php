@extends('layouts.app')

@section('screen')
<div style="max-width:560px;margin:0 auto;padding:32px 16px;">
    <div style="font-size:12px;letter-spacing:0.06em;text-transform:uppercase;color:var(--muted);margin-bottom:8px;">Step 1 of 2</div>
    <h1 style="font-size:22px;font-weight:600;color:var(--ink);margin:0 0 8px;">Let's create your project</h1>
    <p style="font-size:14px;color:var(--muted);margin:0 0 28px;max-width:44ch;">This creates the basic record. You'll link Track to it, then fill in the rest of the detail over there.</p>

    <form method="post" action="{{ route('project-quick-create.store') }}" style="display:flex;flex-direction:column;gap:20px;">
        @csrf
        <div>
            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;">Project code <span style="font-weight:400;color:var(--muted);">(optional)</span></label>
            <input name="code" value="{{ old('code') }}" placeholder="KPT" style="width:100%;height:44px;padding:0 14px;border:1px solid var(--hairline);border-radius:9px;font-size:15px;outline:none;" />
            @error('code')<div style="font-size:12px;color:#b91c1c;margin-top:5px;">{{ $message }}</div>@enderror
        </div>
        <div>
            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;">Project name</label>
            <input name="name" required value="{{ old('name') }}" placeholder="KPT: RMS 2.0" style="width:100%;height:44px;padding:0 14px;border:1px solid var(--hairline);border-radius:9px;font-size:15px;outline:none;" />
            @error('name')<div style="font-size:12px;color:#b91c1c;margin-top:5px;">{{ $message }}</div>@enderror
        </div>
        @if ($categories->isNotEmpty())
            <div>
                <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:2px;">Category <span style="font-weight:400;color:var(--muted);">(optional)</span></label>
                <p style="font-size:12.5px;color:var(--muted);margin:0 0 10px;">Filters this project on staff timesheets — pick as many as apply.</p>
                <div class="uj-cat-chips" role="group" aria-label="Category"
                     x-data="{ selected: @js(array_map('strval', old('categories', []))) }">
                    @foreach ($categories as $cat)
                        <label class="uj-cat-chip" :class="{ 'is-on': selected.includes('{{ $cat->id }}') }">
                            <input type="checkbox" name="categories[]" value="{{ $cat->id }}" class="uj-sr-only" x-model="selected" />
                            <svg class="uj-cat-chip-tick" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                            {{ $cat->name }}
                        </label>
                    @endforeach
                </div>
            </div>
        @endif
        <div>
            <button type="submit" class="uj-btn-primary" style="height:44px;padding:0 22px;font-size:14px;">Create project</button>
        </div>
    </form>
</div>
@endsection
