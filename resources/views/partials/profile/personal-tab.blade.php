{{-- Personal tab: Worksy personal information, contact, emergency contact and identification.
     Expects $p (Employee), $canEditPersonal, $canEditIdentity, $fs. --}}
@php
    use App\Support\PersonalOptions;
    $L = fn ($en, $ms) => '<span x-text="'.e("\$store.ui.lang==='en' ? ".json_encode($en).' : '.json_encode($ms)).'">'.e($en).'</span>';
    $d = fn ($v) => $v ? $v->format('d/m/Y') : '—';
    $v = fn ($x) => filled($x) ? $x : '—';
    $marital = PersonalOptions::MARITAL[$p->marital_status][0] ?? '—';
    $sections = [
        ['Personal Information', 'Maklumat Peribadi', [
            ['First Name', 'Nama Pertama', $v($p->first_name)], ['Last Name', 'Nama Akhir', $v($p->last_name)],
            ['Full Name per IC/Passport', 'Nama Penuh mengikut IC/Pasport', $v($p->full_name_ic)], ['Known Name', 'Nama Panggilan', $v($p->nickname)],
            ['Religion', 'Agama', $v($p->religion)], ['Birth Date', 'Tarikh Lahir', $d($p->date_of_birth)],
            ['Gender', 'Jantina', $p->gender ? ucfirst($p->gender) : '—'], ['Marital Status', 'Status Perkahwinan', $marital],
            ['Race', 'Bangsa', $v($p->race)], ['Nationality', 'Kewarganegaraan', $v($p->nationality)], ['Blood Type', 'Jenis Darah', $v($p->blood_type)],
        ]],
        ['Contact & Address', 'Hubungan & Alamat', [
            ['Phone', 'Telefon', $v($p->phone)], ['Personal Email', 'E-mel Peribadi', $v($p->personal_email)],
            ['Address Line 1', 'Alamat Baris 1', $v($p->address)], ['Address Line 2', 'Alamat Baris 2', $v($p->address_2)],
            ['City', 'Bandar', $v($p->city)], ['State', 'Negeri', $v($p->state)], ['Postcode', 'Poskod', $v($p->postcode)], ['Country', 'Negara', $v($p->country)],
        ]],
        ['Emergency Contact', 'Hubungan Kecemasan', [
            ['Name', 'Nama', $v($p->emergency_contact_name)], ['Phone', 'Telefon', $v($p->emergency_contact_phone)], ['Relationship', 'Hubungan', $v($p->emergency_contact_relationship)],
        ]],
        ['Identification', 'Pengenalan', [
            ['NRIC', 'No. K/P', $v($p->nric)], ['Passport No', 'No. Pasport', $v($p->passport_no)], ['Passport Expiry', 'Tamat Pasport', $d($p->passport_expiry)],
            ['Permit No', 'No. Permit', $v($p->permit_no)], ['Permit Expiry', 'Tamat Permit', $d($p->permit_expiry)],
        ]],
    ];
    $lbl = 'display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;';
    $grid = 'display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px 16px;';
    $old = fn (string $k) => old($k, $p->{$k});
    $oldDate = fn (string $k) => old($k, $p->{$k}?->toDateString());
    $sel = function (string $name, array $options, $current, bool $keyed = false) use ($fs) {
        $h = '<select name="'.$name.'" style="'.$fs.'"><option value="">—</option>';
        foreach ($options as $k => $o) {
            $val = $keyed ? $k : $o;
            $label = $keyed ? $o[0] : $o;
            $h .= '<option value="'.e($val).'"'.($current === $val ? ' selected' : '').'>'.e($label).'</option>';
        }
        return $h.'</select>';
    };
@endphp

@if ($canEditPersonal ?? false)
    <div style="display:flex;justify-content:flex-end;"><button type="button" @click="editPersonal = true" class="uj-btn-ghost" style="height:32px;padding:0 14px;font-size:12.5px;">{!! $L('Edit', 'Sunting') !!}</button></div>
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

