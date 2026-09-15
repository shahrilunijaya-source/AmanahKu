{{-- Experience: TP3 (director/HR), previous employment, education, certificates, awards, languages, training, skills.
     Expects $p, $canEditExperience, $canEditSalaryStructure, $openingFigures, $documents, $skills, $fs, $tSc, $tSl. --}}
@php
    use App\Support\ExperienceOptions;
    $L = fn ($en, $ms) => '<span x-text="'.e("\$store.ui.lang==='en' ? ".json_encode($en).' : '.json_encode($ms)).'">'.e($en).'</span>';
    $lbl = 'display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;';
    $canEdit = $canEditExperience ?? false;
    $form = (string) session('form');
    $d = fn ($v) => $v?->format('d/m/Y');
    // One-line summary per row type for the collapsed card.
    $summary = fn (string $type, $r) => match ($type) {
        'work' => trim(($r->position_held ?? $r->joined_as ?? '').' · '.($d($r->joined_on) ?? '?').' – '.($d($r->resigned_on) ?? 'present'), ' ·'),
        'education' => trim((ExperienceOptions::QUALIFICATIONS[$r->qualification_type] ?? '').($r->major ? ' in '.$r->major : '').' · '.($r->from_year ?? '?').' – '.($r->to_year ?? '?'), ' ·'),
        'certificate' => trim(($r->awarded_by ?? '').($r->awarded_on ? ' · '.$d($r->awarded_on) : '').($r->expires_on ? ' · expires '.$d($r->expires_on) : ''), ' ·'),
        'award' => (string) ($r->year ?? ''),
        'language' => 'S '.ucfirst($r->speaking ?? '-').' · R '.ucfirst($r->reading ?? '-').' · W '.ucfirst($r->writing ?? '-'),
    };
    $title = fn ($r) => $r->company ?? $r->institute ?? $r->name ?? $r->title ?? $r->language ?? '—';
@endphp

@if ($canEditSalaryStructure ?? false)
    {{-- TP3 / previous employment figures: one PayrollOpeningFigure per year, posted to payroll.opening (back() returns here). --}}
    <div style="display:flex;flex-direction:column;gap:10px;">
        <div class="uj-section-head">{!! $L('Previous Employment Figures (TP3)', 'Angka Pekerjaan Terdahulu (TP3)') !!}</div>
        @forelse ($openingFigures as $o)
            <div class="uj-card" x-data="{ open: false }" style="padding:12px 14px;">
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;font-size:12.5px;color:var(--body);">
                    <strong style="color:var(--ink);">{{ $o->year }}</strong>
                    <span>{!! $L('Gross', 'Kasar') !!} RM {{ number_format($o->gross, 2) }}</span><span>PCB RM {{ number_format($o->pcb_paid, 2) }}</span><span>EPF RM {{ number_format($o->epf, 2) }}</span><span>SOCSO RM {{ number_format($o->socso, 2) }}</span><span>EIS RM {{ number_format($o->eis, 2) }}</span><span>Zakat RM {{ number_format($o->zakat_paid, 2) }}</span>
                    <button type="button" @click="open = !open" class="uj-btn-ghost" style="margin-left:auto;height:28px;padding:0 10px;font-size:12px;">{!! $L('Edit', 'Sunting') !!}</button>
                </div>
                <div x-show="open" x-cloak style="margin-top:12px;">@include('partials.profile.opening-form', ['o' => $o])</div>
            </div>
        @empty
            <p style="font-size:12.5px;color:var(--muted);margin:0;">{!! $L('No record found', 'Tiada rekod') !!}</p>
        @endforelse
        <div x-data="{ add: false }">
            <button type="button" @click="add = !add" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;">+ {!! $L('Add year', 'Tambah tahun') !!}</button>
            <div x-show="add" x-cloak class="uj-card" style="margin-top:8px;padding:14px;">@include('partials.profile.opening-form', ['o' => null])</div>
        </div>
    </div>
@endif

