{{-- Work: work details, work location (primary site + allowed clock-in sites), assets.
     Expects $p, $canEditWork, $workSites, $fs, $aIcon, $aSc. --}}
@php
    $L = fn ($en, $ms) => '<span x-text="'.e("\$store.ui.lang==='en' ? ".json_encode($en).' : '.json_encode($ms)).'">'.e($en).'</span>';
    $lbl = 'display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;';
    $canEdit = $canEditWork ?? false;
    $v = fn ($x) => filled($x) ? $x : '—';
    $d = fn ($x) => $x?->format('d/m/Y') ?? '—';
    $arr = ['office' => 'Office', 'client' => 'Client site', 'wfh' => 'Work from home', 'hybrid' => 'Hybrid'];
    $site = $p->workSite;
    $schedule = ($arr[$p->work_arrangement] ?? '—').($site && $site->work_start ? ' · '.substr($site->work_start, 0, 5).' – '.substr((string) $site->work_end, 0, 5) : '');
    $allowedIds = $p->allowedWorkSites->pluck('id')->all();
    $form = session('form');
    $sections = [
        ['Work Details', 'Butiran Kerja', [
            ['Employee ID', 'ID Pekerja', $v($p->staff_id)], ['Attendance ID', 'ID Kehadiran', $v($p->attendance_id)],
            ['Work Email', 'E-mel Kerja', $v($p->email)], ['Work Phone', 'Telefon Kerja', $v($p->work_phone)],
            ['Schedule', 'Jadual', $schedule], ['Benefit Start Date', 'Tarikh Mula Faedah', $d($p->benefit_start_at ?? $p->confirmed_at)],
        ]],
        ['Work Location', 'Lokasi Kerja', [
            ['Primary Work Location', 'Lokasi Kerja Utama', $site?->name ?? '—'],
            ['Allowed clock-in sites', 'Lokasi daftar masuk dibenarkan', $p->allowedWorkSites->isEmpty() ? 'Any configured site' : $p->allowedWorkSites->pluck('name')->join(', ')],
        ]],
    ];
@endphp

@if ($canEdit)
    <div style="display:flex;justify-content:flex-end;"><button type="button" @click="editWork = true" class="uj-btn-ghost" style="height:32px;padding:0 14px;font-size:12.5px;">{!! $L('Edit', 'Sunting') !!}</button></div>
@endif

@foreach ($sections as [$en, $ms, $rows])
    <div>
        <div class="uj-section-head" style="margin-bottom:12px;">{!! $L($en, $ms) !!}</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px 32px;">
            @foreach ($rows as [$ren, $rms, $val])
                <div><div style="font-size:11px;color:var(--muted);margin-bottom:2px;">{!! $L($ren, $rms) !!}</div><div style="font-size:13px;color:var(--ink);">{{ $val }}</div></div>
            @endforeach
        </div>
    </div>
@endforeach

<div>
    <div class="uj-section-head" style="margin-bottom:10px;">{!! $L('Assets', 'Aset') !!}</div>
    @forelse ($p->assets as $a)
        <div class="uj-card" x-data="{ open: {{ ($form === 'asset' && old('_asset') == $a->id) ? 'true' : 'false' }} }" style="padding:12px 14px;margin-bottom:8px;">
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <span style="font-size:18px;">{{ $aIcon[$a->category] ?? '📦' }}</span>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $a->name }}</div>
                    <div style="font-size:11.5px;color:var(--muted);">{{ ucfirst($a->category) }}@if ($a->serial) · {{ $a->serial }}@endif · {!! $L('Issued', 'Dikeluarkan') !!} {{ $d($a->assigned_at) }}@if ($a->returned_at) · {!! $L('Returned', 'Dipulangkan') !!} {{ $d($a->returned_at) }}@endif @if ($a->reference_no) · Ref {{ $a->reference_no }}@endif</div>
                    @if ($a->remark)<div style="font-size:12px;color:var(--body);margin-top:2px;">{{ $a->remark }}</div>@endif
                </div>
                <span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:{{ $aSc[$a->status] ?? 'var(--muted)' }};white-space:nowrap;"><span style="width:8px;height:8px;border-radius:50%;background:{{ $aSc[$a->status] ?? 'var(--muted)' }};"></span>{{ ucfirst($a->status) }}</span>
                @if ($canEdit)<button type="button" @click="open = !open" class="uj-btn-ghost" style="height:28px;padding:0 10px;font-size:12px;">{!! $L('Edit', 'Sunting') !!}</button>@endif
            </div>
            @if ($canEdit)
                <form x-show="open" x-cloak method="post" action="{{ route('assets.details', $a) }}" style="margin-top:12px;display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px 14px;align-items:end;">
                    @csrf
                    <input type="hidden" name="_asset" value="{{ $a->id }}" />
                    @if ($form === 'asset' && old('_asset') == $a->id && $errors->any())<div style="grid-column:1/-1;background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;">{{ $errors->first() }}</div>@endif
                    <div><label style="{{ $lbl }}">{!! $L('Returned on', 'Tarikh pulang') !!}</label><input type="date" name="returned_at" value="{{ old('_asset') == $a->id ? old('returned_at') : $a->returned_at?->toDateString() }}" style="{{ $fs }}" /></div>
                    <div><label style="{{ $lbl }}">{!! $L('Reference No', 'No. Rujukan') !!}</label><input name="reference_no" maxlength="80" value="{{ old('_asset') == $a->id ? old('reference_no') : $a->reference_no }}" style="{{ $fs }}" /></div>
                    <div style="grid-column:1/-1;"><label style="{{ $lbl }}">{!! $L('Remark', 'Catatan') !!}</label><input name="remark" maxlength="500" value="{{ old('_asset') == $a->id ? old('remark') : $a->remark }}" style="{{ $fs }}" /></div>
                    <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:13px;justify-self:start;">{!! $L('Save', 'Simpan') !!}</button>
                </form>
            @endif
        </div>
    @empty
        <p style="font-size:12.5px;color:var(--muted);margin:0;">{!! $L('No assets assigned to this person.', 'Tiada aset ditugaskan kepada orang ini.') !!}</p>
    @endforelse
