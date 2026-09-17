{{-- One Experience record form. $type, $r (null = add), $action. Inherits $L, $lbl, $fs, $documents from experience-tab. --}}
@php
    use App\Support\ExperienceOptions;
    $isMine = session('form') === 'experience:'.$type && ($r ? old('_row') == $r->id : ! old('_row'));
    $o = fn (string $k, $default = null) => $isMine ? old($k, $default) : ($r?->{$k} ?? $default);
    $od = fn (string $k) => $isMine ? old($k) : $r?->{$k}?->toDateString();
    $in = fn (string $k, string $en, string $ms, string $kind = 'text', string $extra = '') => '<div><label style="'.$lbl.'">'.$L($en, $ms).'</label><input type="'.$kind.'" name="'.$k.'" value="'.e((string) ($kind === 'date' ? $od($k) : $o($k))).'" '.$extra.' style="'.$fs.'" /></div>';
    $sel = function (string $k, string $en, string $ms, array $options, bool $keyed) use ($L, $lbl, $fs, $o) {
        $h = '<div><label style="'.$lbl.'">'.$L($en, $ms).'</label><select name="'.$k.'" style="'.$fs.'"><option value="">—</option>';
        foreach ($options as $key => $label) {
            $val = $keyed ? (string) $key : $label;
            $h .= '<option value="'.e($val).'"'.((string) $o($k) === $val ? ' selected' : '').'>'.e($keyed ? $label : ucfirst($label)).'</option>';
        }
        return $h.'</select></div>';
    };
    $docs = fn () => $sel('document_id', 'Attachment (from Documents)', 'Lampiran (daripada Dokumen)', $documents->pluck('title', 'id')->all(), true);
@endphp
<form method="post" action="{{ $action }}" style="display:flex;flex-direction:column;gap:10px;">
    @csrf
    @if ($r)<input type="hidden" name="_row" value="{{ $r->id }}" />@endif
    @if ($isMine && $errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;">{{ $errors->first() }}</div>@endif
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px 14px;">
        @switch($type)
            @case('work')
                {!! $in('company', 'Company', 'Syarikat', 'text', 'required maxlength="160"') !!}
                {!! $in('industry', 'Industry', 'Industri', 'text', 'maxlength="120"') !!}
                {!! $in('joined_on', 'Joined on', 'Tarikh masuk', 'date') !!}
                {!! $in('joined_as', 'Joined as', 'Jawatan masuk', 'text', 'maxlength="120"') !!}
                {!! $in('resigned_on', 'Resigned on', 'Tarikh berhenti', 'date') !!}
                {!! $in('position_held', 'Last position held', 'Jawatan terakhir', 'text', 'maxlength="120"') !!}
                {!! $in('last_drawn_salary', 'Last drawn salary (RM)', 'Gaji terakhir (RM)', 'number', 'step="0.01" min="0"') !!}
                {!! $sel('salary_type', 'Salary type', 'Jenis gaji', ExperienceOptions::SALARY_TYPES, false) !!}
                <div style="grid-column:1/-1;">{!! $in('address', 'Address', 'Alamat', 'text', 'maxlength="255"') !!}</div>
                <div style="grid-column:1/-1;">{!! $in('reason_to_leave', 'Reason to leave', 'Sebab berhenti', 'text', 'maxlength="255"') !!}</div>
                @break
            @case('education')
                {!! $sel('qualification_type', 'Qualification', 'Kelayakan', ExperienceOptions::QUALIFICATIONS, true) !!}
                {!! $in('major', 'Major', 'Pengkhususan', 'text', 'maxlength="160"') !!}
                {!! $in('institute', 'Institute', 'Institusi', 'text', 'maxlength="160"') !!}
                {!! $in('from_year', 'From year', 'Dari tahun', 'number', 'min="1950" max="2100"') !!}
                {!! $in('to_year', 'To year', 'Hingga tahun', 'number', 'min="1950" max="2100"') !!}
                {!! $sel('honours', 'Honours', 'Kepujian', ExperienceOptions::HONOURS, true) !!}
                {!! $in('cgpa', 'CGPA', 'PNGK', 'number', 'step="0.01" min="0" max="4"') !!}
                {!! $docs() !!}
                <div style="grid-column:1/-1;">{!! $in('remark', 'Remark', 'Catatan', 'text', 'maxlength="500"') !!}</div>
                @break
            @case('certificate')
                {!! $in('name', 'Certificate', 'Sijil', 'text', 'required maxlength="160"') !!}
                {!! $in('category', 'Category', 'Kategori', 'text', 'maxlength="120"') !!}
                {!! $in('awarded_by', 'Awarded by', 'Dianugerahkan oleh', 'text', 'maxlength="160"') !!}
                {!! $in('awarded_on', 'Awarded on', 'Tarikh anugerah', 'date') !!}
                {!! $in('expires_on', 'Expires on', 'Tarikh tamat', 'date') !!}
                {!! $docs() !!}
                <div style="grid-column:1/-1;">{!! $in('remark', 'Remark', 'Catatan', 'text', 'maxlength="500"') !!}</div>
                @break
            @case('award')
                {!! $in('title', 'Title', 'Tajuk', 'text', 'required maxlength="160"') !!}
                {!! $in('year', 'Year', 'Tahun', 'number', 'min="1950" max="2100"') !!}
                {!! $docs() !!}
                <div style="grid-column:1/-1;">{!! $in('remark', 'Remark', 'Catatan', 'text', 'maxlength="500"') !!}</div>
                @break
            @case('language')
                {!! $in('language', 'Language', 'Bahasa', 'text', 'required maxlength="80"') !!}
                {!! $sel('speaking', 'Speaking', 'Pertuturan', ExperienceOptions::PROFICIENCY, false) !!}
                {!! $sel('reading', 'Reading', 'Bacaan', ExperienceOptions::PROFICIENCY, false) !!}
                {!! $sel('writing', 'Writing', 'Penulisan', ExperienceOptions::PROFICIENCY, false) !!}
                @break
        @endswitch
    </div>
    <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:13px;align-self:flex-start;">{!! $L('Save', 'Simpan') !!}</button>
</form>
