@extends('layouts.app')

@php use App\Support\Amanahku; $p = $profile; $pers = $p?->personality ?? []; @endphp

@section('screen')
@include('partials.guide', [
    'key' => 'profile',
    'en'  => [
        'title' => 'Employee profile',
        'body'  => 'One person\'s full record in one place — their role and employment details, leave balance, KPI progress, skills, career timeline and more. HR and management can edit the core details using the "Edit" button on the identity card.',
    ],
    'ms'  => [
        'title' => 'Profil pekerja',
        'body'  => 'Rekod penuh seseorang dalam satu tempat — jawatan dan butiran pekerjaan, baki cuti, progres KPI, kemahiran, garis masa kerjaya dan banyak lagi. HR dan pengurusan boleh sunting butiran teras guna butang "Edit" pada kad identiti.',
    ],
])
@if (! $p)
    @include('partials.empty-state', ['variantNote' => 'Profile'])
@elseif (! ($canViewFull ?? false))
    {{-- Slim public card: a blocked viewer never gets a 403 (header search and directory
         clicks must not dead-end) but also never gets tabs, stats, salary or attendance. --}}
    <div class="uj-card" style="max-width:360px;padding:0 24px 24px;text-align:center;overflow:hidden;">
        @if ($p->cover_path)
            <div style="margin:0 -24px 14px;">@include('partials.profile-cover', ['employee' => $p, 'height' => 120, 'isOwn' => false, 'canRemove' => false, 'flat' => true])</div>
        @else
            <div style="height:24px;"></div>
        @endif
        <div style="width:88px;height:88px;border-radius:50%;background:{{ $p->avatar_color }};color:#fff;font-size:30px;font-weight:600;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;">{{ $p->initials }}</div>
        <h3 style="font-size:18px;font-weight:600;color:var(--ink);margin:0;">{{ $p->display_name }}</h3>
        <p style="font-size:13px;color:var(--muted);margin:4px 0 12px;">{{ $p->positionBand?->title ?? '—' }}</p>
        @php
            $stOpts = ['active' => 'Active', 'probation' => 'Probation', 'on_leave' => 'On Leave', 'resigned' => 'Resigned'];
            $stColor = ['active' => 'var(--success)', 'probation' => 'var(--amber)', 'on_leave' => 'var(--muted)', 'resigned' => 'var(--error)'][$p->status] ?? 'var(--success)';
        @endphp
        <span style="display:inline-block;font-size:11px;font-weight:600;color:{{ $stColor }};background:var(--canvas);padding:4px 11px;border-radius:9999px;">{{ $stOpts[$p->status] ?? ucfirst($p->status) }}</span>
        <div style="margin-top:14px;font-size:12.5px;color:var(--muted);display:flex;flex-direction:column;gap:6px;">
            <div>{{ $p->department?->name }}@if ($p->branch) · {{ $p->branch->name }}@endif</div>
            <div><span x-text="$store.ui.lang==='en' ? 'Reports to' : 'Melapor kepada'">Reports to</span>: {{ $p->reportsTo?->name ?? '—' }}</div>
        </div>
        <div style="margin-top:18px;display:flex;gap:8px;">
            @if ($msgEnabled ?? false)
                <a href="{{ route('app.screen', 'messages') }}?to={{ $p->id }}" class="uj-btn-primary" style="flex:1;height:38px;font-size:13px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;"><span x-text="$store.ui.lang==='en' ? 'Message' : 'Mesej'">Message</span></a>
            @endif
            <a href="{{ route('app.screen', 'orgchart') }}" class="uj-btn-ghost" style="flex:1;height:38px;font-size:13px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;"><span x-text="$store.ui.lang==='en' ? 'Org chart' : 'Carta organisasi'">Org chart</span></a>
        </div>
    </div>