</div>

@if ($canEdit)
    <template x-teleport="body">
    <div x-show="editWork" x-cloak @click.self="editWork = false" @keydown.escape.window="editWork = false"
         style="position:fixed;inset:0;z-index:120;display:flex;padding:40px 16px;background:rgba(18,18,30,.42);overflow-y:auto;">
        <form method="post" action="{{ route('employees.work.update', $p) }}" class="uj-card" style="width:100%;max-width:640px;margin:auto;padding:20px;display:flex;flex-direction:column;gap:14px;max-height:calc(100vh - 80px);overflow-y:auto;">
            @csrf
            <div style="font-size:13px;font-weight:600;color:var(--ink);">{!! $L('Edit work details', 'Sunting butiran kerja') !!} · {{ $p->name }}</div>
            @if ($errors->any() && $form === 'work')<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;">{{ $errors->first() }}</div>@endif
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px 16px;">
                <div><label style="{{ $lbl }}">{!! $L('Attendance ID', 'ID Kehadiran') !!}</label><input name="attendance_id" maxlength="40" value="{{ old('attendance_id', $p->attendance_id) }}" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('Work Phone', 'Telefon Kerja') !!}</label><input name="work_phone" maxlength="40" value="{{ old('work_phone', $p->work_phone) }}" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('Benefit Start Date', 'Tarikh Mula Faedah') !!}</label><input type="date" name="benefit_start_at" value="{{ old('benefit_start_at', ($p->benefit_start_at ?? $p->confirmed_at)?->toDateString()) }}" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('Primary Work Location', 'Lokasi Kerja Utama') !!}</label>
                    <select name="work_site_id" style="{{ $fs }}"><option value="">—</option>@foreach ($workSites as $s)<option value="{{ $s->id }}" @selected(old('work_site_id', $p->work_site_id) == $s->id)>{{ $s->name }}</option>@endforeach</select></div>
            </div>
            <div>
                <label style="{{ $lbl }}">{!! $L('Allowed clock-in sites (none ticked = any configured site)', 'Lokasi daftar masuk dibenarkan (tiada = mana-mana lokasi)') !!}</label>
                <input type="hidden" name="allowed_work_sites" value="" />
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:6px 14px;font-size:12.5px;color:var(--body);">
                    @foreach ($workSites as $s)
                        <label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" name="allowed_work_sites[]" value="{{ $s->id }}" @checked(in_array($s->id, (array) old('allowed_work_sites', $allowedIds)))> {{ $s->name }}</label>
                    @endforeach
                </div>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" @click="editWork = false" class="uj-btn-ghost" style="height:40px;padding:0 16px;font-size:13px;">{!! $L('Cancel', 'Batal') !!}</button>
                <button type="submit" class="uj-btn-primary" style="height:40px;padding:0 18px;font-size:13px;">{!! $L('Save changes', 'Simpan perubahan') !!}</button>
            </div>
        </form>
    </div>
    </template>
@endif