@if ($canEditPersonal ?? false)
    <template x-teleport="body">
    <div x-show="editPersonal" x-cloak @click.self="editPersonal = false" @keydown.escape.window="editPersonal = false"
         style="position:fixed;inset:0;z-index:120;display:flex;padding:40px 16px;background:rgba(18,18,30,.42);overflow-y:auto;">
        <form method="post" action="{{ route('employees.personal.update', $p) }}" class="uj-card" style="width:100%;max-width:760px;margin:auto;padding:20px;display:flex;flex-direction:column;gap:14px;max-height:calc(100vh - 80px);overflow-y:auto;">
            @csrf
            <div style="font-size:13px;font-weight:600;color:var(--ink);">{!! $L('Edit personal details', 'Sunting butiran peribadi') !!} · {{ $p->name }}</div>
            @if ($errors->any() && session('form') === 'personal')<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;">{{ $errors->first() }}</div>@endif

            <div style="{{ $grid }}">
                <div><label style="{{ $lbl }}">{!! $L('First Name', 'Nama Pertama') !!}</label><input name="first_name" value="{{ $old('first_name') }}" maxlength="120" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('Last Name', 'Nama Akhir') !!}</label><input name="last_name" value="{{ $old('last_name') }}" maxlength="120" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('Full Name per IC/Passport', 'Nama Penuh mengikut IC/Pasport') !!}</label><input name="full_name_ic" value="{{ $old('full_name_ic') }}" maxlength="200" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('Known Name', 'Nama Panggilan') !!}</label><input name="nickname" value="{{ $old('nickname') }}" maxlength="60" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('Religion', 'Agama') !!}</label>{!! $sel('religion', PersonalOptions::RELIGIONS, $old('religion')) !!}</div>
                <div><label style="{{ $lbl }}">{!! $L('Birth Date', 'Tarikh Lahir') !!}</label><input type="date" name="date_of_birth" value="{{ $oldDate('date_of_birth') }}" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('Gender', 'Jantina') !!}</label>{!! $sel('gender', ['male' => ['Male'], 'female' => ['Female']], $old('gender'), true) !!}</div>
                <div><label style="{{ $lbl }}">{!! $L('Marital Status', 'Status Perkahwinan') !!}</label>{!! $sel('marital_status', PersonalOptions::MARITAL, $old('marital_status'), true) !!}</div>
                <div><label style="{{ $lbl }}">{!! $L('Race', 'Bangsa') !!}</label>{!! $sel('race', PersonalOptions::RACES, $old('race')) !!}</div>
                <div><label style="{{ $lbl }}">{!! $L('Nationality', 'Kewarganegaraan') !!}</label>{!! $sel('nationality', PersonalOptions::NATIONALITIES, $old('nationality')) !!}</div>
                <div><label style="{{ $lbl }}">{!! $L('Blood Type', 'Jenis Darah') !!}</label>{!! $sel('blood_type', PersonalOptions::BLOOD_TYPES, $old('blood_type')) !!}</div>
            </div>

            <div class="uj-section-head">{!! $L('Contact & Address', 'Hubungan & Alamat') !!}</div>
            <div style="{{ $grid }}">
                <div><label style="{{ $lbl }}">{!! $L('Phone', 'Telefon') !!}</label><input name="phone" value="{{ $old('phone') }}" maxlength="40" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('Personal Email', 'E-mel Peribadi') !!}</label><input type="email" name="personal_email" value="{{ $old('personal_email') }}" maxlength="190" style="{{ $fs }}" /></div>
                <div style="grid-column:1/-1;"><label style="{{ $lbl }}">{!! $L('Address Line 1', 'Alamat Baris 1') !!}</label><input name="address" value="{{ $old('address') }}" maxlength="500" style="{{ $fs }}" /></div>
                <div style="grid-column:1/-1;"><label style="{{ $lbl }}">{!! $L('Address Line 2', 'Alamat Baris 2') !!}</label><input name="address_2" value="{{ $old('address_2') }}" maxlength="255" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('City', 'Bandar') !!}</label><input name="city" value="{{ $old('city') }}" maxlength="100" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('State', 'Negeri') !!}</label><input name="state" value="{{ $old('state') }}" maxlength="100" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('Postcode', 'Poskod') !!}</label><input name="postcode" value="{{ $old('postcode') }}" maxlength="12" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('Country', 'Negara') !!}</label><input name="country" value="{{ $old('country') ?? 'Malaysia' }}" maxlength="80" style="{{ $fs }}" /></div>
            </div>

            <div class="uj-section-head">{!! $L('Emergency Contact', 'Hubungan Kecemasan') !!}</div>
            <div style="{{ $grid }}">
                <div><label style="{{ $lbl }}">{!! $L('Name', 'Nama') !!}</label><input name="emergency_contact_name" value="{{ $old('emergency_contact_name') }}" maxlength="160" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('Phone', 'Telefon') !!}</label><input name="emergency_contact_phone" value="{{ $old('emergency_contact_phone') }}" maxlength="40" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('Relationship', 'Hubungan') !!}</label><input name="emergency_contact_relationship" value="{{ $old('emergency_contact_relationship') }}" maxlength="60" style="{{ $fs }}" /></div>
            </div>

            @if ($canEditIdentity ?? false)
                <div class="uj-section-head">{!! $L('Identification', 'Pengenalan') !!}</div>
                <div style="{{ $grid }}">
                    <div><label style="{{ $lbl }}">{!! $L('NRIC', 'No. K/P') !!}</label><input name="nric" value="{{ $old('nric') }}" maxlength="20" style="{{ $fs }}" /></div>
                    <div><label style="{{ $lbl }}">{!! $L('Passport No', 'No. Pasport') !!}</label><input name="passport_no" value="{{ $old('passport_no') }}" maxlength="40" style="{{ $fs }}" /></div>
                    <div><label style="{{ $lbl }}">{!! $L('Passport Expiry', 'Tamat Pasport') !!}</label><input type="date" name="passport_expiry" value="{{ $oldDate('passport_expiry') }}" style="{{ $fs }}" /></div>
                    <div><label style="{{ $lbl }}">{!! $L('Permit No', 'No. Permit') !!}</label><input name="permit_no" value="{{ $old('permit_no') }}" maxlength="60" style="{{ $fs }}" /></div>
                    <div><label style="{{ $lbl }}">{!! $L('Permit Expiry', 'Tamat Permit') !!}</label><input type="date" name="permit_expiry" value="{{ $oldDate('permit_expiry') }}" style="{{ $fs }}" /></div>
                </div>
            @endif

            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" @click="editPersonal = false" class="uj-btn-ghost" style="height:40px;padding:0 16px;font-size:13px;">{!! $L('Cancel', 'Batal') !!}</button>
                <button type="submit" class="uj-btn-primary" style="height:40px;padding:0 18px;font-size:13px;">{!! $L('Save changes', 'Simpan perubahan') !!}</button>
            </div>
        </form>
    </div>
    </template>
@endif
