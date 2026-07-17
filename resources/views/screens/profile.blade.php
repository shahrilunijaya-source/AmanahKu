@extends('layouts.app')

@php use App\Support\Amanahku; $p = $profile; $pers = $p?->personality ?? []; @endphp

@section('screen')
@include('partials.guide', [
    'key' => 'profile',
    'en'  => [
        'title' => 'Employee profile',
        'body'  => 'One person\'s full record in one place — their role and employment details, leave balance, KPI progress, skills, career timeline and more. HR and management can edit the core details using the "Edit" button on the left.',
    ],
    'ms'  => [
        'title' => 'Profil pekerja',
        'body'  => 'Rekod penuh seseorang dalam satu tempat — jawatan dan butiran pekerjaan, baki cuti, progres KPI, kemahiran, garis masa kerjaya dan banyak lagi. HR dan pengurusan boleh sunting butiran teras guna butang "Edit" di sebelah kiri.',
    ],
])
@if (! $p)
    @include('partials.empty-state', ['variantNote' => 'Profile'])
@else
<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    {{-- Left column --}}
    <div style="flex:1;min-width:280px;max-width:320px;display:flex;flex-direction:column;gap:16px;">
        @php
            $canEdit = in_array($role, ['management', 'hr'], true);
            // The signed-in user is looking at their own record — offer self-service editing.
            $isOwn = isset($employee) && $employee && $p && $employee->id === $p->id;
            $stOpts = ['active' => 'Active', 'probation' => 'Probation', 'on_leave' => 'On Leave', 'resigned' => 'Resigned'];
            $stColor = ['active' => 'var(--success)', 'probation' => 'var(--amber)', 'on_leave' => 'var(--muted-soft)', 'resigned' => 'var(--error)'][$p->status] ?? 'var(--success)';
            $fs = 'height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;width:100%;';
        @endphp
        <div class="uj-card" style="padding:24px;text-align:center;" x-data="{ edit: {{ $errors->any() ? 'true' : 'false' }} }">
            <div style="width:88px;height:88px;border-radius:50%;background:{{ $p->avatar_color }};color:#fff;font-size:30px;font-weight:600;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;">{{ $p->initials }}</div>
            <h3 style="font-size:18px;font-weight:600;color:var(--ink);margin:0;">{{ $p->name }}</h3>
            <p style="font-size:13px;color:var(--muted);margin:4px 0 12px;">{{ $p->positionBand?->title ?? '—' }}</p>
            <span style="display:inline-block;font-size:11px;font-weight:600;color:{{ $stColor }};background:var(--canvas);padding:4px 11px;border-radius:9999px;">{{ $stOpts[$p->status] ?? ucfirst($p->status) }}</span>
            <div style="margin-top:18px;display:flex;gap:8px;">
                @if (($msgEnabled ?? false) && ! $isOwn)
                    {{-- Opens the in-app Messages screen pre-targeting this person. The
                         conversation row is created lazily on the first message sent. --}}
                    <a href="{{ route('app.screen', 'messages') }}?to={{ $p->id }}" class="uj-btn-primary" style="flex:1;height:38px;font-size:13px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;"><span x-text="$store.ui.lang==='en' ? 'Message' : 'Mesej'">Message</span></a>
                @endif
                @if ($canEdit)<button @click="edit = ! edit" class="uj-btn-ghost" style="flex:1;height:38px;font-size:13px;"><span x-text="edit ? ($store.ui.lang==='en' ? 'Close' : 'Tutup') : ($store.ui.lang==='en' ? 'Edit' : 'Sunting')"></span></button>@endif
            </div>
            @if ($isOwn)
                <a href="{{ route('welcome.show') }}" class="uj-btn-ghost" style="display:flex;align-items:center;justify-content:center;height:36px;font-size:12.5px;margin-top:8px;text-decoration:none;">
                    <span x-text="$store.ui.lang==='en' ? 'Edit my personal details' : 'Sunting butiran peribadi saya'">Edit my personal details</span>
                </a>
            @endif

            @if ($canEdit)
                @php $bandsByDept = $allPositions->groupBy(fn ($pos) => $pos->department?->name ?? '—'); @endphp
                <form method="post" action="{{ route('employees.update', $p) }}" x-show="edit" x-cloak
                      x-data="{ pid: '{{ old('position_id', $p->position_id) }}', max: @js($allPositions->mapWithKeys(fn ($pos) => [$pos->id => (float) $pos->max_salary])) }"
                      style="margin-top:16px;padding-top:16px;border-top:1px solid var(--hairline-soft);text-align:left;display:flex;flex-direction:column;gap:10px;">
                    @csrf
                    @if ($errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;">{{ $errors->first() }}</div>@endif
                    <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'Full name' : 'Nama penuh'">Full name</span></label><input name="name" type="text" value="{{ old('name', $p->name) }}" required maxlength="120" style="{{ $fs }}" /></div>
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
                </form>
                {{-- Provision a login for a directory record that has an email but no account yet. --}}
                @if ($p->email && ! $p->user_id)
                    <form method="post" action="{{ route('members.create-login', $p) }}" x-show="edit" x-cloak style="margin-top:8px;text-align:left;">
                        @csrf
                        <button type="submit" class="uj-btn-ghost" style="height:38px;font-size:12.5px;width:100%;"><span x-text="$store.ui.lang==='en' ? 'Create login' : 'Cipta login'">Create login</span></button>
                        <p style="font-size:11px;color:var(--muted);margin:6px 0 0;"><span x-text="$store.ui.lang==='en' ? 'Sends an email invite to this address to activate the account and set a password.' : 'Menghantar jemputan emel ke alamat ini untuk mengaktifkan akaun dan menetapkan kata laluan.'"></span></p>
                    </form>
                @elseif ($p->user_id)
                    <p x-show="edit" x-cloak style="font-size:11px;color:var(--muted);margin-top:8px;text-align:left;"><span x-text="$store.ui.lang==='en' ? 'This person already has a login.' : 'Orang ini sudah ada login.'">This person already has a login.</span></p>
                    {{-- Reset password: mints a fresh one-time password shown to HR once (see MemberController::resetPassword). The employee must change it on next sign-in. --}}
                    <form method="post" action="{{ route('members.reset-password', $p) }}" x-show="edit" x-cloak
                          @submit="if (! confirm($store.ui.lang==='en' ? @js('Reset the password for '.$p->name.'? A new one-time password will be shown to you and they must change it on next sign-in.') : @js('Set semula kata laluan '.$p->name.'? Kata laluan sekali guna baharu akan dipaparkan kepada anda dan mereka mesti menukarnya semasa log masuk seterusnya.'))) $event.preventDefault();"
                          style="margin-top:8px;text-align:left;">
                        @csrf
                        <button type="submit" class="uj-btn-ghost" style="height:38px;font-size:12.5px;width:100%;"><span x-text="$store.ui.lang==='en' ? 'Reset password' : 'Set semula kata laluan'">Reset password</span></button>
                        <p style="font-size:11px;color:var(--muted);margin:6px 0 0;"><span x-text="$store.ui.lang==='en' ? 'Generates a new one-time password shown to you once. The employee must set their own password on next sign-in.' : 'Menjana kata laluan sekali guna baharu yang dipaparkan kepada anda sekali sahaja. Pekerja mesti menetapkan kata laluan sendiri semasa log masuk seterusnya.'"></span></p>
                    </form>
                @endif
                {{-- Archive (soft-delete): hides the person from the directory; history kept, restorable. Separate form so it never nests inside the edit form. --}}
                <form method="post" action="{{ route('employees.destroy', $p) }}" x-show="edit" x-cloak
                      @submit="if (! confirm($store.ui.lang==='en' ? @js('Archive '.$p->name.'? They will be removed from the directory. Their history is kept and they can be restored.') : @js('Arkib '.$p->name.'? Mereka akan dikeluarkan dari direktori. Sejarah dikekalkan dan boleh dipulihkan.'))) $event.preventDefault();"
                      style="margin-top:8px;text-align:left;">
                    @csrf
                    <button type="submit" class="uj-btn-ghost" style="height:38px;font-size:12.5px;width:100%;color:var(--red);border-color:var(--red);"><span x-text="$store.ui.lang==='en' ? 'Archive staff' : 'Arkib staf'">Archive staff</span></button>
                </form>
            @endif
        </div>

        <div class="uj-card" style="padding:20px;">
            <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Employment' : 'Pekerjaan'">Employment</span></div>
            <div style="display:flex;flex-direction:column;gap:11px;font-size:13px;">
                <div style="display:flex;justify-content:space-between;"><span style="color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Department' : 'Jabatan'">Department</span></span><span style="color:var(--ink);">{{ $p->department?->name }}</span></div>
                <div style="display:flex;justify-content:space-between;"><span style="color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Branch' : 'Cawangan'">Branch</span></span><span style="color:var(--ink);">{{ $p->branch?->name }}</span></div>
                <div style="display:flex;justify-content:space-between;"><span style="color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Reports to' : 'Melapor kepada'">Reports to</span></span><span style="color:var(--ink);">{{ $p->reportsTo?->name ?? '—' }}</span></div>
                <div style="display:flex;justify-content:space-between;"><span style="color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Joined' : 'Menyertai'">Joined</span></span><span style="color:var(--ink);font-family:var(--font-mono);">{{ $p->joined_at?->format('d M Y') }}</span></div>
                <div style="display:flex;justify-content:space-between;"><span style="color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Staff ID' : 'ID Staf'">Staff ID</span></span><span style="color:var(--ink);font-family:var(--font-mono);">{{ $p->staff_id }}</span></div>
            </div>
        </div>

        @if ($canSeeAttendance ?? false)
        <div class="uj-card" style="padding:20px;">
            <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Attendance · this month' : 'Kehadiran · bulan ini'">Attendance · this month</span></div>
            @forelse ($attendance ?? [] as $a)
                @php
                    $aLate = $a->status === 'late';
                    $aIn   = $a->clock_in ? substr((string) $a->clock_in, 0, 5) : '—';
                    $aOut  = $a->clock_out ? substr((string) $a->clock_out, 0, 5) : '—';
                    $aDot  = $a->clock_in ? ($aLate ? 'var(--amber)' : 'var(--success)') : 'var(--muted-soft)';
                @endphp
                <div style="display:flex;align-items:center;gap:10px;padding:9px 0;border-top:1px solid var(--hairline-soft);">
                    <span style="flex-shrink:0;width:8px;height:8px;border-radius:50%;background:{{ $aDot }};"></span>
                    <span style="font-size:12.5px;color:var(--ink);font-weight:500;min-width:54px;">{{ $a->date->format('d M') }}</span>
                    <span style="font-size:12.5px;color:var(--body);font-family:var(--font-mono);">{{ $aIn }} → {{ $aOut }}</span>
                    @if ($aLate)<span style="font-size:10.5px;color:var(--amber);font-weight:600;margin-left:auto;"><span x-text="$store.ui.lang==='en' ? 'Late' : 'Lewat'">Late</span></span>@endif
                </div>
            @empty
                <p style="font-size:12.5px;color:var(--muted-soft);margin:0;" x-text="$store.ui.lang==='en' ? 'No attendance recorded this month.' : 'Tiada kehadiran direkod bulan ini.'">No attendance recorded this month.</p>
            @endforelse
        </div>
        @endif

        @if (($canAssign ?? false) && ! $p->isArchived())
    <div class="uj-card" style="padding:20px;" x-data="{ assign: {{ $errors->getBag('assign')->any() ? 'true' : 'false' }} }">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
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
            <p style="font-size:12.5px;color:var(--muted-soft);margin:0;" x-text="$store.ui.lang==='en' ? 'No tasks assigned to this person yet.' : 'Tiada tugas diberi kepada orang ini lagi.'">No tasks assigned to this person yet.</p>
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
                        <input name="due_at" type="date" value="{{ old('due_at') }}" style="{{ $fs }}" />
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

        @if ($p->skills)
        <div class="uj-card" style="padding:20px;">
            <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Skills' : 'Kemahiran'">Skills</span></div>
            <div style="display:flex;flex-wrap:wrap;gap:7px;">@foreach ($p->skills as $s)<span style="font-size:12px;color:var(--ink);background:var(--canvas);border:1px solid var(--hairline);padding:5px 11px;border-radius:9999px;">{{ $s }}</span>@endforeach</div>
        </div>
        @endif

        @if ($pers)
        <div class="uj-card" style="padding:20px;">
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
        <div class="uj-card" style="padding:20px;">
            <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Career interests' : 'Minat kerjaya'">Career interests</span></div>
            <div style="display:flex;flex-wrap:wrap;gap:7px;">@foreach ($p->interests as $i)<span style="font-size:12px;color:var(--red);background:var(--red-tint);padding:5px 11px;border-radius:9999px;font-weight:500;">{{ $i }}</span>@endforeach</div>
        </div>
        @endif
    </div>

    {{-- Right column --}}
    <div style="flex:2;min-width:380px;display:flex;flex-direction:column;gap:16px;">
        <div style="display:flex;gap:16px;flex-wrap:wrap;">
            <div class="uj-card" style="flex:1;min-width:120px;padding:16px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Annual leave' : 'Cuti tahunan'">Annual leave</span></div><div class="uj-stat-value" style="font-size:22px;">{{ $p->annualLeaveBalance() }} <span style="font-size:12px;color:var(--muted-soft);font-weight:400;"><span x-text="$store.ui.lang==='en' ? 'days' : 'hari'">days</span></span></div></div>
            @if ($perfEnabled ?? true)
            <div class="uj-card" style="flex:1;min-width:120px;padding:16px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'KPI · H1' : 'KPI · H1'">KPI · H1</span></div><div class="uj-stat-value" style="font-size:22px;color:var(--success);">{{ $p->kpi_pct }}%</div></div>
            @endif
            <div class="uj-card" style="flex:1;min-width:120px;padding:16px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Workload' : 'Beban kerja'">Workload</span></div><div style="font-size:15px;font-weight:600;color:{{ Amanahku::SWATCH[$p->workload] }};margin-top:5px;">● {{ $p->workload_label }}</div></div>
            <div class="uj-card" style="flex:1;min-width:120px;padding:16px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Open tasks' : 'Tugas terbuka'">Open tasks</span></div><div class="uj-stat-value" style="font-size:22px;">{{ $p->workItems->whereIn('status', ['todo','prog','review'])->count() }}</div></div>
        </div>

        @php
            // Read-only lookup maps for the profile tabs (mirrors the standalone screens).
            $wTag    = ['assignment' => ['Assignment', 'var(--red)'], 'task' => ['Task', 'var(--info)'], 'adhoc' => ['Adhoc', 'var(--amber)']];
            $wStatus = ['todo' => ['To Do', 'var(--muted-soft)'], 'prog' => ['In Progress', 'var(--info)'], 'review' => ['In Review', 'var(--amber)'], 'done' => ['Done', 'var(--success)']];
            $wPri    = ['high' => 'var(--error)', 'medium' => 'var(--amber)', 'low' => 'var(--muted)'];
            $wOrder  = ['todo' => 0, 'prog' => 1, 'review' => 2, 'done' => 3];
            $wItems  = $p->workItems->sortBy(fn ($w) => $wOrder[$w->status] ?? 9)->values();
            $aIcon   = ['laptop' => '💻', 'phone' => '📱', 'vehicle' => '🚗', 'furniture' => '🪑', 'other' => '📦'];
            $aSc     = ['assigned' => 'var(--info)', 'available' => 'var(--success)', 'maintenance' => 'var(--amber)', 'retired' => 'var(--muted-soft)'];
            $tSc     = ['completed' => 'var(--success)', 'in_progress' => 'var(--info)', 'not_started' => 'var(--muted-soft)'];
            $tSl     = ['completed' => 'Completed', 'in_progress' => 'In progress', 'not_started' => 'Not started'];
            $tabs    = [['overview', 'Overview', 'Gambaran'], ['work', 'Work & Tasks', 'Kerja & Tugas'], ['kpi', 'KPI History', 'Sejarah KPI'], ['documents', 'Documents', 'Dokumen'], ['assets', 'Assets', 'Aset'], ['training', 'Training', 'Latihan']];
            // Drop the KPI History tab when Performance is off for this company.
            if (! ($perfEnabled ?? true)) {
                $tabs = array_values(array_filter($tabs, fn ($t) => $t[0] !== 'kpi'));
            }
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

            {{-- Overview · career timeline --}}
            <div x-show="tab === 'overview'" style="padding:20px;">
                <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Career timeline' : 'Garis masa kerjaya'">Career timeline</span></div>
                @forelse ($p->careerTimeline->sortByDesc('sort') as $c)
                    <div style="display:flex;gap:14px;padding-bottom:16px;"><div style="width:10px;height:10px;border-radius:50%;background:{{ Amanahku::SWATCH[$c->category] ?? 'var(--muted-soft)' }};margin-top:4px;flex-shrink:0;"></div><div><div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $c->title }}</div><div style="font-size:12px;color:var(--muted);font-family:var(--font-mono);">{{ $c->date_label }}</div></div></div>
                @empty
                    <div style="padding:24px 4px;text-align:center;font-size:13px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'No career history yet.' : 'Tiada sejarah kerjaya lagi.'">No career history yet.</div>
                @endforelse
            </div>

            {{-- Work & Tasks · this employee's work items, open ones first --}}
            <div x-show="tab === 'work'" x-cloak style="padding:6px 0;">
                @forelse ($wItems as $w)
                    @php [$tl, $tc] = $wTag[$w->type] ?? ['Task', 'var(--info)']; [$sl, $scol] = $wStatus[$w->status] ?? ['—', 'var(--muted-soft)']; @endphp
                    <div style="display:flex;align-items:center;gap:12px;padding:12px 20px;border-bottom:1px solid var(--hairline-soft);">
                        <div style="flex:1;min-width:0;">
                            <div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $w->title }}</div>
                            <div style="font-size:11.5px;color:var(--muted-soft);margin-top:2px;"><span style="color:{{ $tc }};font-weight:600;">{{ $tl }}</span>@if ($w->due_label) · {{ $w->due_label }}@endif @if ($w->estimate_hours) · <span style="font-family:var(--font-mono);">{{ $w->estimate_hours }}h</span>@endif</div>
                        </div>
                        @if ($w->priority)<span style="font-size:10.5px;font-weight:600;color:{{ $wPri[$w->priority] ?? 'var(--muted)' }};">{{ ucfirst($w->priority) }}</span>@endif
                        <span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:{{ $scol }};white-space:nowrap;"><span style="width:8px;height:8px;border-radius:50%;background:{{ $scol }};"></span>{{ $sl }}</span>
                    </div>
                @empty
                    <div style="padding:32px 20px;text-align:center;font-size:13px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'No work items assigned.' : 'Tiada item kerja ditugaskan.'">No work items assigned.</div>
                @endforelse
            </div>

            {{-- KPI History · objectives with progress (hidden when Performance module is off) --}}
            @if ($perfEnabled ?? true)
            <div x-show="tab === 'kpi'" x-cloak style="padding:6px 0;">
                @forelse ($p->kpiItems as $k)
                    <div style="padding:13px 20px;border-bottom:1px solid var(--hairline-soft);">
                        <div style="display:flex;justify-content:space-between;gap:12px;margin-bottom:7px;">
                            <div style="min-width:0;"><div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $k->title }}</div><div style="font-size:11px;color:var(--muted-soft);text-transform:capitalize;">{{ $k->category }}</div></div>
                            <div style="text-align:right;white-space:nowrap;"><span style="font-size:12.5px;color:var(--ink);font-weight:600;font-family:var(--font-mono);">{{ $k->actual }}</span><span style="font-size:11.5px;color:var(--muted);"> / {{ $k->target }}</span></div>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;"><div class="uj-progress" style="flex:1;"><span style="width:{{ $k->progress }}%;background:{{ Amanahku::SWATCH[$k->status] ?? 'var(--success)' }};"></span></div><span style="font-size:11px;color:var(--muted);font-family:var(--font-mono);">{{ $k->progress }}%</span><span style="font-size:11px;color:var(--muted-soft);">· w{{ $k->weight }}</span></div>
                    </div>
                @empty
                    <div style="padding:32px 20px;text-align:center;font-size:13px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'No KPI objectives set.' : 'Tiada objektif KPI ditetapkan.'">No KPI objectives set.</div>
                @endforelse
            </div>
            @endif

            {{-- Documents · managed centrally, link out --}}
            <div x-show="tab === 'documents'" x-cloak style="padding:34px 20px;text-align:center;">
                <div style="font-size:13.5px;color:var(--ink);font-weight:500;margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Documents live in the Documents section' : 'Dokumen berada dalam bahagian Dokumen'">Documents live in the Documents section</div>
                <div style="font-size:12.5px;color:var(--muted);line-height:1.5;margin-bottom:16px;max-width:380px;margin-left:auto;margin-right:auto;" x-text="$store.ui.lang==='en' ? 'Company files, policies and shared documents are managed centrally.' : 'Fail syarikat, polisi dan dokumen kongsi diurus secara berpusat.'">Company files, policies and shared documents are managed centrally.</div>
                <a href="{{ route('app.screen', 'documents') }}" class="uj-btn-ghost" style="display:inline-flex;height:36px;align-items:center;padding:0 16px;font-size:13px;text-decoration:none;"><span x-text="$store.ui.lang==='en' ? 'Open Documents' : 'Buka Dokumen'">Open Documents</span></a>
            </div>

            {{-- Assets · items held by this employee --}}
            <div x-show="tab === 'assets'" x-cloak style="padding:6px 0;">
                @forelse ($p->assets as $a)
                    <div style="display:flex;align-items:center;gap:12px;padding:12px 20px;border-bottom:1px solid var(--hairline-soft);">
                        <span style="font-size:18px;flex-shrink:0;">{{ $aIcon[$a->category] ?? '📦' }}</span>
                        <div style="flex:1;min-width:0;"><div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $a->name }}</div><div style="font-size:11.5px;color:var(--muted-soft);text-transform:capitalize;">{{ $a->category }}@if ($a->serial) · <span style="font-family:var(--font-mono);text-transform:none;">{{ $a->serial }}</span>@endif</div></div>
                        <span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:{{ $aSc[$a->status] ?? 'var(--muted)' }};white-space:nowrap;"><span style="width:8px;height:8px;border-radius:50%;background:{{ $aSc[$a->status] ?? 'var(--muted)' }};"></span>{{ ucfirst($a->status) }}</span>
                    </div>
                @empty
                    <div style="padding:32px 20px;text-align:center;font-size:13px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'No assets assigned to this person.' : 'Tiada aset ditugaskan kepada orang ini.'">No assets assigned to this person.</div>
                @endforelse
            </div>

            {{-- Training · courses & certifications --}}
            <div x-show="tab === 'training'" x-cloak style="padding:6px 0;">
                @forelse ($p->trainingRecords as $r)
                    @php $isOverdue = $r->status !== 'completed' && $r->due_at && $r->due_at->isPast(); @endphp
                    <div style="display:flex;align-items:center;gap:12px;padding:12px 20px;border-bottom:1px solid var(--hairline-soft);">
                        <div style="flex:1;min-width:0;"><div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $r->course }}</div><div style="font-size:11.5px;color:var(--muted-soft);">{{ $r->provider }}@if ($r->mandatory) · <span style="color:var(--red);font-weight:600;">Mandatory</span>@endif</div></div>
                        <span style="font-size:12px;font-family:var(--font-mono);color:{{ $isOverdue ? 'var(--error)' : 'var(--muted)' }};white-space:nowrap;">{{ $r->due_at?->format('j M Y') ?? '—' }}{{ $isOverdue ? ' ⚠' : '' }}</span>
                        <span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:{{ $tSc[$r->status] ?? 'var(--muted)' }};white-space:nowrap;"><span style="width:8px;height:8px;border-radius:50%;background:{{ $tSc[$r->status] ?? 'var(--muted)' }};"></span>{{ $tSl[$r->status] ?? ucfirst($r->status) }}</span>
                    </div>
                @empty
                    <div style="padding:32px 20px;text-align:center;font-size:13px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'No training records.' : 'Tiada rekod latihan.'">No training records.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endif
@endsection
