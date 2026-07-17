{{-- Small person pill: avatar initials + name + position. Expects $p (Employee). --}}
@php $p = $p ?? null; @endphp
@if ($p)
    <span style="display:inline-flex;align-items:center;gap:8px;background:var(--surface,#fff);border:1px solid var(--hairline);border-radius:999px;padding:3px 12px 3px 3px;">
        <span style="width:26px;height:26px;border-radius:50%;background:{{ $p->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:600;flex-shrink:0;">{{ $p->initials }}</span>
        <span style="display:flex;flex-direction:column;line-height:1.25;min-width:0;">
            <span style="font-size:12.5px;font-weight:600;color:var(--ink);white-space:nowrap;">{{ $p->name }}</span>
            @if ($p->position)<span style="font-size:10.5px;color:var(--muted);white-space:nowrap;">{{ $p->position }}</span>@endif
        </span>
    </span>
@endif
