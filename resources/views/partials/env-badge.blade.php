{{-- Environment chip — sits under the Amanahku logo on every non-production tier
     (local = "Development", staging = "Staging"). Never renders on production.
     Pass onDark => true on dark surfaces (the sidebar) for a muted low-contrast pill;
     default keeps the solid red-tint pill for light surfaces (the login page). --}}
@unless(app()->environment('production'))
    @php($__env = app()->environment() === 'local' ? 'Development' : ucfirst(app()->environment()))
    @php($__dark = $onDark ?? false)
    <span style="display:{{ $__dark ? 'flex' : 'inline-flex' }};align-items:center;gap:6px;margin-top:8px;
                 background:{{ $__dark ? 'transparent' : 'var(--red-tint,#fdecec)' }};color:{{ $__dark ? '#f0908f' : 'var(--red,#dc2626)' }};
                 font:600 10px/1 ui-sans-serif,system-ui,sans-serif;letter-spacing:.11em;text-transform:uppercase;
                 padding:5px 9px;border-radius:999px;border:1px solid {{ $__dark ? 'rgba(240,144,143,.35)' : 'currentColor' }};white-space:nowrap;">
        <span style="width:6px;height:6px;border-radius:50%;background:currentColor;"></span>{{ $__env }}
    </span>
@endunless
