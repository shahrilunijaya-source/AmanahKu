{{-- One family member form. $m null = add; $relations = the relation values this form may pick.
     Inherits $L, $lbl, $relL, $fs from family-tab. --}}
@php
    use App\Support\PersonalOptions;
    $isMine = session('form') === 'family' && ($m ? old('_member') == $m->id : ! old('_member') && in_array(old('relation'), $relations, true));
    $o = fn (string $k, $default = null) => $isMine ? old($k, $default) : ($m?->{$k} ?? $default);
    $od = fn (string $k) => $isMine ? old($k) : $m?->{$k}?->toDateString();
    $rel = $o('relation', $relations[0] ?? 'dependent');
    $opt = function (array $options, $current, bool $ucfirst = false) {
        $h = '<option value="">—</option>';
        foreach ($options as $v) {
            $h .= '<option value="'.e($v).'"'.($current === $v ? ' selected' : '').'>'.e($ucfirst ? ucfirst($v) : $v).'</option>';
        }
        return $h;
    };
@endphp
<form method="post" action="{{ $action }}" style="display:flex;flex-direction:column;gap:10px;" x-data="{ rel: @js($rel) }">
    @csrf
    @if ($m)<input type="hidden" name="_member" value="{{ $m->id }}" />@endif
    @if ($isMine && $errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;">{{ $errors->first() }}</div>@endif
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px 14px;">
        <div><label style="{{ $lbl }}">{!! $L('Relation', 'Hubungan') !!}</label><select name="relation" x-model="rel" style="{{ $fs }}">@foreach ($relations as $r)<option value="{{ $r }}">{{ $relL[$r][0] }}</option>@endforeach</select></div>
        <div><label style="{{ $lbl }}">{!! $L('Name', 'Nama') !!} *</label><input name="name" required value="{{ $o('name') }}" maxlength="160" style="{{ $fs }}" /></div>
        <div><label style="{{ $lbl }}">{!! $L('Phone', 'Telefon') !!}</label><input name="phone" value="{{ $o('phone') }}" maxlength="40" style="{{ $fs }}" /></div>
        <div><label style="{{ $lbl }}">{!! $L('Birth Date', 'Tarikh Lahir') !!}</label><input type="date" name="date_of_birth" value="{{ $od('date_of_birth') }}" style="{{ $fs }}" /></div>
        <div><label style="{{ $lbl }}">{!! $L('NRIC', 'No. K/P') !!}</label><input name="nric" value="{{ $o('nric') }}" maxlength="40" style="{{ $fs }}" /></div>
        <div><label style="{{ $lbl }}">{!! $L('Gender', 'Jantina') !!}</label><select name="gender" style="{{ $fs }}">{!! $opt(['male', 'female'], $o('gender'), true) !!}</select></div>
        <div><label style="{{ $lbl }}">{!! $L('Nationality', 'Kewarganegaraan') !!}</label><select name="nationality" style="{{ $fs }}">{!! $opt(PersonalOptions::NATIONALITIES, $o('nationality')) !!}</select></div>
        <div><label style="{{ $lbl }}">{!! $L('Occupation', 'Pekerjaan') !!}</label><select name="occupation" style="{{ $fs }}">{!! $opt(PersonalOptions::OCCUPATIONS, $o('occupation'), true) !!}</select></div>
        <div x-show="rel === 'spouse'"><label style="{{ $lbl }}">{!! $L('Employer', 'Majikan') !!}</label><input name="employer_name" value="{{ $o('employer_name') }}" maxlength="160" style="{{ $fs }}" /></div>
        <div x-show="rel === 'spouse'"><label style="{{ $lbl }}">{!! $L('Marriage Date', 'Tarikh Perkahwinan') !!}</label><input type="date" name="marriage_date" value="{{ $od('marriage_date') }}" style="{{ $fs }}" /></div>
        <div x-show="rel === 'child'"><label style="{{ $lbl }}">{!! $L('Education', 'Pendidikan') !!}</label><select name="education" style="{{ $fs }}">{!! $opt(PersonalOptions::EDUCATION, $o('education')) !!}</select></div>
        <div style="grid-column:1/-1;"><label style="{{ $lbl }}">{!! $L('Address', 'Alamat') !!}</label><input name="address" value="{{ $o('address') }}" maxlength="255" style="{{ $fs }}" /></div>
        <div style="grid-column:1/-1;"><label style="{{ $lbl }}">{!! $L('Remark', 'Catatan') !!}</label><input name="remark" value="{{ $o('remark') }}" maxlength="2000" style="{{ $fs }}" /></div>
        <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--body);"><input type="hidden" name="deceased" value="0" /><input type="checkbox" name="deceased" value="1" @checked((bool) $o('deceased', false)) /> {!! $L('Deceased', 'Meninggal dunia') !!}</label>
    </div>
    <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:13px;align-self:flex-start;">{!! $L('Save', 'Simpan') !!}</button>
</form>
