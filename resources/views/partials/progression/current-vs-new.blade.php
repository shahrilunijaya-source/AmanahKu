{{-- Worksy two-column layout: CURRENT (read-only) on the left, NEW (form fields) on the right. --}}
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:24px;">
    <div style="display:flex;flex-direction:column;gap:12px;">
        <div style="display:flex;align-items:center;gap:12px;"><span style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;">{!! $L('Current', 'Semasa') !!}</span><span style="flex:1;border-top:2px dashed var(--info);"></span></div>
        @include('partials.profile.employment-rows', ['p' => $selected, 'canSeeSalary' => $canSeeSalary, 'minCol' => '130px'])
    </div>
    <div style="display:flex;flex-direction:column;gap:12px;">
        <div style="display:flex;align-items:center;gap:12px;"><span style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;">{!! $L('New', 'Baharu') !!}</span><span style="flex:1;border-top:2px dashed var(--info);"></span></div>
        @include('partials.profile.employment-form-fields', ['e' => $selected, 'canSeeSalary' => $canSeeSalary])
    </div>
</div>
