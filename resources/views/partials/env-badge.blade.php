{{-- Environment chip — sits under the Amanahku logo on every non-production tier
     (local = "Development", staging = "Staging"). Never renders on production. --}}
@unless(app()->environment('production'))
    @php($__env = app()->environment() === 'local' ? 'Development' : ucfirst(app()->environment()))
    <span style="display:inline-flex;align-items:center;gap:6px;margin-top:8px;
                 background:var(--red-tint,#fdecec);color:var(--red,#dc2626);
                 font:600 10px/1 ui-sans-serif,system-ui,sans-serif;letter-spacing:.11em;text-transform:uppercase;
                 padding:5px 9px;border-radius:999px;border:1px solid currentColor;white-space:nowrap;">
        <span style="width:6px;height:6px;border-radius:50%;background:currentColor;"></span>{{ $__env }}
    </span>
@endunless
