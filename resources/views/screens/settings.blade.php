@extends('layouts.app')

@section('screen')
@include('partials.guide', [
    'key' => 'settings',
    'en'  => [
        'title' => 'Workspace settings',
        'body'  => 'Admin settings for the whole company — the workspace name, subscription plan, and the list of branches and departments. Changes here affect every member, so update them carefully.',
    ],
    'ms'  => [
        'title' => 'Tetapan workspace',
        'body'  => 'Tetapan admin untuk seluruh syarikat — nama workspace, pelan langganan, serta senarai cawangan dan jabatan. Perubahan di sini memberi kesan kepada setiap ahli, jadi kemas kini dengan berhati-hati.',
    ],
])
@php $only = request('section'); @endphp
<div style="{{ $only ? '' : 'display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;' }}">
    @if (! $only || $only === 'profile')
    <div class="uj-card" style="{{ $only ? 'padding:24px;' : 'flex:1.2;min-width:340px;padding:24px;' }}">
        <h3 class="uj-card-title" style="margin-bottom:16px;" x-text="$store.ui.lang==='en' ? 'Workspace profile' : 'Profil workspace'">Workspace profile</h3>
        <form method="post" action="{{ route('admin.settings.update') }}" enctype="multipart/form-data">
            @csrf
            @if ($errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12.5px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>@endif
            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Company name' : 'Nama syarikat'">Company name</label>
            <input name="name" value="{{ old('name', $company->name) }}" required style="width:100%;height:42px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;margin-bottom:6px;outline:none;" />
            @include('partials.hint', ['en' => 'The name shown to everyone across the app and on documents. Changing it updates it for all members.', 'ms' => 'Nama yang dipaparkan kepada semua orang di seluruh aplikasi dan pada dokumen. Menukarnya akan mengemas kini untuk semua ahli.'])

            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin:14px 0 6px;" x-text="$store.ui.lang==='en' ? 'Industry' : 'Industri'">Industry</label>
            <input name="industry" value="{{ old('industry', $company->industry) }}" style="width:100%;height:42px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;" />

            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin:14px 0 6px;" x-text="$store.ui.lang==='en' ? 'Welcome message' : 'Mesej alu-aluan'">Welcome message</label>
            <input name="welcome_message" value="{{ old('welcome_message', $company->welcome_message) }}" placeholder="Shown on your company login page" style="width:100%;height:42px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;" />
            @include('partials.hint', ['en' => 'Greeting shown on your company-branded login page.', 'ms' => 'Ucapan yang dipaparkan pada halaman log masuk berjenama syarikat anda.'])

            <div style="display:flex;gap:12px;">
                <div style="flex:1;">
                    <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin:14px 0 6px;" x-text="$store.ui.lang==='en' ? 'Brand colour' : 'Warna jenama'">Brand colour</label>
                    <input name="color" value="{{ old('color', $company->color) }}" placeholder="#d6232b" style="width:100%;height:42px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;" />
                </div>
                <div style="flex:1;">
                    <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin:14px 0 6px;" x-text="$store.ui.lang==='en' ? 'Secondary colour' : 'Warna sekunder'">Secondary colour</label>
                    <input name="secondary_color" value="{{ old('secondary_color', $company->secondary_color) }}" placeholder="#1f1e1a" style="width:100%;height:42px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;" />
                </div>
            </div>

            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin:14px 0 6px;" x-text="$store.ui.lang==='en' ? 'Company logo' : 'Logo syarikat'">Company logo</label>
            @if ($company->logo_path)
                <img src="/storage/{{ $company->logo_path }}" alt="logo" style="height:40px;border-radius:8px;margin-bottom:8px;display:block;">
            @endif
            <input type="file" name="logo" accept="image/*" style="width:100%;font-size:13px;margin-bottom:6px;" />
            @include('partials.hint', ['en' => 'PNG or JPG up to 2 MB. Appears on your company login page.', 'ms' => 'PNG atau JPG sehingga 2 MB. Dipaparkan pada halaman log masuk syarikat anda.'])

            <div style="display:flex;gap:12px;">
                <div style="flex:1;">
                    <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin:14px 0 6px;" x-text="$store.ui.lang==='en' ? 'Contact number' : 'Nombor telefon'">Contact number</label>
                    <input name="contact_number" value="{{ old('contact_number', $company->contact_number) }}" style="width:100%;height:42px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;" />
                </div>
                <div style="flex:1;">
                    <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin:14px 0 6px;" x-text="$store.ui.lang==='en' ? 'Email' : 'Emel'">Email</label>
                    <input type="email" name="email" value="{{ old('email', $company->email) }}" style="width:100%;height:42px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;" />
                </div>
            </div>

            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin:14px 0 6px;" x-text="$store.ui.lang==='en' ? 'Website' : 'Laman web'">Website</label>
            <input name="website" value="{{ old('website', $company->website) }}" placeholder="https://" style="width:100%;height:42px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;" />

            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin:14px 0 6px;" x-text="$store.ui.lang==='en' ? 'Address' : 'Alamat'">Address</label>
            <input name="address" value="{{ old('address', $company->address) }}" style="width:100%;height:42px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;" />

            {{-- Plan, category, subscription, status and slug are set by the platform team
                 (super-admin) and are read-only here — shown for reference only. --}}
            <div style="display:flex;gap:24px;flex-wrap:wrap;margin:20px 0;padding:14px 16px;background:var(--canvas);border:1px solid var(--hairline-soft);border-radius:10px;">
                <div><div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;" x-text="$store.ui.lang==='en' ? 'Category' : 'Kategori'">Category</div><div style="font-size:14px;color:var(--ink);margin-top:3px;">{{ $company->companyCategory?->name ?? '—' }}</div></div>
                <div><div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;" x-text="$store.ui.lang==='en' ? 'Plan' : 'Pelan'">Plan</div><div style="font-size:14px;color:var(--ink);margin-top:3px;">{{ $company->plan }}</div></div>
                <div><div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;" x-text="$store.ui.lang==='en' ? 'Workspace ID' : 'ID workspace'">Workspace ID</div><div style="font-size:14px;color:var(--ink);font-family:var(--font-mono);margin-top:3px;">{{ $company->slug }}</div></div>
                <div><div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;" x-text="$store.ui.lang==='en' ? 'Members' : 'Ahli'">Members</div><div style="font-size:14px;color:var(--ink);font-family:var(--font-mono);margin-top:3px;">{{ $company->users()->count() }}</div></div>
            </div>

            <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;"><span x-text="$store.ui.lang==='en' ? 'Save changes' : 'Simpan perubahan'">Save changes</span></button>
        </form>
    </div>
    @endif

    <div style="{{ $only ? '' : 'flex:1;min-width:280px;display:flex;flex-direction:column;gap:16px;' }}">

        @if (! $only || $only === 'branches')
        {{-- Branches: name + state CRUD. Geofence/hours live on the Attendance Setup screen. --}}
        <div class="uj-card" style="padding:20px;" @if ($canManageFeatures) x-data="{ adding:false, editId:null }" @endif>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Branches' : 'Cawangan'">Branches</h3>
                @if ($canManageFeatures)
                    <button type="button" @click="adding=!adding;editId=null" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12.5px;">
                        <span x-text="adding ? ($store.ui.lang==='en'?'Cancel':'Batal') : ($store.ui.lang==='en'?'+ Add':'+ Tambah')">+ Add</span>
                    </button>
                @endif
            </div>

            @if ($canManageFeatures)
                @php $bfs = 'height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;outline:none;background:#fff;color:var(--ink);min-width:0;'; @endphp
                <form x-show="adding" x-cloak method="post" action="{{ route('admin.branches.store') }}" style="margin-bottom:14px;">
                    @csrf
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px;">
                        <input name="name" required :placeholder="$store.ui.lang==='en'?'Branch name *':'Nama cawangan *'" style="{{ $bfs }}" />
                        <input name="code" placeholder="Code" style="{{ $bfs }}" />
                        <select name="type" style="{{ $bfs }}"><option value="">Type…</option>@foreach ($locationTypes as $t)<option value="{{ $t }}">{{ $t }}</option>@endforeach</select>
                        <input name="state" :placeholder="$store.ui.lang==='en'?'State':'Negeri'" style="{{ $bfs }}" />
                        <input name="contact_number" placeholder="Contact" style="{{ $bfs }}" />
                        <input name="email" type="email" placeholder="Email" style="{{ $bfs }}" />
                        <select name="status" style="{{ $bfs }}"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                        <input name="effective_date" type="date" style="{{ $bfs }}" />
                    </div>
                    <input name="address" placeholder="Address" style="{{ $bfs }}width:100%;margin-top:8px;" />
                    <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:12.5px;margin-top:8px;"><span x-text="$store.ui.lang==='en'?'Add branch':'Tambah cawangan'">Add branch</span></button>
                </form>
            @endif

            @forelse ($branches as $b)
                <div style="padding:8px 0;border-bottom:1px solid var(--hairline-soft);">
                    <div @if ($canManageFeatures) x-show="editId !== {{ $b->id }}" @endif style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                        <span style="font-size:13px;color:var(--ink);">{{ $b->name }}@if ($b->type || $b->code)<span style="color:var(--muted);font-size:11.5px;"> · {{ $b->type ?: 'Branch' }}@if ($b->code) ({{ $b->code }})@endif</span>@endif</span>
                        <div style="display:flex;align-items:center;gap:12px;">
                            <span style="font-size:12px;color:var(--muted);">{{ $b->state }}</span>
                            @if ($canManageFeatures)
                                <button type="button" @click="editId={{ $b->id }};adding=false" style="font-size:12px;color:var(--ink);" x-text="$store.ui.lang==='en'?'Edit':'Sunting'">Edit</button>
                                <button type="submit" form="del-branch-{{ $b->id }}" style="font-size:12px;color:var(--red);" x-text="$store.ui.lang==='en'?'Delete':'Padam'">Delete</button>
                            @endif
                        </div>
                    </div>
                    @if ($canManageFeatures)
                        <form x-show="editId === {{ $b->id }}" x-cloak method="post" action="{{ route('admin.branches.update', $b) }}">
                            @csrf
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;">
                                <input name="name" value="{{ $b->name }}" required style="{{ $bfs }}" />
                                <input name="code" value="{{ $b->code }}" placeholder="Code" style="{{ $bfs }}" />
                                <select name="type" style="{{ $bfs }}"><option value="">Type…</option>@foreach ($locationTypes as $t)<option value="{{ $t }}" @selected($b->type === $t)>{{ $t }}</option>@endforeach</select>
                                <input name="state" value="{{ $b->state }}" placeholder="State" style="{{ $bfs }}" />
                                <input name="contact_number" value="{{ $b->contact_number }}" placeholder="Contact" style="{{ $bfs }}" />
                                <input name="email" type="email" value="{{ $b->email }}" placeholder="Email" style="{{ $bfs }}" />
                                <select name="status" style="{{ $bfs }}"><option value="active" @selected(($b->status ?? 'active')==='active')>Active</option><option value="inactive" @selected($b->status==='inactive')>Inactive</option></select>
                                <input name="effective_date" type="date" value="{{ optional($b->effective_date)->toDateString() }}" style="{{ $bfs }}" />
                            </div>
                            <input name="address" value="{{ $b->address }}" placeholder="Address" style="{{ $bfs }}width:100%;margin-top:8px;" />
                            <div style="display:flex;gap:8px;margin-top:8px;">
                                <button type="submit" class="uj-btn-primary" style="height:34px;padding:0 14px;font-size:12px;"><span x-text="$store.ui.lang==='en'?'Save':'Simpan'">Save</span></button>
                                <button type="button" @click="editId=null" style="font-size:12px;color:var(--muted);" x-text="$store.ui.lang==='en'?'Cancel':'Batal'">Cancel</button>
                            </div>
                        </form>
                    @endif
                </div>
            @empty
                <p style="font-size:12.5px;color:var(--muted);margin:4px 0 0;" x-text="$store.ui.lang==='en'?'No branches yet.':'Tiada cawangan lagi.'">No branches yet.</p>
            @endforelse

            @if ($canManageFeatures)
                @foreach ($branches as $b)
                    <form id="del-branch-{{ $b->id }}" method="post" action="{{ route('admin.branches.delete', $b) }}" onsubmit="return confirm('Delete {{ addslashes($b->name) }}?')">@csrf</form>
                @endforeach
            @endif
        </div>
        @endif

        @if (! $only || $only === 'departments')
        {{-- Departments: name CRUD. employees_count is shown for context; delete is blocked while in use. --}}
        <div class="uj-card" style="padding:20px;" @if ($canManageFeatures) x-data="{ adding:false, editId:null }" @endif>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Departments' : 'Jabatan'">Departments</h3>
                @if ($canManageFeatures)
                    <button type="button" @click="adding=!adding;editId=null" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12.5px;">
                        <span x-text="adding ? ($store.ui.lang==='en'?'Cancel':'Batal') : ($store.ui.lang==='en'?'+ Add':'+ Tambah')">+ Add</span>
                    </button>
                @endif
            </div>

            @if ($canManageFeatures)
                <form x-show="adding" x-cloak method="post" action="{{ route('admin.departments.store') }}" style="display:flex;gap:8px;margin-bottom:14px;">
                    @csrf
                    <input name="name" required :placeholder="$store.ui.lang==='en'?'Department name':'Nama jabatan'" style="flex:1;min-width:0;height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
                    <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 14px;font-size:12.5px;flex-shrink:0;"><span x-text="$store.ui.lang==='en'?'Add':'Tambah'">Add</span></button>
                </form>
            @endif

            @forelse ($departments as $d)
                <div style="padding:8px 0;border-bottom:1px solid var(--hairline-soft);">
                    <div @if ($canManageFeatures) x-show="editId !== {{ $d->id }}" @endif style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                        <span style="font-size:13px;color:var(--ink);">{{ $d->name }}</span>
                        <div style="display:flex;align-items:center;gap:12px;">
                            <span style="font-size:12px;color:var(--muted);font-family:var(--font-mono);" title="{{ $d->employees_count }} {{ __('employees') }}">{{ $d->employees_count }}</span>
                            @if ($canManageFeatures)
                                <button type="button" @click="editId={{ $d->id }};adding=false" style="font-size:12px;color:var(--ink);" x-text="$store.ui.lang==='en'?'Edit':'Sunting'">Edit</button>
                                <button type="submit" form="del-dept-{{ $d->id }}" style="font-size:12px;color:var(--red);" x-text="$store.ui.lang==='en'?'Delete':'Padam'">Delete</button>
                            @endif
                        </div>
                    </div>
                    @if ($canManageFeatures)
                        <form x-show="editId === {{ $d->id }}" x-cloak method="post" action="{{ route('admin.departments.update', $d) }}" style="display:flex;gap:8px;align-items:center;">
                            @csrf
                            <input name="name" value="{{ $d->name }}" required style="flex:1;min-width:0;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
                            <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 12px;font-size:12px;flex-shrink:0;"><span x-text="$store.ui.lang==='en'?'Save':'Simpan'">Save</span></button>
                            <button type="button" @click="editId=null" style="font-size:12px;color:var(--muted);flex-shrink:0;" x-text="$store.ui.lang==='en'?'Cancel':'Batal'">Cancel</button>
                        </form>
                    @endif
                </div>
            @empty
                <p style="font-size:12.5px;color:var(--muted);margin:4px 0 0;" x-text="$store.ui.lang==='en'?'No departments yet.':'Tiada jabatan lagi.'">No departments yet.</p>
            @endforelse

            @if ($canManageFeatures)
                @foreach ($departments as $d)
                    <form id="del-dept-{{ $d->id }}" method="post" action="{{ route('admin.departments.delete', $d) }}" onsubmit="return confirm('Delete {{ addslashes($d->name) }}?')">@csrf</form>
                @endforeach
            @endif
        </div>
        @endif

        @if (! $only || $only === 'staff-levels')
        {{-- Staff levels (grades): name + optional code. Blocked from delete while in use. --}}
        <div class="uj-card" style="padding:20px;" @if ($canManageFeatures) x-data="{ adding:false, editId:null }" @endif>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Staff levels' : 'Tahap staf'">Staff levels</h3>
                @if ($canManageFeatures)
                    <button type="button" @click="adding=!adding" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12.5px;">
                        <span x-text="adding ? ($store.ui.lang==='en'?'Cancel':'Batal') : ($store.ui.lang==='en'?'+ Add':'+ Tambah')">+ Add</span>
                    </button>
                @endif
            </div>
            @if ($canManageFeatures)
                <form x-show="adding" x-cloak method="post" action="{{ route('admin.staff-levels.store') }}" style="display:flex;gap:8px;margin-bottom:14px;">
                    @csrf
                    <input name="name" required :placeholder="$store.ui.lang==='en'?'Level (e.g. L3)':'Tahap (cth. L3)'" style="flex:2;min-width:0;height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
                    <input name="code" :placeholder="$store.ui.lang==='en'?'Code':'Kod'" style="flex:1;min-width:0;height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
                    <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 14px;font-size:12.5px;flex-shrink:0;"><span x-text="$store.ui.lang==='en'?'Add':'Tambah'">Add</span></button>
                </form>
            @endif
            @forelse ($staffLevels as $lv)
                <div style="padding:8px 0;border-bottom:1px solid var(--hairline-soft);">
                    <div @if ($canManageFeatures) x-show="editId !== {{ $lv->id }}" @endif style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                        <span style="font-size:13px;color:var(--ink);">{{ $lv->name }}@if ($lv->code)<span style="color:var(--muted);font-size:12px;"> · {{ $lv->code }}</span>@endif</span>
                        @if ($canManageFeatures)
                            <div style="display:flex;align-items:center;gap:12px;">
                                <button type="button" @click="editId={{ $lv->id }};adding=false" style="font-size:12px;color:var(--ink);" x-text="$store.ui.lang==='en'?'Edit':'Sunting'">Edit</button>
                                <button type="submit" form="del-lv-{{ $lv->id }}" style="font-size:12px;color:var(--red);" x-text="$store.ui.lang==='en'?'Delete':'Padam'">Delete</button>
                            </div>
                        @endif
                    </div>
                    @if ($canManageFeatures)
                        <form x-show="editId === {{ $lv->id }}" x-cloak method="post" action="{{ route('admin.staff-levels.update', $lv) }}" style="display:flex;gap:8px;align-items:center;">
                            @csrf
                            <input name="name" value="{{ $lv->name }}" required style="flex:2;min-width:0;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
                            <input name="code" value="{{ $lv->code }}" :placeholder="$store.ui.lang==='en'?'Code':'Kod'" style="flex:1;min-width:0;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
                            <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 12px;font-size:12px;flex-shrink:0;"><span x-text="$store.ui.lang==='en'?'Save':'Simpan'">Save</span></button>
                            <button type="button" @click="editId=null" style="font-size:12px;color:var(--muted);flex-shrink:0;" x-text="$store.ui.lang==='en'?'Cancel':'Batal'">Cancel</button>
                        </form>
                    @endif
                </div>
            @empty
                <p style="font-size:12.5px;color:var(--muted);margin:4px 0 0;" x-text="$store.ui.lang==='en'?'No staff levels yet.':'Tiada tahap staf lagi.'">No staff levels yet.</p>
            @endforelse
            @if ($canManageFeatures)
                @foreach ($staffLevels as $lv)
                    <form id="del-lv-{{ $lv->id }}" method="post" action="{{ route('admin.staff-levels.delete', $lv) }}" onsubmit="return confirm('Delete {{ addslashes($lv->name) }}?')">@csrf</form>
                @endforeach
            @endif
        </div>
        @endif

        @if (! $only || $only === 'employment-types')
        {{-- Employment types: Full-time, Contract, Part-time, etc. --}}
        <div class="uj-card" style="padding:20px;" @if ($canManageFeatures) x-data="{ adding:false, editId:null }" @endif>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Employment types' : 'Jenis pekerjaan'">Employment types</h3>
                @if ($canManageFeatures)
                    <button type="button" @click="adding=!adding" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12.5px;">
                        <span x-text="adding ? ($store.ui.lang==='en'?'Cancel':'Batal') : ($store.ui.lang==='en'?'+ Add':'+ Tambah')">+ Add</span>
                    </button>
                @endif
            </div>
            @if ($canManageFeatures)
                <form x-show="adding" x-cloak method="post" action="{{ route('admin.employment-types.store') }}" style="display:flex;gap:8px;margin-bottom:14px;">
                    @csrf
                    <input name="name" required :placeholder="$store.ui.lang==='en'?'Type (e.g. Full-time)':'Jenis (cth. Sepenuh masa)'" style="flex:2;min-width:0;height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
                    <input name="code" :placeholder="$store.ui.lang==='en'?'Code':'Kod'" style="flex:1;min-width:0;height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
                    <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 14px;font-size:12.5px;flex-shrink:0;"><span x-text="$store.ui.lang==='en'?'Add':'Tambah'">Add</span></button>
                </form>
            @endif
            @forelse ($employmentTypes as $et)
                <div style="padding:8px 0;border-bottom:1px solid var(--hairline-soft);">
                    <div @if ($canManageFeatures) x-show="editId !== {{ $et->id }}" @endif style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                        <span style="font-size:13px;color:var(--ink);">{{ $et->name }}@if ($et->code)<span style="color:var(--muted);font-size:12px;"> · {{ $et->code }}</span>@endif</span>
                        @if ($canManageFeatures)
                            <div style="display:flex;align-items:center;gap:12px;">
                                <button type="button" @click="editId={{ $et->id }};adding=false" style="font-size:12px;color:var(--ink);" x-text="$store.ui.lang==='en'?'Edit':'Sunting'">Edit</button>
                                <button type="submit" form="del-et-{{ $et->id }}" style="font-size:12px;color:var(--red);" x-text="$store.ui.lang==='en'?'Delete':'Padam'">Delete</button>
                            </div>
                        @endif
                    </div>
                    @if ($canManageFeatures)
                        <form x-show="editId === {{ $et->id }}" x-cloak method="post" action="{{ route('admin.employment-types.update', $et) }}" style="display:flex;gap:8px;align-items:center;">
                            @csrf
                            <input name="name" value="{{ $et->name }}" required style="flex:2;min-width:0;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
                            <input name="code" value="{{ $et->code }}" :placeholder="$store.ui.lang==='en'?'Code':'Kod'" style="flex:1;min-width:0;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
                            <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 12px;font-size:12px;flex-shrink:0;"><span x-text="$store.ui.lang==='en'?'Save':'Simpan'">Save</span></button>
                            <button type="button" @click="editId=null" style="font-size:12px;color:var(--muted);flex-shrink:0;" x-text="$store.ui.lang==='en'?'Cancel':'Batal'">Cancel</button>
                        </form>
                    @endif
                </div>
            @empty
                <p style="font-size:12.5px;color:var(--muted);margin:4px 0 0;" x-text="$store.ui.lang==='en'?'No employment types yet.':'Tiada jenis pekerjaan lagi.'">No employment types yet.</p>
            @endforelse
            @if ($canManageFeatures)
                @foreach ($employmentTypes as $et)
                    <form id="del-et-{{ $et->id }}" method="post" action="{{ route('admin.employment-types.delete', $et) }}" onsubmit="return confirm('Delete {{ addslashes($et->name) }}?')">@csrf</form>
                @endforeach
            @endif
        </div>
        @endif
    </div>
</div>

@if (!empty($canManageFeatures) && (! $only || $only === 'features'))
<div class="uj-card" style="margin-top:16px;padding:24px;">
    <h3 class="uj-card-title" style="margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Features' : 'Ciri'">Features</h3>
    <p style="font-size:13px;color:var(--muted);margin:0 0 16px;"><span x-text="$store.ui.lang==='en' ? 'Turn modules on or off for this company and tune behavioural settings. Features marked' : 'Hidup atau matikan modul untuk syarikat ini dan laras tetapan tingkah laku. Ciri yang ditanda'">Turn modules on or off for this company and tune behavioural settings. Features marked</span> <span style="font-size:11px;font-weight:600;color:#a81820;background:#fbeaeb;border:1px solid #f3c6c8;padding:1px 7px;border-radius:9999px;" x-text="$store.ui.lang==='en' ? 'Locked' : 'Dikunci'">Locked</span> <span x-text="$store.ui.lang==='en' ? 'are set by the platform and cannot be changed here.' : 'ditetapkan oleh platform dan tidak boleh diubah di sini.'">are set by the platform and cannot be changed here.</span></p>
    @include('partials.hint', ['en' => 'Disabling a module hides it from the menu for everyone and blocks its screens. Locked features are controlled centrally by the platform team.', 'ms' => 'Mematikan modul akan menyembunyikannya dari menu untuk semua orang dan menyekat skrinnya. Ciri yang dikunci dikawal secara berpusat oleh pasukan platform.'])

    <form method="post" action="{{ route('admin.features.update') }}">
        @csrf
        <input type="hidden" name="features_present" value="1">

        <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin:18px 0 10px;" x-text="$store.ui.lang==='en' ? 'Modules' : 'Modul'">Modules</div>
        {{-- Grouped by sidebar section so each toggle maps to where it lives in the
             nav. Section heading + the per-toggle "Controls:" caption come from
             AppController::navScreenIndex(). --}}
        @foreach ($featureRows['modules'] as $group)
            <div style="margin-bottom:16px;">
                <div style="font-size:12px;font-weight:600;color:var(--ink);border-bottom:1px solid var(--hairline-soft);padding-bottom:6px;margin-bottom:6px;"
                     x-text="$store.ui.lang==='en' ? @js($group['section']) : @js($group['section_ms'])">{{ $group['section'] }}</div>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:2px 20px;">
                    @foreach ($group['rows'] as $row)
                        @php
                            $navEn = implode(' · ', array_map(fn ($n) => $n['en'], $row['nav_items']));
                            $navMs = implode(' · ', array_map(fn ($n) => $n['ms'], $row['nav_items']));
                            $showNav = count($row['nav_items']) > 1;
                        @endphp
                        <label style="display:flex;align-items:flex-start;gap:10px;padding:8px 0;cursor:{{ $row['locked'] ? 'not-allowed' : 'pointer' }};">
                            <input type="checkbox" name="features[{{ $row['key'] }}]" value="1" style="margin-top:2px;flex-shrink:0;"
                                @checked(\App\Support\Features::asBool($row['value']))
                                @disabled($row['locked'])>
                            <span style="flex:1;min-width:0;">
                                <span style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                    <span style="font-size:13.5px;color:var(--ink);">{{ $row['label'] }}</span>
                                    @if ($row['locked'])<span style="font-size:11px;font-weight:600;color:#a81820;background:#fbeaeb;border:1px solid #f3c6c8;padding:1px 7px;border-radius:9999px;" x-text="$store.ui.lang==='en' ? 'Locked' : 'Dikunci'">Locked</span>@endif
                                </span>
                                @if ($showNav)
                                    <span style="display:block;font-size:11px;color:var(--muted);margin-top:1px;line-height:1.4;"
                                          x-text="$store.ui.lang==='en' ? @js('Controls: '.$navEn) : @js('Mengawal: '.$navMs)">Controls: {{ $navEn }}</span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>
            </div>
        @endforeach

        <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin:24px 0 10px;" x-text="$store.ui.lang==='en' ? 'Settings' : 'Tetapan'">Settings</div>
        <div style="display:flex;flex-direction:column;gap:16px;max-width:560px;">
            @foreach ($featureRows['settings'] as $row)
                <div style="display:flex;align-items:flex-start;gap:14px;">
                    <div style="flex:1;">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span style="font-size:13.5px;font-weight:500;color:var(--ink);">{{ $row['label'] }}</span>
                            @if ($row['locked'])<span style="font-size:11px;font-weight:600;color:#a81820;background:#fbeaeb;border:1px solid #f3c6c8;padding:1px 7px;border-radius:9999px;" x-text="$store.ui.lang==='en' ? 'Locked' : 'Dikunci'">Locked</span>@endif
                        </div>
                        @if (!empty($row['help']))<div style="font-size:12px;color:var(--muted);margin-top:2px;">{{ $row['help'] }}</div>@endif
                    </div>
                    <div style="width:200px;flex-shrink:0;">
                        @if ($row['type'] === 'enum')
                            <select name="features[{{ $row['key'] }}]" @disabled($row['locked'])
                                style="width:100%;height:38px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;background:{{ $row['locked'] ? 'var(--hairline-soft)' : '#fff' }};color:var(--ink);">
                                @foreach ($row['options'] as $val => $optLabel)
                                    <option value="{{ $val }}" @selected((string) $row['value'] === (string) $val)>{{ $optLabel }}</option>
                                @endforeach
                            </select>
                        @elseif ($row['type'] === 'number')
                            <input type="number" name="features[{{ $row['key'] }}]" value="{{ $row['value'] }}" @disabled($row['locked'])
                                step="1" min="{{ $row['min'] ?? 0 }}" @if (! is_null($row['max']))max="{{ $row['max'] }}"@endif
                                style="width:100%;height:38px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;font-family:var(--font-mono);background:{{ $row['locked'] ? 'var(--hairline-soft)' : '#fff' }};color:var(--ink);">
                        @else
                            <label style="display:inline-flex;align-items:center;gap:8px;font-size:13px;color:var(--ink);cursor:{{ $row['locked'] ? 'not-allowed' : 'pointer' }};">
                                <input type="checkbox" name="features[{{ $row['key'] }}]" value="1"
                                    @checked(\App\Support\Features::asBool($row['value']))
                                    @disabled($row['locked'])>
                                <span x-text="$store.ui.lang==='en' ? 'Enabled' : 'Dihidupkan'">Enabled</span>
                            </label>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;margin-top:22px;"><span x-text="$store.ui.lang==='en' ? 'Save features' : 'Simpan ciri'">Save features</span></button>
    </form>
</div>
@endif
@endsection