@foreach (ExperienceOptions::TYPES as $type => [$class, $hen, $hms, $relation])
    @php $rows = $p->{$relation}; $addOpen = $form === 'experience:'.$type && ! old('_row'); @endphp
    <div style="display:flex;flex-direction:column;gap:10px;">
        <div class="uj-section-head">{!! $L($hen, $hms) !!}</div>
        @forelse ($rows as $r)
            <div class="uj-card" x-data="{ open: {{ ($form === 'experience:'.$type && old('_row') == $r->id) ? 'true' : 'false' }} }" style="padding:12px 14px;">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <span style="font-size:13px;font-weight:600;color:var(--ink);">{{ $title($r) }}</span>
                    <span style="font-size:12px;color:var(--muted);">{{ $summary($type, $r) }}</span>
                    @if (isset($r->document_id) && $r->document)<a href="{{ route('documents.download', $r->document) }}" style="font-size:12px;">📎 {{ $r->document->title }}</a>@endif
                    @if ($canEdit)<button type="button" @click="open = !open" class="uj-btn-ghost" style="margin-left:auto;height:28px;padding:0 10px;font-size:12px;">{!! $L('Edit', 'Sunting') !!}</button>@endif
                </div>
                @if ($canEdit)
                    <div x-show="open" x-cloak style="margin-top:12px;">
                        @include('partials.profile.experience-form', ['type' => $type, 'r' => $r, 'action' => route('employees.experience.update', [$type, $r->id])])
                        <form method="post" action="{{ route('employees.experience.destroy', [$type, $r->id]) }}" onsubmit="return confirm('Remove this record?')" style="margin-top:6px;">@csrf<button type="submit" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;color:var(--red);">{!! $L('Remove', 'Buang') !!}</button></form>
                    </div>
                @endif
            </div>
        @empty
            <p style="font-size:12.5px;color:var(--muted);margin:0;">{!! $L('No record found', 'Tiada rekod') !!}</p>
        @endforelse
        @if ($canEdit)
            <div x-data="{ add: {{ $addOpen ? 'true' : 'false' }} }">
                <button type="button" @click="add = !add" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;">+ {!! $L('Add', 'Tambah') !!}</button>
                <div x-show="add" x-cloak class="uj-card" style="margin-top:8px;padding:14px;">
                    @include('partials.profile.experience-form', ['type' => $type, 'r' => null, 'action' => route('employees.experience.store', [$p, $type])])
                </div>
            </div>
        @endif
    </div>
@endforeach

<div>
    <div class="uj-section-head" style="margin-bottom:10px;">{!! $L('Training', 'Latihan') !!}</div>
    @forelse ($p->trainingRecords as $r)
        @php $isOverdue = $r->status !== 'completed' && $r->due_at && $r->due_at->isPast(); @endphp
        <div class="uj-row" style="display:flex;align-items:center;gap:12px;padding:10px 0;border-top:1px solid var(--hairline-soft);">
            <div style="flex:1;min-width:0;"><div style="font-size:13px;color:var(--ink);font-weight:500;">{{ $r->course }}</div><div style="font-size:11.5px;color:var(--muted);">{{ $r->provider }}@if ($r->mandatory) · <span style="color:var(--red);font-weight:600;">Mandatory</span>@endif</div></div>
            <span style="font-size:12px;font-family:var(--font-mono);color:{{ $isOverdue ? 'var(--error)' : 'var(--muted)' }};white-space:nowrap;">{{ $r->due_at?->format('j M Y') ?? '—' }}{{ $isOverdue ? ' ⚠' : '' }}</span>
            <span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:{{ $tSc[$r->status] ?? 'var(--muted)' }};white-space:nowrap;"><span style="width:8px;height:8px;border-radius:50%;background:{{ $tSc[$r->status] ?? 'var(--muted)' }};"></span>{{ $tSl[$r->status] ?? ucfirst($r->status) }}</span>
        </div>
    @empty
        <p style="font-size:12.5px;color:var(--muted);margin:0;" x-text="$store.ui.lang==='en' ? 'No training records.' : 'Tiada rekod latihan.'">No training records.</p>
    @endforelse
</div>

<div>
    <div class="uj-section-head" style="margin-bottom:10px;">{!! $L('Skills', 'Kemahiran') !!}</div>
    @forelse ($skills ?? [] as $es)
        <div class="uj-row" style="display:flex;align-items:center;gap:10px;padding:10px 0;border-top:1px solid var(--hairline-soft);">
            <span style="flex-shrink:0;width:8px;height:8px;border-radius:50%;background:{{ $es->verified ? 'var(--success)' : 'var(--muted-soft)' }};"></span>
            <div style="flex:1;min-width:0;"><div style="font-size:13px;color:var(--ink);font-weight:500;">{{ $es->skill?->name ?? '—' }}</div><div style="font-size:11.5px;color:var(--muted);">{{ $es->level_label }}</div></div>
        </div>
    @empty
        <p style="font-size:12.5px;color:var(--muted);margin:0;">{!! $L('No skills recorded.', 'Tiada kemahiran direkodkan.') !!}</p>
    @endforelse
</div>
