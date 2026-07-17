@extends('layouts.app')

@section('screen')
@php $fs = 'height:40px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;width:100%;'; @endphp
@include('partials.guide', [
    'key' => 'roles',
    'en'  => [
        'title' => 'Members & access roles',
        'body'  => 'Invite people into the workspace and set what each one can do. A member\'s role decides their access and approval rights — Manager, Management and HR can approve leave, claims and more, so assign higher roles only to people who should have that power.',
        'who'   => 'Admins invite & assign roles',
        'steps' => [
            'Click "+ Add member", enter their name and work email, and pick a starting role (Employee or Manager).',
            'After adding, change anyone\'s role from the dropdown in their row — it saves the moment you pick.',
            'Management and HR are powerful: they can approve requests and see sensitive data. Give those out sparingly.',
            'Role changes apply on the member\'s next page load.',
        ],
    ],
    'ms'  => [
        'title' => 'Ahli & role akses',
        'body'  => 'Jemput orang masuk ke workspace dan tetapkan apa yang setiap seorang boleh buat. Role seseorang ahli menentukan akses dan hak kelulusan mereka — Manager, Management dan HR boleh luluskan cuti, tuntutan dan banyak lagi, jadi berikan role lebih tinggi hanya kepada orang yang sepatutnya ada kuasa itu.',
        'who'   => 'Admin jemput & tetapkan role',
        'steps' => [
            'Klik "+ Add member", masukkan nama dan emel kerja mereka, dan pilih role permulaan (Employee atau Manager).',
            'Selepas ditambah, tukar role sesiapa dari dropdown di baris mereka — ia tersimpan sebaik anda pilih.',
            'Management dan HR berkuasa besar: mereka boleh luluskan permohonan dan lihat data sensitif. Berikannya berhemat.',
            'Perubahan role berkuat kuasa pada muatan halaman seterusnya ahli tersebut.',
        ],
    ],
])
<div x-data="{ add: {{ $errors->any() ? 'true' : 'false' }} }">
<div style="display:flex;justify-content:flex-end;margin-bottom:14px;">
    <button @click="add = ! add" class="uj-btn-primary" style="height:38px;padding:0 16px;font-size:13px;"><span x-text="add ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal') : ($store.ui.lang==='en' ? '+ Add member' : '+ Tambah ahli')"></span></button>
</div>
<div x-show="add" x-cloak class="uj-card" style="padding:20px;margin-bottom:16px;">
    <h3 class="uj-card-title" style="margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Invite a member' : 'Jemput ahli'">Invite a member</h3>
    <p style="font-size:12.5px;color:var(--muted);margin:0 0 14px;" x-text="$store.ui.lang==='en' ? 'Creates a sign-in account in this workspace. If the email already exists, they are added to this tenant.' : 'Mencipta akaun log masuk dalam workspace ini. Jika emel sudah wujud, mereka ditambah ke tenant ini.'">Creates a sign-in account in this workspace. If the email already exists, they're added to this tenant.</p>
    <form method="post" action="{{ route('members.store') }}">
        @csrf
        @if ($errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>@endif
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;">
            <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Full name *' : 'Nama penuh *'">Full name *</label><input name="name" value="{{ old('name') }}" required maxlength="120" style="{{ $fs }}" /></div>
            <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Work email *' : 'Emel kerja *'">Work email *</label><input name="email" type="email" value="{{ old('email') }}" required maxlength="160" style="{{ $fs }}" /></div>
            <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Position' : 'Jawatan'">Position</label><input name="position" value="{{ old('position') }}" maxlength="120" style="{{ $fs }}" /></div>
            <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Role' : 'Peranan'">Role</label><select name="role" style="{{ $fs }}margin-bottom:6px;">@foreach (['employee' => 'Employee', 'manager' => 'Manager'] as $v => $l)<option value="{{ $v }}" @selected(old('role', 'employee') === $v)>{{ $l }}</option>@endforeach</select>@include('partials.hint', ['en' => 'Employee is the safe default. Manager can approve their team\'s requests. Promote to Management or HR from the list below only when needed.', 'ms' => 'Employee ialah pilihan selamat secara lalai. Manager boleh luluskan permohonan pasukan mereka. Naikkan ke Management atau HR dari senarai di bawah hanya apabila perlu.'])</div>
        </div>
        <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;margin-top:16px;"><span x-text="$store.ui.lang==='en' ? 'Add member' : 'Tambah ahli'">Add member</span></button>
    </form>