@else
    @php
        // The signed-in user is looking at their own record — offer self-service editing.
        $isOwn = isset($employee) && $employee && $p && $employee->id === $p->id;
        $stOpts = ['active' => 'Active', 'probation' => 'Probation', 'on_leave' => 'On Leave', 'resigned' => 'Resigned'];
        $stColor = ['active' => 'var(--success)', 'probation' => 'var(--amber)', 'on_leave' => 'var(--muted)', 'resigned' => 'var(--error)'][$p->status] ?? 'var(--success)';
        $fs = 'height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;width:100%;';
    @endphp
    <div x-data="{ edit: {{ $errors->any() ? 'true' : 'false' }} }" style="display:flex;flex-direction:column;gap:16px;">

        {{-- Cover + identity band. With a cover the band rides up over its lower third. --}}
        @if ($p->cover_path)
            @include('partials.profile-cover', ['employee' => $p, 'height' => 200, 'isOwn' => $isOwn, 'canRemove' => $canEdit])
        @elseif ($isOwn)
            <form method="post" action="{{ route('employees.cover.update', $p) }}" enctype="multipart/form-data" x-data>
                @csrf
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" x-ref="f" style="display:none;" @change="$el.form.requestSubmit()">
                <button type="button" class="uj-cover-invite" @click="$refs.f.click()">
                    + <span x-text="$store.ui.lang==='en' ? 'Add a cover photo' : 'Tambah foto cover'">Add a cover photo</span>
                </button>
                @error('photo')<p style="margin:6px 0 0;font-size:12px;color:var(--error);">{{ $message }}</p>@enderror
            </form>
        @endif
        <div class="uj-card {{ $p->cover_path ? 'uj-card--over-cover' : '' }}" style="padding:24px;display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
            <div style="width:76px;height:76px;flex-shrink:0;border-radius:50%;background:{{ $p->avatar_color }};color:#fff;font-size:26px;font-weight:600;display:flex;align-items:center;justify-content:center;" class="{{ $p->cover_path ? 'uj-avatar-ring' : '' }}">{{ $p->initials }}</div>
            <div style="flex:1;min-width:220px;">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <h3 style="font-size:19px;font-weight:600;color:var(--ink);margin:0;">{{ $p->display_name }}</h3>
                    <span style="display:inline-block;font-size:11px;font-weight:600;color:{{ $stColor }};background:var(--canvas);padding:3px 11px;border-radius:9999px;">{{ $stOpts[$p->status] ?? ucfirst($p->status) }}</span>
                </div>
                <p style="font-size:13.5px;color:var(--muted);margin:5px 0 0;">{{ $p->positionBand?->title ?? '—' }}</p>
                <p style="font-size:12.5px;color:var(--muted);margin:3px 0 0;">{{ $p->department?->name }}@if ($p->branch) · {{ $p->branch->name }}@endif</p>
                <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:8px;font-size:12px;color:var(--muted);">
                    <span><span x-text="$store.ui.lang==='en' ? 'Staff ID' : 'ID Staf'">Staff ID</span>: <span style="font-family:var(--font-mono);color:var(--ink);">{{ $p->staff_id ?? '—' }}</span></span>
                    <span><span x-text="$store.ui.lang==='en' ? 'Joined' : 'Menyertai'">Joined</span>: <span style="font-family:var(--font-mono);color:var(--ink);">{{ $p->joined_at?->format('d M Y') ?? '—' }}</span></span>
                </div>
            </div>
            <div style="display:flex;gap:8px;flex-shrink:0;">
                @if (($msgEnabled ?? false) && ! $isOwn)
                    <a href="{{ route('app.screen', 'messages') }}?to={{ $p->id }}" class="uj-btn-primary" style="height:38px;padding:0 16px;font-size:13px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;"><span x-text="$store.ui.lang==='en' ? 'Message' : 'Mesej'">Message</span></a>
                @endif
                @if ($canEdit)<button type="button" @click="edit = true" class="uj-btn-ghost" style="height:38px;padding:0 16px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Edit' : 'Sunting'">Edit</span></button>@endif
                <a href="{{ route('app.screen', 'orgchart') }}" class="uj-btn-ghost" style="height:38px;padding:0 16px;font-size:13px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;"><span x-text="$store.ui.lang==='en' ? 'Org chart' : 'Carta organisasi'">Org chart</span></a>
            </div>
            @if ($isOwn)
                <a href="{{ route('welcome.show') }}" class="uj-btn-ghost" style="display:inline-flex;align-items:center;justify-content:center;height:32px;font-size:12px;padding:0 12px;text-decoration:none;">
                    <span x-text="$store.ui.lang==='en' ? 'Edit my personal details' : 'Sunting butiran peribadi saya'">Edit my personal details</span>
                </a>
            @endif
        </div>

        {{-- Edit modal — teleported to body + centered. Route/method/field names unchanged. --}}
        @if ($canEdit)
            @php $bandsByDept = $allPositions->groupBy(fn ($pos) => $pos->department?->name ?? '—'); @endphp
            <template x-teleport="body">
            <div x-show="edit" x-cloak @click.self="edit = false"
                 style="position:fixed;inset:0;z-index:120;display:flex;padding:40px 16px;background:rgba(18,18,30,.42);overflow-y:auto;"
                 @keydown.escape.window="edit = false">
                <div class="uj-card" style="width:100%;max-width:560px;margin:auto;padding:0;overflow:hidden;max-height:calc(100vh - 80px);display:flex;flex-direction:column;">
                <form method="post" action="{{ route('employees.update', $p) }}"
                      x-data="{ pid: '{{ old('position_id', $p->position_id) }}', max: @js($allPositions->mapWithKeys(fn ($pos) => [$pos->id => (float) $pos->max_salary])) }"
                      style="display:contents;">
                    @csrf
                    <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--hairline);flex-shrink:0;">
                        <span style="font-size:13px;font-weight:600;color:var(--ink);">
                            <span x-text="$store.ui.lang==='en' ? 'Edit' : 'Sunting'">Edit</span> {{ $p->name }}
                        </span>
                        <button type="button" @click="edit = false" style="font-size:20px;line-height:1;color:var(--muted);background:transparent;cursor:pointer;">×</button>
                    </div>
                    <div style="padding:20px;overflow-y:auto;flex:1;min-height:0;display:flex;flex-direction:column;gap:10px;">
                        @if ($errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;">{{ $errors->first() }}</div>@endif
                        <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'Full name' : 'Nama penuh'">Full name</span></label><input name="name" type="text" value="{{ old('name', $p->name) }}" required maxlength="120" style="{{ $fs }}" /></div>
                        <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'Nickname' : 'Nama panggilan'">Nickname</span></label><input name="nickname" type="text" value="{{ old('nickname', $p->nickname) }}" maxlength="60" style="{{ $fs }}" />@include('partials.hint', ['en' => 'The short name colleagues use, such as "Hakime". Used instead of the full name in every list and picker.', 'ms' => 'Nama pendek yang digunakan rakan sekerja, contohnya "Hakime". Digunakan sebagai ganti nama penuh dalam setiap senarai dan pemilih.'])</div>
                        <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'Email' : 'Emel'">Email</span></label><input name="email" type="email" value="{{ old('email', $p->email) }}" maxlength="160" style="{{ $fs }}" /></div>
                        <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'Staff ID' : 'ID Staf'">Staff ID</span></label><input name="staff_id" type="text" value="{{ old('staff_id', $p->staff_id) }}" placeholder="UR-0000" style="{{ $fs }}font-family:var(--font-mono);" /></div>
                        <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'Joined' : 'Menyertai'">Joined</span></label><input name="joined_at" type="date" value="{{ old('joined_at', $p->joined_at?->format('Y-m-d')) }}" style="{{ $fs }}margin-bottom:6px;" />@include('partials.hint', ['en' => 'Leave blank to keep the current hire date.', 'ms' => 'Biar kosong untuk kekalkan tarikh menyertai semasa.'])</div>
                        <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'Date of birth' : 'Tarikh lahir'">Date of birth</span></label><input name="date_of_birth" type="date" value="{{ old('date_of_birth', $p->date_of_birth?->format('Y-m-d')) }}" style="{{ $fs }}" /></div>
                        <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'Position band' : 'Band pangkat'">Position band</span></label><select name="position_id" x-model="pid" style="{{ $fs }}"><option value="">—</option>@foreach ($bandsByDept as $deptName => $group)<optgroup label="{{ $deptName }}">@foreach ($group as $pos)<option value="{{ $pos->id }}" @selected((int) old('position_id', $p->position_id) === $pos->id)>{{ $pos->title }}@if ($pos->staffLevel) · {{ $pos->staffLevel->name }}@endif · RM {{ number_format((float) $pos->max_salary, 0) }}</option>@endforeach</optgroup>@endforeach</select></div>
                        @if ($canSeeSalary ?? false)<div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'Salary (RM)' : 'Gaji (RM)'">Salary (RM)</span></label><input type="number" step="0.01" min="0" name="salary" value="{{ old('salary', $p->salary) }}" placeholder="0.00" style="{{ $fs }}font-family:var(--font-mono);" /><div x-show="pid && max[pid] !== undefined" x-cloak style="font-size:11px;color:var(--muted);margin-top:4px;"><span x-text="$store.ui.lang==='en' ? 'Band max:' : 'Maks band:'">Band max:</span> RM <span x-text="(max[pid] ?? 0).toLocaleString('en-MY',{minimumFractionDigits:2})"></span></div></div>@endif
                        <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'Branch' : 'Cawangan'">Branch</span></label><select name="branch_id" style="{{ $fs }}"><option value="">—</option>@foreach ($allBranches as $b)<option value="{{ $b->id }}" @selected((int) old('branch_id', $p->branch_id) === $b->id)>{{ $b->name }}</option>@endforeach</select></div>
                        @php $waOpts = ['office' => 'Office', 'client' => 'Client site', 'wfh' => 'Work from home', 'hybrid' => 'Hybrid']; @endphp
                        <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'Work arrangement' : 'Pengaturan kerja'">Work arrangement</span></label><select name="work_arrangement" style="{{ $fs }}">@foreach ($waOpts as $v => $l)<option value="{{ $v }}" @selected(old('work_arrangement', $p->work_arrangement ?? 'office') === $v)>{{ $l }}</option>@endforeach</select>@include('partials.hint', ['en' => 'Where this person clocks in. Client site location and hybrid office days are set on the Attendance Setup screen.', 'ms' => 'Di mana orang ini merekod kehadiran. Lokasi tapak klien dan hari pejabat hibrid ditetapkan pada skrin Persediaan Kehadiran.'])</div>
                        <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'Employment type' : 'Jenis pekerjaan'">Employment type</span></label><select name="employment_type_id" style="{{ $fs }}"><option value="">—</option>@foreach ($allEmploymentTypes as $et)<option value="{{ $et->id }}" @selected((int) old('employment_type_id', $p->employment_type_id) === $et->id)>{{ $et->name }}</option>@endforeach</select></div>
                        <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'Reports to' : 'Melapor kepada'">Reports to</span></label><select name="reports_to_id" style="{{ $fs }}"><option value="">—</option>@foreach ($allManagers as $m)@continue($m->id === $p->id)<option value="{{ $m->id }}" @selected((int) old('reports_to_id', $p->reports_to_id) === $m->id)>{{ $m->name }}</option>@endforeach</select>@include('partials.hint', ['en' => 'Who this person reports to. This single link is what builds the organisation chart.', 'ms' => 'Siapa orang ini melapor kepadanya. Pautan inilah yang membina carta organisasi.'])</div>
                        <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'Status' : 'Status'">Status</span></label><select name="status" style="{{ $fs }}">@foreach ($stOpts as $v => $l)<option value="{{ $v }}" @selected(old('status', $p->status) === $v)>{{ $l }}</option>@endforeach</select></div>
                        <button type="submit" class="uj-btn-primary" style="height:40px;font-size:13px;width:100%;padding:0 16px;display:flex;align-items:center;justify-content:center;margin-top:2px;"><span x-text="$store.ui.lang==='en' ? 'Save changes' : 'Simpan perubahan'">Save changes</span></button>
                    </div>
                </form>

                {{-- Login/password/archive actions — separate forms so they never nest inside the edit form above. --}}
                <div style="padding:16px 20px 20px;border-top:1px solid var(--hairline);flex-shrink:0;">
                    @if ($p->email && ! $p->user_id)
                        <form method="post" action="{{ route('members.create-login', $p) }}" style="margin-top:8px;">
                            @csrf
                            <button type="submit" class="uj-btn-ghost" style="height:38px;font-size:12.5px;width:100%;"><span x-text="$store.ui.lang==='en' ? 'Create login' : 'Cipta login'">Create login</span></button>
                            <p style="font-size:11px;color:var(--muted);margin:6px 0 0;"><span x-text="$store.ui.lang==='en' ? 'Sends an email invite to this address to activate the account and set a password.' : 'Menghantar jemputan emel ke alamat ini untuk mengaktifkan akaun dan menetapkan kata laluan.'"></span></p>
                        </form>
                    @elseif ($p->user_id)
                        <p style="font-size:11px;color:var(--muted);margin-top:8px;"><span x-text="$store.ui.lang==='en' ? 'This person already has a login.' : 'Orang ini sudah ada login.'">This person already has a login.</span></p>
                        {{-- Never activated: the invite is queued mail and can be lost without a trace, so HR needs a way to send it again (see MemberController::resendInvite). --}}
                        @if ($p->user?->password_change_required)
                            <form method="post" action="{{ route('members.resend-invite', $p) }}" style="margin-top:8px;">
                                @csrf
                                <button type="submit" class="uj-btn-ghost" style="height:38px;font-size:12.5px;width:100%;"><span x-text="$store.ui.lang==='en' ? 'Resend invite' : 'Hantar semula jemputan'">Resend invite</span></button>
                                <p style="font-size:11px;color:var(--muted);margin:6px 0 0;"><span x-text="$store.ui.lang==='en' ? 'This person has not activated their account yet. Sends a fresh invite email; any earlier invite stops working.' : 'Orang ini belum mengaktifkan akaun mereka. Menghantar emel jemputan baharu; jemputan terdahulu berhenti berfungsi.'"></span></p>
                            </form>
                        @endif
                        {{-- Reset password: mints a fresh one-time password shown to HR once (see MemberController::resetPassword). The employee must change it on next sign-in. --}}
                        <form method="post" action="{{ route('members.reset-password', $p) }}"
                              @submit="if (! confirm($store.ui.lang==='en' ? @js('Reset the password for '.$p->name.'? A new one-time password will be shown to you and they must change it on next sign-in.') : @js('Set semula kata laluan '.$p->name.'? Kata laluan sekali guna baharu akan dipaparkan kepada anda dan mereka mesti menukarnya semasa log masuk seterusnya.'))) $event.preventDefault();"
                              style="margin-top:8px;">
                            @csrf
                            <button type="submit" class="uj-btn-ghost" style="height:38px;font-size:12.5px;width:100%;"><span x-text="$store.ui.lang==='en' ? 'Reset password' : 'Set semula kata laluan'">Reset password</span></button>
                            <p style="font-size:11px;color:var(--muted);margin:6px 0 0;"><span x-text="$store.ui.lang==='en' ? 'Generates a new one-time password shown to you once. The employee must set their own password on next sign-in.' : 'Menjana kata laluan sekali guna baharu yang dipaparkan kepada anda sekali sahaja. Pekerja mesti menetapkan kata laluan sendiri semasa log masuk seterusnya.'"></span></p>
                        </form>
                    @endif
                    {{-- Archive (soft-delete): hides the person from the directory; history kept, restorable. Separate form so it never nests inside the edit form. --}}
                    <form method="post" action="{{ route('employees.destroy', $p) }}"
                          @submit="if (! confirm($store.ui.lang==='en' ? @js('Archive '.$p->name.'? They will be removed from the directory. Their history is kept and they can be restored.') : @js('Arkib '.$p->name.'? Mereka akan dikeluarkan dari direktori. Sejarah dikekalkan dan boleh dipulihkan.'))) $event.preventDefault();"
                          style="margin-top:8px;">
                        @csrf
                        <button type="submit" class="uj-btn-ghost" style="height:38px;font-size:12.5px;width:100%;color:var(--red);border-color:var(--red);"><span x-text="$store.ui.lang==='en' ? 'Archive staff' : 'Arkib staf'">Archive staff</span></button>
                    </form>
                </div>
                </div>
            </div>
            </template>
        @endif

        {{-- Stat row --}}
        <div style="display:flex;gap:16px;flex-wrap:wrap;">
            <div class="uj-card" style="flex:1;min-width:120px;padding:16px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Annual leave' : 'Cuti tahunan'">Annual leave</span></div><div class="uj-stat-value" style="font-size:22px;">{{ $p->annualLeaveBalance() }} <span style="font-size:12px;color:var(--muted);font-weight:400;"><span x-text="$store.ui.lang==='en' ? 'days' : 'hari'">days</span></span></div></div>
            @if ($kpiGate ?? false)
            <div class="uj-card" style="flex:1;min-width:120px;padding:16px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'KPI · H1' : 'KPI · H1'">KPI · H1</span></div><div class="uj-stat-value" style="font-size:22px;color:var(--success);">{{ $p->kpi_pct }}%</div></div>
            @endif
            <div class="uj-card" style="flex:1;min-width:120px;padding:16px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Workload' : 'Beban kerja'">Workload</span></div><div style="font-size:15px;font-weight:600;color:{{ Amanahku::SWATCH[$p->workload] }};margin-top:5px;">● {{ $p->workload_label }}</div></div>
            <div class="uj-card" style="flex:1;min-width:120px;padding:16px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Open tasks' : 'Tugas terbuka'">Open tasks</span></div><div class="uj-stat-value" style="font-size:22px;">{{ $p->workItems->whereIn('status', ['todo','prog','review'])->count() }}</div></div>
        </div>

        @php
            // Read-only lookup maps for the profile tabs (mirrors the standalone screens).
            $wTag    = ['assignment' => ['Assignment', 'var(--red)'], 'task' => ['Task', 'var(--info)'], 'adhoc' => ['Adhoc', 'var(--amber)']];
            $wStatus = ['todo' => ['To Do', 'var(--muted)'], 'prog' => ['In Progress', 'var(--info)'], 'review' => ['In Review', 'var(--amber)'], 'done' => ['Done', 'var(--success)']];
            $wPri    = ['high' => 'var(--error)', 'medium' => 'var(--amber)', 'low' => 'var(--muted)'];
            $wItems  = $p->workItems->where('status', 'prog')->values();
            $aIcon   = ['laptop' => '💻', 'phone' => '📱', 'vehicle' => '🚗', 'furniture' => '🪑', 'other' => '📦'];
            $aSc     = ['assigned' => 'var(--info)', 'available' => 'var(--success)', 'maintenance' => 'var(--amber)', 'retired' => 'var(--muted)'];
            $tSc     = ['completed' => 'var(--success)', 'in_progress' => 'var(--info)', 'not_started' => 'var(--muted)'];
            $tSl     = ['completed' => 'Completed', 'in_progress' => 'In progress', 'not_started' => 'Not started'];
            $leaveSt = ['approved' => ['var(--success)', 'Approved', 'Diluluskan'], 'verified' => ['var(--amber)', 'With management', 'Dengan pengurusan'], 'submitted' => ['var(--amber)', 'With your manager', 'Dengan pengurus'], 'rejected' => ['var(--error)', 'Declined', 'Ditolak'], 'cancelled' => ['var(--muted)', 'Cancelled', 'Dibatalkan'], 'draft' => ['var(--muted)', 'Draft', 'Draf']];
            $claimSt = ['approved' => 'var(--success)', 'pending' => 'var(--amber)', 'verified' => 'var(--amber)', 'submitted' => 'var(--amber)', 'rejected' => 'var(--error)'];
            $loanSt  = ['approved' => 'var(--success)', 'submitted' => 'var(--amber)', 'rejected' => 'var(--error)'];
            $otSt    = ['approved' => 'var(--success)', 'submitted' => 'var(--amber)', 'verified' => 'var(--amber)', 'rejected' => 'var(--error)'];
            $probSt  = ['confirmed' => 'var(--success)', 'active' => 'var(--info)', 'extended' => 'var(--amber)', 'terminated' => 'var(--error)'];

            $money = fn ($v) => 'RM ' . number_format((float) $v, 2);

            $perfShow = ($goalsGate ?? false) || ($reviewsGate ?? false) || ($probationGate ?? false) || ($skillsGate ?? false);
            $moneyShow = ($canSeeMoney ?? false) && (($payrollGate ?? false) || ($claimsGate ?? false) || ($loansGate ?? false) || ($overtimeGate ?? false));

            $tabs = [['overview', 'Overview', 'Gambaran']];
            $tabs[] = ['work', 'Work & Tasks', 'Kerja & Tugas'];
            if ($leaveGate ?? false) {
                $tabs[] = ['leave', 'Leave & Attendance', 'Cuti & Kehadiran'];
            }
            if ($kpiGate ?? false) {
                $tabs[] = ['kpi', 'KPI History', 'Sejarah KPI'];
            }
            if ($perfShow) {
                $tabs[] = ['performance', 'Performance', 'Prestasi'];
            }
            if ($moneyShow) {
                $tabs[] = ['money', 'Money', 'Wang'];
            }
            $tabs[] = ['assets', 'Assets & Training', 'Aset & Latihan'];
        @endphp
        <div class="uj-card" x-data="{ tab: 'overview' }">
            <div style="display:flex;gap:4px;padding:6px;border-bottom:1px solid var(--hairline);overflow-x:auto;">
                @foreach ($tabs as $tab)
                    <button type="button" @click="tab = '{{ $tab[0] }}'"
                        style="font-size:13px;padding:7px 14px;border-radius:7px;white-space:nowrap;cursor:pointer;border:0;transition:background .12s;"
                        :style="tab === '{{ $tab[0] }}' ? { color:'#fff', background:'var(--red)', fontWeight:'600' } : { color:'var(--body)', background:'transparent', fontWeight:'400' }"
                        x-text="$store.ui.lang==='en' ? @js($tab[1]) : @js($tab[2])">{{ $tab[1] }}</button>
                @endforeach
            </div>

            {{-- Overview · career timeline, employment details, skills tags, personality, interests, documents link-out --}}
            <div x-show="tab === 'overview'" class="uj-tab-stack" style="padding:20px;">
                <div>
                    <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Employment' : 'Pekerjaan'">Employment</span></div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px 24px;font-size:13px;">
                        <div style="display:flex;flex-direction:column;gap:3px;min-width:0;"><span style="font-size:11px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Department' : 'Jabatan'">Department</span></span><span style="color:var(--ink);font-weight:500;">{{ $p->department?->name ?? '—' }}</span></div>
                        <div style="display:flex;flex-direction:column;gap:3px;min-width:0;"><span style="font-size:11px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Branch' : 'Cawangan'">Branch</span></span><span style="color:var(--ink);font-weight:500;">{{ $p->branch?->name ?? '—' }}</span></div>
                        <div style="display:flex;flex-direction:column;gap:3px;min-width:0;"><span style="font-size:11px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Reports to' : 'Melapor kepada'">Reports to</span></span><span style="color:var(--ink);font-weight:500;">{{ $p->reportsTo?->name ?? '—' }}</span></div>
                        <div style="display:flex;flex-direction:column;gap:3px;min-width:0;"><span style="font-size:11px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Employment type' : 'Jenis pekerjaan'">Employment type</span></span><span style="color:var(--ink);font-weight:500;">{{ $p->employmentType?->name ?? '—' }}</span></div>
                    </div>
                </div>

                <div>
                    <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Career timeline' : 'Garis masa kerjaya'">Career timeline</span></div>
                    @forelse ($p->careerTimeline->sortByDesc('sort') as $c)
                        <div style="display:flex;gap:14px;padding-bottom:16px;"><div style="width:10px;height:10px;border-radius:50%;background:{{ Amanahku::SWATCH[$c->category] ?? 'var(--muted-soft)' }};margin-top:4px;flex-shrink:0;"></div><div><div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $c->title }}</div><div style="font-size:12px;color:var(--muted);font-family:var(--font-mono);">{{ $c->date_label }}</div></div></div>
                    @empty
                        <div style="padding:24px 4px;text-align:center;font-size:13px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'No career history yet.' : 'Tiada sejarah kerjaya lagi.'">No career history yet.</div>
                    @endforelse
                </div>

                @if ($p->skills)
                <div>
                    <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Skills' : 'Kemahiran'">Skills</span></div>
                    <div style="display:flex;flex-wrap:wrap;gap:7px;">@foreach ($p->skills as $s)<span style="font-size:12px;color:var(--ink);background:var(--canvas);border:1px solid var(--hairline);padding:5px 11px;border-radius:9999px;">{{ $s }}</span>@endforeach</div>
                </div>
                @endif

                @if ($pers)
                <div>
                    <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;margin-bottom:14px;"><span x-text="$store.ui.lang==='en' ? 'Personality profile' : 'Profil personaliti'">Personality profile</span></div>
                    <div style="margin-bottom:14px;">
                        <div style="font-size:16px;font-weight:600;color:var(--ink);">{{ $pers['type'] ?? '' }}</div>
                        <div style="font-size:12.5px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Spirit animal:' : 'Haiwan semangat:'">Spirit animal:</span> <span style="color:var(--red);font-weight:500;">{{ $pers['animal'] ?? '' }}</span></div>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:11px;">
                        @foreach (($pers['traits'] ?? []) as $tr)
                            <div><div style="display:flex;justify-content:space-between;margin-bottom:4px;"><span style="font-size:12px;color:var(--body);">{{ $tr['label'] }}</span><span style="font-size:11px;color:var(--muted);font-family:var(--font-mono);">{{ $tr['pct'] }}%</span></div><div class="uj-progress"><span style="width:{{ $tr['pct'] }}%;background:{{ Amanahku::SWATCH[$tr['color']] ?? 'var(--success)' }};"></span></div></div>
                        @endforeach
                    </div>
                    <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--hairline-soft);font-size:12px;color:var(--body);line-height:1.5;">{{ $pers['blurb'] ?? '' }}</div>
                </div>
                @endif

                @if ($p->interests)
                <div>
                    <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Career interests' : 'Minat kerjaya'">Career interests</span></div>
                    <div style="display:flex;flex-wrap:wrap;gap:7px;">@foreach ($p->interests as $i)<span style="font-size:12px;color:var(--red);background:var(--red-tint);padding:5px 11px;border-radius:9999px;font-weight:500;">{{ $i }}</span>@endforeach</div>
                </div>
                @endif

                <div>
                    <a href="{{ route('app.screen', 'documents') }}" class="uj-btn-ghost" style="display:inline-flex;height:36px;align-items:center;padding:0 16px;font-size:13px;text-decoration:none;"><span x-text="$store.ui.lang==='en' ? 'Open Documents' : 'Buka Dokumen'">Open Documents</span></a>
                </div>
            </div>

            {{-- Work & Tasks · work items + assigned-tasks box with the Assign modal --}}
            <div x-show="tab === 'work'" x-cloak style="padding:6px 0;">
                <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;padding:14px 20px 6px;"><span x-text="$store.ui.lang==='en' ? 'Work items' : 'Item kerja'">Work items</span></div>
                @if ($isOwn)
                <div style="padding:0 20px 12px;">
                    <span style="font-size:12px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Google Calendar' : 'Kalendar Google'">Google Calendar</span>
                    @if ($errors->has('google_calendar'))
                        <div style="width:100%;font-size:12px;color:var(--red);margin-top:8px;">{{ $errors->first('google_calendar') }}</div>
                    @endif
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:8px;">
                    @if ($googleCalendarConnected ?? false)
                        <form method="post" action="{{ route('google-calendar.disconnect') }}">
                            @csrf
                            <button type="submit" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12px;">
                                <span x-text="$store.ui.lang==='en' ? 'Disconnect' : 'Putuskan'">Disconnect</span>
                            </button>
                        </form>
                    @else
                        <a href="{{ route('google-calendar.redirect') }}" style="display:inline-flex;align-items:center;gap:8px;height:30px;padding:0 12px 0 8px;font-size:12px;font-weight:500;color:#3c4043;text-decoration:none;background:#fff;border:1px solid #dadce0;border-radius:6px;">
                            <svg width="16" height="16" viewBox="0 0 18 18" style="flex-shrink:0;">
                                <path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62z"/>
                                <path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.8.54-1.84.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.33A9 9 0 0 0 9 18z"/>
                                <path fill="#FBBC05" d="M3.97 10.72A5.4 5.4 0 0 1 3.68 9c0-.6.1-1.18.29-1.72V4.95H.96A9 9 0 0 0 0 9c0 1.45.35 2.83.96 4.05l3.01-2.33z"/>
                                <path fill="#EA4335" d="M9 3.58c1.32 0 2.51.45 3.44 1.35l2.59-2.59C13.46.89 11.43 0 9 0A9 9 0 0 0 .96 4.95l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58z"/>
                            </svg>
                            <span x-text="$store.ui.lang==='en' ? 'Connect' : 'Sambung'">Connect</span>
                        </a>
                    @endif
                </div>
                </div>
                @endif
                @forelse ($wItems as $w)
                    @php [$tl, $tc] = $wTag[$w->type] ?? ['Task', 'var(--info)']; [$sl, $scol] = $wStatus[$w->status] ?? ['—', 'var(--muted)']; @endphp
                    <div class="uj-row" style="display:flex;align-items:center;gap:12px;padding:12px 20px;border-bottom:1px solid var(--hairline-soft);">
                        <div style="flex:1;min-width:0;">
                            <div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $w->title }}</div>
                            <div style="font-size:11.5px;color:var(--muted);margin-top:2px;"><span style="color:{{ $tc }};font-weight:600;">{{ $tl }}</span>@if ($w->due_label) · {{ $w->due_label }}@endif</div>
                        </div>
                        @if ($w->priority)<span style="font-size:10.5px;font-weight:600;color:{{ $wPri[$w->priority] ?? 'var(--muted)' }};">{{ ucfirst($w->priority) }}</span>@endif
                        <span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:{{ $scol }};white-space:nowrap;"><span style="width:8px;height:8px;border-radius:50%;background:{{ $scol }};"></span>{{ $sl }}</span>
                    </div>
                @empty
                    <div style="padding:32px 20px;text-align:center;font-size:13px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'No work items in progress.' : 'Tiada item kerja sedang berjalan.'">No work items in progress.</div>
                @endforelse

                @if (($canAssign ?? false) && ! $isOwn && ! $p->isArchived())
                <div style="padding:18px 20px 4px;" x-data="{ assign: {{ $errors->getBag('assign')->any() ? 'true' : 'false' }} }">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;border-top:1px solid var(--hairline-soft);padding-top:16px;">
                        <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;">
                            <span x-text="$store.ui.lang==='en' ? 'Assigned tasks' : 'Tugas diberi'">Assigned tasks</span>
                        </div>
                        <button type="button" @click="assign = true" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12px;">
                            <span x-text="$store.ui.lang==='en' ? 'Assign task' : 'Beri tugas'">Assign task</span>
                        </button>
                    </div>

                    {{-- Tracking list: tasks already assigned to this person, soonest due first. --}}
                    @forelse ($assignedTasks ?? [] as $t)
                        @php
                            $tcol = ['todo' => 'var(--muted)', 'prog' => 'var(--info)', 'review' => 'var(--amber)', 'done' => 'var(--success)'][$t->status] ?? 'var(--muted)';
                            $tlab = ['todo' => 'To Do', 'prog' => 'In Progress', 'review' => 'In Review', 'done' => 'Done'][$t->status] ?? $t->status;
                            $overdue = $t->due_at && $t->status !== 'done' && $t->due_at->isPast();
                        @endphp
                        <div style="display:flex;align-items:flex-start;gap:10px;padding:10px 0;border-top:1px solid var(--hairline-soft);">
                            <span style="flex-shrink:0;margin-top:3px;width:8px;height:8px;border-radius:50%;background:{{ $tcol }};"></span>
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:13px;color:var(--ink);font-weight:500;line-height:1.35;">{{ $t->title }}</div>
                                <div style="font-size:11.5px;color:var(--muted);margin-top:3px;">
                                    {{ $tlab }} · {{ $t->assignedBy?->name ?? '—' }}
                                    @if ($t->due_at)<span style="color:{{ $overdue ? 'var(--error)' : 'var(--muted)' }};">· {{ $t->due_at->format('d M') }}{{ $overdue ? ' · overdue' : '' }}</span>@endif
                                </div>
                            </div>
                        </div>
                    @empty
                        <p style="font-size:12.5px;color:var(--muted);margin:0;" x-text="$store.ui.lang==='en' ? 'No tasks assigned to this person yet.' : 'Tiada tugas diberi kepada orang ini lagi.'">No tasks assigned to this person yet.</p>
                    @endforelse

                    {{-- Assign modal — teleported to body + centered. --}}
                    <template x-teleport="body">
                    <div x-show="assign" x-cloak @click.self="assign = false"
                         style="position:fixed;inset:0;z-index:120;display:flex;padding:40px 16px;background:rgba(18,18,30,.42);overflow-y:auto;"
                         @keydown.escape.window="assign = false">
                        <form method="post" action="{{ route('work.assign', $p) }}" class="uj-card"
                              style="width:100%;max-width:520px;margin:auto;padding:0;overflow:hidden;">
                            @csrf
                            <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--hairline);">
                                <span style="font-size:13px;font-weight:600;color:var(--ink);">
                                    <span x-text="$store.ui.lang==='en' ? 'Assign a task to' : 'Beri tugas kepada'">Assign a task to</span> {{ $p->name }}
                                </span>
                                <button type="button" @click="assign = false" style="font-size:20px;line-height:1;color:var(--muted);background:transparent;cursor:pointer;">×</button>
                            </div>
                            <div style="padding:20px;display:flex;flex-direction:column;gap:14px;">
                                @if ($errors->getBag('assign')->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;">{{ $errors->getBag('assign')->first() }}</div>@endif
                                <div>
                                    <label style="display:block;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Title' : 'Tajuk'">Title</label>
                                    <input name="title" maxlength="160" required value="{{ old('title') }}" style="{{ $fs }}" />
                                </div>
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                                    <div>
                                        <label style="display:block;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Type' : 'Jenis'">Type</label>
                                        <select name="type" style="{{ $fs }}">@foreach (['adhoc' => 'Adhoc', 'task' => 'Task', 'assignment' => 'Assignment'] as $v => $l)<option value="{{ $v }}" @selected(old('type', 'adhoc') === $v)>{{ $l }}</option>@endforeach</select>
                                    </div>
                                    <div>
                                        <label style="display:block;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Priority' : 'Keutamaan'">Priority</label>
                                        <select name="priority" style="{{ $fs }}">@foreach (['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $v => $l)<option value="{{ $v }}" @selected(old('priority', 'medium') === $v)>{{ $l }}</option>@endforeach</select>
                                    </div>
                                </div>
                                <div>
                                    <label style="display:block;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Due date' : 'Tarikh akhir'">Due date</label>
                                    <input name="due_at" type="date" required value="{{ old('due_at') }}" style="{{ $fs }}" />
                                </div>
                                <div>
                                    <label style="display:block;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Description' : 'Penerangan'">Description</label>
                                    <textarea name="description" rows="3" maxlength="5000" style="width:100%;border:1px solid var(--hairline);border-radius:8px;padding:9px 11px;font-size:13px;color:var(--ink);outline:none;resize:vertical;font-family:inherit;">{{ old('description') }}</textarea>
                                </div>
                                <button type="submit" class="uj-btn-primary" style="height:40px;font-size:13px;">
                                    <span x-text="$store.ui.lang==='en' ? 'Assign task' : 'Beri tugas'">Assign task</span>
                                </button>
                            </div>
                        </form>
                    </div>
                    </template>
                </div>
                @endif
            </div>

            {{-- Leave & Attendance · balances by type, request history, this-month attendance --}}
            @if ($leaveGate ?? false)
            <div x-show="tab === 'leave'" x-cloak class="uj-tab-stack" style="padding:20px;">
                <div>
                    <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Leave balances' : 'Baki cuti'">Leave balances</span></div>
                    <div style="display:flex;flex-wrap:wrap;gap:10px;">
                        @forelse ($p->leaveBalances as $b)
                            <div style="min-width:130px;flex:1;border:1px solid var(--hairline-soft);border-radius:8px;padding:10px 12px;">
                                <div style="font-size:11.5px;color:var(--muted);">{{ $b->leaveType?->name ?? '—' }}</div>
                                {{-- A granted type (Replacement) has no yearly entitlement to count
                                     against — its quota is whatever HR granted — so it shows a bare
                                     balance rather than a "/ 0" denominator. --}}
                                <div style="font-size:16px;color:var(--ink);font-weight:600;font-family:var(--font-mono);">{{ rtrim(rtrim(number_format((float) $b->balance, 1), '0'), '.') }}@if (! $b->leaveType?->is_hr_granted_only) <span style="font-size:11px;color:var(--muted);font-weight:400;">/ {{ rtrim(rtrim(number_format((float) ($b->leaveType?->entitlement ?? 0), 1), '0'), '.') }}</span>@endif</div>
                            </div>
                        @empty
                            <div style="padding:24px 4px;text-align:center;font-size:13px;color:var(--muted);width:100%;" x-text="$store.ui.lang==='en' ? 'No leave balances set up.' : 'Tiada baki cuti ditetapkan.'">No leave balances set up.</div>
                        @endforelse
                    </div>
                </div>

                <div>
                    <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Leave request history' : 'Sejarah permohonan cuti'">Leave request history</span></div>
                    @forelse ($leaveHistory ?? [] as $lr)
                        @php [$lcol, $len, $lms] = $leaveSt[$lr->status] ?? ['var(--muted)', ucfirst($lr->status), ucfirst($lr->status)]; @endphp
                        <div class="uj-row" style="display:flex;align-items:center;gap:10px;padding:10px 0;border-top:1px solid var(--hairline-soft);">
                            <span style="flex-shrink:0;width:8px;height:8px;border-radius:50%;background:{{ $lcol }};"></span>
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:13px;color:var(--ink);font-weight:500;">{{ $lr->leaveType?->name ?? '—' }}</div>
                                <div style="font-size:11.5px;color:var(--muted);font-family:var(--font-mono);">{{ $lr->date_from?->format('d M') }} → {{ $lr->date_to?->format('d M Y') }} · {{ $lr->days }}d</div>
                            </div>
                            <span style="font-size:11px;font-weight:600;color:{{ $lcol }};white-space:nowrap;" x-text="$store.ui.lang==='en' ? @js($len) : @js($lms)">{{ $len }}</span>
                        </div>
                    @empty
                        <p style="font-size:12.5px;color:var(--muted);margin:0;" x-text="$store.ui.lang==='en' ? 'No leave requests yet.' : 'Tiada permohonan cuti lagi.'">No leave requests yet.</p>
                    @endforelse
                </div>

                <div>
                    <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Attendance · this month' : 'Kehadiran · bulan ini'">Attendance · this month</span></div>
                    @forelse ($attendance ?? [] as $a)
                        @php
                            $aLate = $a->status === 'late';
                            $aIn   = $a->clock_in ? substr((string) $a->clock_in, 0, 5) : '—';
                            $aOut  = $a->clock_out ? substr((string) $a->clock_out, 0, 5) : '—';
                            $aDot  = $a->clock_in ? ($aLate ? 'var(--amber)' : 'var(--success)') : 'var(--muted-soft)';
                        @endphp
                        <div class="uj-row" style="display:flex;align-items:center;gap:10px;padding:9px 0;border-top:1px solid var(--hairline-soft);">
                            <span style="flex-shrink:0;width:8px;height:8px;border-radius:50%;background:{{ $aDot }};"></span>
                            <span style="font-size:12.5px;color:var(--ink);font-weight:500;min-width:54px;">{{ $a->date->format('d M') }}</span>
                            <span style="font-size:12.5px;color:var(--body);font-family:var(--font-mono);">{{ $aIn }} → {{ $aOut }}</span>
                            @if ($aLate)<span style="font-size:10.5px;color:var(--amber);font-weight:600;margin-left:auto;"><span x-text="$store.ui.lang==='en' ? 'Late' : 'Lewat'">Late</span></span>@endif
                        </div>
                    @empty
                        <p style="font-size:12.5px;color:var(--muted);margin:0;" x-text="$store.ui.lang==='en' ? 'No attendance recorded this month.' : 'Tiada kehadiran direkod bulan ini.'">No attendance recorded this month.</p>
                    @endforelse
                </div>
            </div>
            @endif

            {{-- KPI History · objectives with progress (hidden when Performance module is off) --}}
            @if ($kpiGate ?? false)
            <div x-show="tab === 'kpi'" x-cloak style="padding:6px 0;">
                @forelse ($p->kpiItems as $k)
                    <div style="padding:13px 20px;border-bottom:1px solid var(--hairline-soft);">
                        <div style="display:flex;justify-content:space-between;gap:12px;margin-bottom:7px;">
                            <div style="min-width:0;"><div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $k->title }}</div><div style="font-size:11px;color:var(--muted);text-transform:capitalize;">{{ $k->category }}</div></div>
                            <div style="text-align:right;white-space:nowrap;"><span style="font-size:12.5px;color:var(--ink);font-weight:600;font-family:var(--font-mono);">{{ $k->actual }}</span><span style="font-size:11.5px;color:var(--muted);"> / {{ $k->target }}</span></div>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;"><div class="uj-progress" style="flex:1;"><span style="width:{{ $k->progress }}%;background:{{ Amanahku::SWATCH[$k->status] ?? 'var(--success)' }};"></span></div><span style="font-size:11px;color:var(--muted);font-family:var(--font-mono);">{{ $k->progress }}%</span><span style="font-size:11px;color:var(--muted);">· w{{ $k->weight }}</span></div>
                    </div>
                @empty
                    <div style="padding:32px 20px;text-align:center;font-size:13px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'No KPI objectives set.' : 'Tiada objektif KPI ditetapkan.'">No KPI objectives set.</div>
                @endforelse
            </div>
            @endif

            {{-- Performance · goals, reviews, probation, skill matrix --}}
            @if ($perfShow)
            <div x-show="tab === 'performance'" x-cloak class="uj-tab-stack" style="padding:20px;">
                @if ($goalsGate ?? false)
                <div>
                    <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Goals' : 'Matlamat'">Goals</span></div>
                    @forelse ($goals ?? [] as $g)
                        <div style="padding:11px 0;border-top:1px solid var(--hairline-soft);">
                            <div style="display:flex;justify-content:space-between;gap:12px;margin-bottom:6px;">
                                <div style="min-width:0;"><div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $g->title }}</div><div style="font-size:11px;color:var(--muted);text-transform:capitalize;">{{ $g->category }} · {{ $g->period }}</div></div>
                                <span style="font-size:11px;font-weight:600;color:var(--muted);text-transform:capitalize;white-space:nowrap;">{{ $g->status }}</span>
                            </div>
                            <div style="display:flex;align-items:center;gap:8px;"><div class="uj-progress" style="flex:1;"><span style="width:{{ $g->progress }}%;background:var(--success);"></span></div><span style="font-size:11px;color:var(--muted);font-family:var(--font-mono);">{{ $g->progress }}%</span></div>
                        </div>
                    @empty
                        <p style="font-size:12.5px;color:var(--muted);margin:0;" x-text="$store.ui.lang==='en' ? 'No goals set.' : 'Tiada matlamat ditetapkan.'">No goals set.</p>
                    @endforelse
                </div>
                @endif

                @if ($reviewsGate ?? false)
                <div>
                    <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Performance reviews' : 'Penilaian prestasi'">Performance reviews</span></div>
                    @forelse ($reviews ?? [] as $r)
                        <div class="uj-row" style="display:flex;align-items:center;gap:10px;padding:10px 0;border-top:1px solid var(--hairline-soft);">
                            <span style="flex-shrink:0;width:8px;height:8px;border-radius:50%;background:{{ $r->acknowledged_at ? 'var(--success)' : 'var(--amber)' }};"></span>
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:13px;color:var(--ink);font-weight:500;">{{ $r->period_label ?? $r->cycle }}</div>
                                <div style="font-size:11.5px;color:var(--muted);font-family:var(--font-mono);">{{ $r->review_date?->format('d M Y') }}</div>
                            </div>
                            <span style="font-size:12.5px;color:var(--ink);font-weight:600;font-family:var(--font-mono);white-space:nowrap;">{{ $r->rating_label ?? $r->overall_rating }}</span>
                        </div>
                    @empty
                        <p style="font-size:12.5px;color:var(--muted);margin:0;" x-text="$store.ui.lang==='en' ? 'No performance reviews yet.' : 'Tiada penilaian prestasi lagi.'">No performance reviews yet.</p>
                    @endforelse
                </div>
                @endif

                @if ($probationGate ?? false)
                <div>
                    <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Probation' : 'Percubaan'">Probation</span></div>
                    @forelse ($probation ?? [] as $pr)
                        <div class="uj-row" style="display:flex;align-items:center;gap:10px;padding:10px 0;border-top:1px solid var(--hairline-soft);">
                            <span style="flex-shrink:0;width:8px;height:8px;border-radius:50%;background:{{ $probSt[$pr->status] ?? 'var(--muted)' }};"></span>
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:13px;color:var(--ink);font-weight:500;font-family:var(--font-mono);">{{ $pr->start_date?->format('d M Y') }} → {{ $pr->end_date?->format('d M Y') }}</div>
                                <div style="font-size:11.5px;color:var(--muted);">{{ $pr->length_days }} <span x-text="$store.ui.lang==='en' ? 'days' : 'hari'">days</span></div>
                            </div>
                            <span style="font-size:11px;font-weight:600;color:{{ $probSt[$pr->status] ?? 'var(--muted)' }};text-transform:capitalize;white-space:nowrap;">{{ $pr->status }}</span>
                        </div>
                    @empty
                        <p style="font-size:12.5px;color:var(--muted);margin:0;" x-text="$store.ui.lang==='en' ? 'No probation record.' : 'Tiada rekod percubaan.'">No probation record.</p>
                    @endforelse
                </div>
                @endif

                @if ($skillsGate ?? false)
                <div>
                    <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Skill matrix' : 'Matriks kemahiran'">Skill matrix</span></div>
                    @forelse ($skills ?? [] as $es)
                        <div class="uj-row" style="display:flex;align-items:center;gap:10px;padding:10px 0;border-top:1px solid var(--hairline-soft);">
                            <span style="flex-shrink:0;width:8px;height:8px;border-radius:50%;background:{{ $es->verified ? 'var(--success)' : 'var(--muted-soft)' }};"></span>
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:13px;color:var(--ink);font-weight:500;">{{ $es->skill?->name ?? '—' }}</div>
                                <div style="font-size:11.5px;color:var(--muted);">{{ $es->level_label }}@if (! $es->verified) · <span x-text="$store.ui.lang==='en' ? 'self-rated' : 'nilai sendiri'"></span>@endif</div>
                            </div>
                        </div>
                    @empty
                        <p style="font-size:12.5px;color:var(--muted);margin:0;" x-text="$store.ui.lang==='en' ? 'No skills recorded.' : 'Tiada kemahiran direkodkan.'">No skills recorded.</p>
                    @endforelse
                </div>
                @endif
            </div>
            @endif

            {{-- Money · payslips, claims, loans, overtime --}}
            @if ($moneyShow)
            <div x-show="tab === 'money'" x-cloak class="uj-tab-stack" style="padding:20px;">
                @if ($payrollGate ?? false)
                <div>
                    <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Payslips' : 'Slip gaji'">Payslips</span></div>
                    @forelse ($payslips ?? [] as $ps)
                        <div class="uj-row" style="display:flex;align-items:center;gap:10px;padding:10px 0;border-top:1px solid var(--hairline-soft);">
                            <span style="flex-shrink:0;width:8px;height:8px;border-radius:50%;background:var(--success);"></span>
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:13px;color:var(--ink);font-weight:500;font-family:var(--font-mono);">{{ $money($ps->net_pay) }}</div>
                                <div style="font-size:11.5px;color:var(--muted);">{{ $ps->created_at?->format('d M Y') }}</div>
                            </div>
                            <span style="font-size:11.5px;color:var(--muted);font-family:var(--font-mono);white-space:nowrap;"><span x-text="$store.ui.lang==='en' ? 'Gross' : 'Kasar'"></span> {{ $money($ps->gross) }}</span>
                        </div>
                    @empty
                        <p style="font-size:12.5px;color:var(--muted);margin:0;" x-text="$store.ui.lang==='en' ? 'No payslips yet.' : 'Tiada slip gaji lagi.'">No payslips yet.</p>
                    @endforelse
                </div>
                @endif

                @if ($claimsGate ?? false)
                <div>
                    <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Claims' : 'Tuntutan'">Claims</span></div>
                    @forelse ($claims ?? [] as $cl)
                        <div class="uj-row" style="display:flex;align-items:center;gap:10px;padding:10px 0;border-top:1px solid var(--hairline-soft);">
                            <span style="flex-shrink:0;width:8px;height:8px;border-radius:50%;background:{{ $claimSt[$cl->status] ?? 'var(--muted)' }};"></span>
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:13px;color:var(--ink);font-weight:500;">{{ $cl->title ?? $cl->type }}</div>
                                <div style="font-size:11.5px;color:var(--muted);font-family:var(--font-mono);">{{ $cl->date?->format('d M Y') }}</div>
                            </div>
                            <div style="text-align:right;white-space:nowrap;">
                                <div style="font-size:13px;color:var(--ink);font-weight:600;font-family:var(--font-mono);">{{ $money($cl->amount) }}</div>
                                <div style="font-size:11px;font-weight:600;color:{{ $claimSt[$cl->status] ?? 'var(--muted)' }};text-transform:capitalize;">{{ $cl->status }}</div>
                            </div>
                        </div>
                    @empty
                        <p style="font-size:12.5px;color:var(--muted);margin:0;" x-text="$store.ui.lang==='en' ? 'No claims yet.' : 'Tiada tuntutan lagi.'">No claims yet.</p>
                    @endforelse
                </div>
                @endif

                @if ($loansGate ?? false)
                <div>
                    <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Loans & advances' : 'Pinjaman & pendahuluan'">Loans & advances</span></div>
                    @forelse ($loans ?? [] as $ln)
                        <div class="uj-row" style="display:flex;align-items:center;gap:10px;padding:10px 0;border-top:1px solid var(--hairline-soft);">
                            <span style="flex-shrink:0;width:8px;height:8px;border-radius:50%;background:{{ $loanSt[$ln->status] ?? 'var(--muted)' }};"></span>
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:13px;color:var(--ink);font-weight:500;text-transform:capitalize;">{{ $ln->type }}</div>
                                <div style="font-size:11.5px;color:var(--muted);">{{ $ln->installments }} <span x-text="$store.ui.lang==='en' ? 'installments' : 'ansuran'"></span></div>
                            </div>
                            <div style="text-align:right;white-space:nowrap;">
                                <div style="font-size:13px;color:var(--ink);font-weight:600;font-family:var(--font-mono);">{{ $money($ln->amount) }}</div>
                                <div style="font-size:11px;font-weight:600;color:{{ $loanSt[$ln->status] ?? 'var(--muted)' }};text-transform:capitalize;">{{ $ln->status }}</div>
                            </div>
                        </div>
                    @empty
                        <p style="font-size:12.5px;color:var(--muted);margin:0;" x-text="$store.ui.lang==='en' ? 'No loans or advances.' : 'Tiada pinjaman atau pendahuluan.'">No loans or advances.</p>
                    @endforelse
                </div>
                @endif

                @if ($overtimeGate ?? false)
                <div>
                    <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Overtime' : 'Kerja lebih masa'">Overtime</span></div>
                    @forelse ($overtime ?? [] as $ot)
                        <div class="uj-row" style="display:flex;align-items:center;gap:10px;padding:10px 0;border-top:1px solid var(--hairline-soft);">
                            <span style="flex-shrink:0;width:8px;height:8px;border-radius:50%;background:{{ $otSt[$ot->status] ?? 'var(--muted)' }};"></span>
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:13px;color:var(--ink);font-weight:500;font-family:var(--font-mono);">{{ $ot->ot_date?->format('d M Y') }}</div>
                                <div style="font-size:11.5px;color:var(--muted);">{{ $ot->hours }}h · ×{{ $ot->rate_multiplier }}</div>
                            </div>
                            <span style="font-size:11px;font-weight:600;color:{{ $otSt[$ot->status] ?? 'var(--muted)' }};text-transform:capitalize;white-space:nowrap;">{{ $ot->status }}</span>
                        </div>
                    @empty
                        <p style="font-size:12.5px;color:var(--muted);margin:0;" x-text="$store.ui.lang==='en' ? 'No overtime records.' : 'Tiada rekod kerja lebih masa.'">No overtime records.</p>
                    @endforelse
                </div>
                @endif
            </div>
            @endif

            {{-- Assets & Training · merged --}}
            <div x-show="tab === 'assets'" x-cloak style="padding:6px 0;">
                <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;padding:14px 20px 6px;"><span x-text="$store.ui.lang==='en' ? 'Assets' : 'Aset'">Assets</span></div>
                @forelse ($p->assets as $a)
                    <div class="uj-row" style="display:flex;align-items:center;gap:12px;padding:12px 20px;border-bottom:1px solid var(--hairline-soft);">
                        <span style="font-size:18px;flex-shrink:0;">{{ $aIcon[$a->category] ?? '📦' }}</span>
                        <div style="flex:1;min-width:0;"><div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $a->name }}</div><div style="font-size:11.5px;color:var(--muted);text-transform:capitalize;">{{ $a->category }}@if ($a->serial) · <span style="font-family:var(--font-mono);text-transform:none;">{{ $a->serial }}</span>@endif</div></div>
                        <span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:{{ $aSc[$a->status] ?? 'var(--muted)' }};white-space:nowrap;"><span style="width:8px;height:8px;border-radius:50%;background:{{ $aSc[$a->status] ?? 'var(--muted)' }};"></span>{{ ucfirst($a->status) }}</span>
                    </div>
                @empty
                    <div style="padding:32px 20px;text-align:center;font-size:13px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'No assets assigned to this person.' : 'Tiada aset ditugaskan kepada orang ini.'">No assets assigned to this person.</div>
                @endforelse

                <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;padding:16px 20px 6px;border-top:1px solid var(--hairline-soft);margin-top:8px;"><span x-text="$store.ui.lang==='en' ? 'Training' : 'Latihan'">Training</span></div>
                @forelse ($p->trainingRecords as $r)
                    @php $isOverdue = $r->status !== 'completed' && $r->due_at && $r->due_at->isPast(); @endphp
                    <div class="uj-row" style="display:flex;align-items:center;gap:12px;padding:12px 20px;border-bottom:1px solid var(--hairline-soft);">
                        <div style="flex:1;min-width:0;"><div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $r->course }}</div><div style="font-size:11.5px;color:var(--muted);">{{ $r->provider }}@if ($r->mandatory) · <span style="color:var(--red);font-weight:600;">Mandatory</span>@endif</div></div>
                        <span style="font-size:12px;font-family:var(--font-mono);color:{{ $isOverdue ? 'var(--error)' : 'var(--muted)' }};white-space:nowrap;">{{ $r->due_at?->format('j M Y') ?? '—' }}{{ $isOverdue ? ' ⚠' : '' }}</span>
                        <span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:{{ $tSc[$r->status] ?? 'var(--muted)' }};white-space:nowrap;"><span style="width:8px;height:8px;border-radius:50%;background:{{ $tSc[$r->status] ?? 'var(--muted)' }};"></span>{{ $tSl[$r->status] ?? ucfirst($r->status) }}</span>
                    </div>
                @empty
                    <div style="padding:32px 20px;text-align:center;font-size:13px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'No training records.' : 'Tiada rekod latihan.'">No training records.</div>
                @endforelse
            </div>
        </div>
    </div>
@endif
@endsection