</div>

<div class="uj-card" x-data="rolesAdmin()">
    {{-- Transient save toast (bottom-center). Replaces the top-of-page flash banner so
         editing a row no longer scrolls the embedded screen back to the top. --}}
    <div x-show="toast" x-cloak x-transition.opacity
         style="position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:60;max-width:calc(100% - 32px);"
         :style="toastErr ? 'border-color:var(--red);color:var(--red);background:var(--red-tint);' : ''">
        <div :style="toastErr
                ? 'background:var(--red-tint);border:1px solid var(--red);color:var(--red);'
                : 'background:var(--ink);border:1px solid var(--ink);color:#fff;'"
             style="border-radius:9px;padding:10px 16px;font-size:13px;font-weight:500;box-shadow:0 6px 24px rgba(0,0,0,.18);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
             x-text="toast"></div>
    </div>
    <div class="uj-card-head"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Workspace members' : 'Ahli workspace'">Workspace members</h3><span style="font-size:12.5px;color:var(--muted);">{{ $members->count() }} <span x-text="$store.ui.lang==='en' ? 'members' : 'ahli'">members</span></span></div>
    <div style="display:grid;grid-template-columns:1.6fr 1.6fr 1.1fr 1.3fr 0.8fr;gap:8px;padding:12px 20px;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;border-bottom:1px solid var(--hairline-soft);"><span x-text="$store.ui.lang==='en' ? 'Member' : 'Ahli'">Member</span><span x-text="$store.ui.lang==='en' ? 'Email' : 'Emel'">Email</span><span x-text="$store.ui.lang==='en' ? 'Role' : 'Peranan'">Role</span><span x-text="$store.ui.lang==='en' ? 'Data scope' : 'Skop data'">Data scope</span><span></span></div>
    @foreach ($members as $m)
        @php $rolePerms = \App\Support\Permissions::forRole($m->pivot->role); $ov = $permOverrides[$m->id] ?? collect(); @endphp
        <div x-data="{ open:false }" style="border-bottom:1px solid var(--hairline-soft);">
            <div style="display:grid;grid-template-columns:1.6fr 1.6fr 1.1fr 1.3fr 0.8fr;gap:8px;padding:12px 20px;align-items:center;">
                <span style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $m->name }}</span>
                <span style="font-size:13px;color:var(--muted);">{{ $m->email }}</span>
                <form method="post" action="{{ route('admin.roles.update', $m) }}" style="display:flex;align-items:center;gap:8px;">
                    @csrf
                    <select name="role" @change="save($event.target.form)" style="height:34px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;background:#fff;color:var(--ink);">
                        @foreach (['employee' => 'Employee', 'manager' => 'Manager', 'management' => 'Management', 'director' => 'Director', 'hr' => 'HR'] as $v => $l)
                            <option value="{{ $v }}" @selected($m->pivot->role === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </form>
                <form method="post" action="{{ route('admin.scope.update', $m) }}" style="display:flex;align-items:center;gap:8px;">
                    @csrf
                    <select name="data_scope" @change="save($event.target.form)" style="height:34px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;background:#fff;color:var(--ink);">
                        @foreach (\App\Support\Permissions::SCOPE_LABELS as $v => $l)
                            <option value="{{ $v }}" @selected(($m->pivot->data_scope ?? 'company') === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </form>
                <button type="button" @click="open=!open" class="uj-btn-ghost" style="height:32px;padding:0 10px;font-size:12px;justify-self:start;">
                    <span x-text="open ? ($store.ui.lang==='en'?'Close':'Tutup') : ($store.ui.lang==='en'?'Permissions':'Kebenaran')">Permissions</span>
                </button>
            </div>

            {{-- Per-user permission overrides. Default 'inherit' follows the role; grant/deny override it. --}}
            <div x-show="open" x-cloak style="padding:0 20px 18px;">
                <form method="post" action="{{ route('admin.permissions.update', $m) }}" @submit.prevent="save($event.target)">
                    @csrf
                    <div style="background:var(--canvas);border:1px solid var(--hairline-soft);border-radius:10px;padding:14px 16px;">
                        <p style="font-size:12px;color:var(--muted);margin:0 0 12px;">Per-member overrides for staff actions (create / update / import). Inherit follows the <strong style="color:var(--ink);text-transform:capitalize;">{{ $m->pivot->role }}</strong> role; grant or deny to override for this member only. Other capabilities follow the role — see the reference below.</p>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;">
                            @foreach ($permissionGroups as $domain => $perms)
                                <div>
                                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:6px;">{{ $domain }}</div>
                                    @foreach ($perms as $perm)
                                        @php $cur = $ov->has($perm) ? ($ov[$perm] ? 'grant' : 'deny') : 'inherit'; $roleHas = in_array($perm, $rolePerms, true); @endphp
                                        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:3px 0;">
                                            <span style="font-size:12px;color:var(--ink);font-family:var(--font-mono);">{{ $perm }}@if ($roleHas)<span style="color:var(--success);font-family:inherit;"> ·role</span>@endif</span>
                                            <select name="perm[{{ $perm }}]" style="height:28px;padding:0 6px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;background:#fff;color:var(--ink);">
                                                <option value="inherit" @selected($cur==='inherit')>Inherit</option>
                                                <option value="grant" @selected($cur==='grant')>Grant</option>
                                                <option value="deny" @selected($cur==='deny')>Deny</option>
                                            </select>
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                        <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:13px;margin-top:14px;"><span x-text="$store.ui.lang==='en' ? 'Save overrides' : 'Simpan kebenaran'">Save overrides</span></button>
                    </div>
                </form>
            </div>
        </div>
    @endforeach
</div>

{{-- Permission reference: what each role can do. Advisory — the role itself is the gate. --}}
<div class="uj-card" style="margin-top:16px;padding:20px;">
    <h3 class="uj-card-title" style="margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'What each role can do' : 'Apa setiap peranan boleh buat'">What each role can do</h3>
    <p style="font-size:12.5px;color:var(--muted);margin:0 0 14px;" x-text="$store.ui.lang==='en' ? 'Reference only. Data scope narrows which records a member sees within their access.' : 'Rujukan sahaja. Skop data mengecilkan rekod yang dilihat ahli dalam akses mereka.'">Reference only. Data scope narrows which records a member sees within their access.</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;">
        @foreach (\App\Support\Permissions::ROLE_PERMISSIONS as $roleName => $perms)
            <div style="border:1px solid var(--hairline-soft);border-radius:10px;padding:12px 14px;">
                <div style="font-size:13px;font-weight:600;color:var(--ink);margin-bottom:8px;text-transform:capitalize;">{{ $roleName }}</div>
                <div style="display:flex;flex-wrap:wrap;gap:5px;">
                    @foreach ($perms as $perm)
                        <span style="font-size:11px;color:var(--body);background:var(--canvas);border:1px solid var(--hairline-soft);border-radius:6px;padding:2px 7px;font-family:var(--font-mono);">{{ $perm }}</span>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</div>
<p style="font-size:12px;color:var(--muted-soft);margin-top:12px;"><span x-text="$store.ui.lang==='en' ? 'Role changes take effect on the member next page load. Approval rights (leave, claims) follow the assigned role.' : 'Perubahan peranan berkuat kuasa pada muatan halaman seterusnya ahli tersebut. Hak kelulusan (cuti, tuntutan) mengikut peranan yang ditetapkan.'">Role changes take effect on the member's next page load. Approval rights (leave, claims) follow the assigned role.</span></p>
</div>
@endsection
