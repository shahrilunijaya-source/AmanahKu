@extends('layouts.app')

@php
    $sc = ['completed' => 'var(--success)', 'in_progress' => 'var(--info)', 'not_started' => 'var(--muted-soft)'];
    $sl = ['completed' => 'Completed', 'in_progress' => 'In progress', 'not_started' => 'Not started'];
    $slMs = ['completed' => 'Selesai', 'in_progress' => 'Sedang berjalan', 'not_started' => 'Belum mula'];
    $overdue = $records->filter(fn ($r) => $r->status !== 'completed' && $r->due_at && $r->due_at->isPast());
    $privileged = in_array($role, ['manager', 'management', 'hr'], true);
    $fs = 'height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;';
    // Assign-picker groups: staff ranked by tenant role tier (senior first), then
    // alphabetically within a tier. $recipientRoles maps user_id → pivot role.
    $tierMeta = [
        'director'   => ['order' => 0, 'en' => 'Directors',  'ms' => 'Pengarah'],
        'management' => ['order' => 1, 'en' => 'Management',  'ms' => 'Pengurusan'],
        'hr'         => ['order' => 2, 'en' => 'HR',          'ms' => 'HR'],
        'manager'    => ['order' => 3, 'en' => 'Managers',    'ms' => 'Pengurus'],
        'employee'   => ['order' => 4, 'en' => 'Employees',   'ms' => 'Pekerja'],
    ];
    $tierOf = fn ($e) => isset($tierMeta[$recipientRoles[$e->user_id] ?? 'employee'])
        ? ($recipientRoles[$e->user_id] ?? 'employee')
        : 'employee';
    $recipientsByTier = $recipients
        ->groupBy($tierOf)
        ->sortBy(fn ($people, $tier) => $tierMeta[$tier]['order'] ?? 99);
@endphp

@section('screen')
@include('partials.guide', [
    'key'   => 'training',
    'en'  => [
        'title' => 'Training & certifications',
        'body'  => 'Track the courses staff are assigned and whether they\'ve finished them. Mandatory courses are required for the role — overdue ones are flagged in red so nothing slips.',
        'who'   => 'Managers & HR assign · Staff complete',
        'steps' => [
            'Click "+ Assign course", pick the employee and type the course name.',
            'Set a due date and tick "Mandatory" if the course is required, not optional.',
            'Staff (or you) click "Mark complete" once it\'s done — the status turns green.',
            'Watch the Overdue counter: anything past its due date and not completed shows there.',
        ],
    ],
    'ms'  => [
        'title' => 'Latihan & pensijilan',
        'body'  => 'Jejak kursus yang ditugaskan kepada staf dan sama ada mereka sudah selesaikannya. Kursus mandatory diperlukan untuk jawatan — yang lewat tarikh diserlahkan merah supaya tiada yang terlepas.',
        'who'   => 'Pengurus & HR tugaskan · Staf selesaikan',
        'steps' => [
            'Klik "+ Assign course", pilih pekerja dan taip nama kursus.',
            'Tetapkan tarikh akhir dan tanda "Mandatory" jika kursus itu wajib, bukan pilihan.',
            'Staf (atau anda) klik "Mark complete" setelah selesai — statusnya bertukar hijau.',
            'Perhatikan kaunter Overdue: apa-apa yang lepas tarikh akhir dan belum selesai dipaparkan di situ.',
        ],
    ],
])
<div x-data="{ assign: {{ $errors->any() ? 'true' : 'false' }} }">
<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
    <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Courses' : 'Kursus'">Courses</span></div><div class="uj-stat-value">{{ $records->count() }}</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Completed' : 'Selesai'">Completed</span></div><div class="uj-stat-value" style="color:var(--success);">{{ $records->where('status', 'completed')->count() }}</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Mandatory' : 'Wajib'">Mandatory</span></div><div class="uj-stat-value">{{ $records->where('mandatory', true)->count() }}</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Overdue' : 'Lewat tarikh'">Overdue</span></div><div class="uj-stat-value" style="color:var(--error);">{{ $overdue->count() }}</div></div>
</div>

@if ($privileged)
    <div x-show="assign" x-cloak class="uj-card" style="padding:20px;margin-bottom:16px;">
        <h3 class="uj-card-title" style="margin-bottom:14px;"><span x-text="$store.ui.lang==='en' ? 'Assign a course' : 'Tugaskan kursus'">Assign a course</span></h3>
        <form method="post" action="{{ route('training.store') }}">
            @csrf
            @if ($errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>@endif
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;align-items:start;">
                {{-- Multi-select employee picker: opens a role-tier-grouped modal (Directors →
                     Management → HR → Managers → Employees); selections ride into the form as
                     employee_ids[] hidden inputs. Teleported to body so it centres. --}}
                <div x-data="{
                        pickerOpen: false,
                        q: '',
                        picked: {{ \Illuminate\Support\Js::from(collect(old('employee_ids', []))->map(fn ($v) => (string) $v)->values()) }},
                        people: {{ \Illuminate\Support\Js::from($recipients->mapWithKeys(fn ($e) => [(string) $e->id => ['name' => $e->name]])) }}
                     }">
                    <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Employees' : 'Pekerja'">Employees</span> *</label>
                    <button type="button" @click="pickerOpen = true" style="{{ $fs }}width:100%;display:flex;align-items:center;justify-content:space-between;gap:8px;text-align:left;cursor:pointer;">
                        <span x-show="picked.length === 0" style="color:var(--muted-soft);" x-text="$store.ui.lang==='en' ? 'Select employees…' : 'Pilih pekerja…'">Select employees…</span>
                        <span x-show="picked.length" x-cloak style="color:var(--ink);" x-text="picked.length + ($store.ui.lang==='en' ? ' selected' : ' dipilih')"></span>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--muted-soft)" stroke-width="2" stroke-linecap="round" style="flex-shrink:0;"><path d="M6 9l6 6 6-6"></path></svg>
                    </button>
                    <div x-show="picked.length" x-cloak style="margin-top:8px;">
                        <div style="display:flex;flex-wrap:wrap;gap:5px;">
                            <template x-for="id in picked" :key="id">
                                <span style="display:inline-flex;align-items:center;gap:4px;font-size:11.5px;color:var(--ink);background:var(--canvas);border:1px solid var(--hairline);border-radius:9999px;padding:2px 4px 2px 9px;">
                                    <span x-text="people[id] ? people[id].name : id"></span>
                                    <button type="button" @click="picked = picked.filter(p => p !== id)" style="border:none;background:none;cursor:pointer;color:var(--muted);font-size:15px;line-height:1;padding:0 3px;">&times;</button>
                                </span>
                            </template>
                        </div>
                    </div>
                    {{-- selections carried into the POST --}}
                    <template x-for="id in picked" :key="'h'+id"><input type="hidden" name="employee_ids[]" :value="id"></template>

                    {{-- Centered via display:flex overlay + card margin:auto (the app's canonical
                         teleported-modal pattern — justify-content centring rendered left here). --}}
                    <template x-teleport="body">
                        <div x-show="pickerOpen" x-cloak @click.self="pickerOpen = false" @keydown.escape.window="pickerOpen = false"
                             style="position:fixed;inset:0;z-index:120;display:flex;padding:40px 16px;background:rgba(18,18,30,.42);overflow-y:auto;">
                            <div class="uj-card" style="width:100%;max-width:460px;margin:auto;padding:0;display:flex;flex-direction:column;max-height:calc(100vh - 80px);overflow:hidden;">
                                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:16px 20px;border-bottom:1px solid var(--hairline);flex-shrink:0;">
                                    <h3 class="uj-card-title" style="margin:0;font-size:15px;"><span x-text="$store.ui.lang==='en' ? 'Select employees' : 'Pilih pekerja'">Select employees</span><span x-show="picked.length" x-cloak style="color:var(--muted-soft);font-weight:400;" x-text="' · ' + picked.length"></span></h3>
                                    <button type="button" @click="pickerOpen = false" style="border:none;background:none;cursor:pointer;color:var(--muted);font-size:20px;line-height:1;">&times;</button>
                                </div>
                                <div style="padding:12px 20px;border-bottom:1px solid var(--hairline);flex-shrink:0;">
                                    <input x-model="q" :placeholder="$store.ui.lang==='en' ? 'Search name…' : 'Cari nama…'" style="{{ $fs }}width:100%;" />
                                </div>
                                <div style="overflow-y:auto;padding:6px 10px;flex:1;">
                                    @forelse ($recipientsByTier as $tier => $people)
                                        @php $grpJs = \Illuminate\Support\Js::from($people->map(fn ($e) => (string) $e->id)->values()); $grpNames = \Illuminate\Support\Js::from($people->map(fn ($e) => strtolower($e->name))->values()); $tm = $tierMeta[$tier] ?? ['en' => ucfirst($tier), 'ms' => ucfirst($tier)]; @endphp
                                        <div x-show="!q || {{ $grpNames }}.some(n => n.includes(q.toLowerCase()))">
                                            <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 10px 5px;">
                                                <span style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;"><span x-text="$store.ui.lang==='en' ? @json($tm['en']) : @json($tm['ms'])">{{ $tm['en'] }}</span> <span style="color:var(--muted-soft);">· {{ $people->count() }}</span></span>
                                                <button type="button" @click="picked = {{ $grpJs }}.every(i => picked.includes(i)) ? picked.filter(i => !{{ $grpJs }}.includes(i)) : [...new Set([...picked, ...{{ $grpJs }}])]"
                                                        style="border:none;background:none;cursor:pointer;font-size:11.5px;color:var(--red);font-weight:600;"
                                                        x-text="{{ $grpJs }}.every(i => picked.includes(i)) ? ($store.ui.lang==='en' ? 'Clear' : 'Kosong') : ($store.ui.lang==='en' ? 'Select all' : 'Pilih semua')"></button>
                                            </div>
                                            @foreach ($people as $p)
                                                {{-- x-show must sit on a block wrapper, NOT the flex <label>: x-show manages
                                                     the element's `display`, and on show reverts a <label> to its default
                                                     `inline` — wiping display:flex and making rows flow side-by-side. --}}
                                                <div x-show="!q || {{ \Illuminate\Support\Js::from(strtolower($p->name)) }}.includes(q.toLowerCase())">
                                                    <label :style="picked.includes('{{ $p->id }}') ? { background:'var(--red-tint)' } : {}"
                                                           style="display:flex;align-items:center;gap:11px;padding:8px 10px;border-radius:8px;cursor:pointer;">
                                                        <span style="width:28px;height:28px;border-radius:50%;background:{{ $p->avatar_color }};color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:10.5px;font-weight:600;flex-shrink:0;">{{ $p->initials }}</span>
                                                        <span style="flex:1;min-width:0;">
                                                            <span style="display:block;font-size:13px;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $p->name }}</span>
                                                            @if ($p->position)<span style="display:block;font-size:11px;color:var(--muted-soft);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $p->position }}</span>@endif
                                                        </span>
                                                        <input type="checkbox" value="{{ $p->id }}" x-model="picked" style="width:16px;height:16px;flex-shrink:0;accent-color:var(--red);cursor:pointer;" />
                                                    </label>
                                                </div>
                                            @endforeach
                                        </div>
                                    @empty
                                        <div style="padding:24px;text-align:center;font-size:13px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'No employees to assign.' : 'Tiada pekerja untuk ditugaskan.'"></span></div>
                                    @endforelse
                                </div>
                                <div style="padding:12px 20px;border-top:1px solid var(--hairline);display:flex;align-items:center;justify-content:space-between;gap:10px;flex-shrink:0;">
                                    <button type="button" @click="picked = []" x-show="picked.length" x-cloak style="border:none;background:none;cursor:pointer;font-size:12.5px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Clear all' : 'Kosongkan semua'">Clear all</span></button>
                                    <span x-show="!picked.length"></span>
                                    <button type="button" @click="pickerOpen = false" class="uj-btn-primary" style="height:36px;padding:0 18px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Done' : 'Selesai'">Done</span><span x-show="picked.length" x-cloak x-text="' (' + picked.length + ')'"></span></button>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
                <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Course *' : 'Kursus *'">Course *</span></label><input name="course" value="{{ old('course') }}" required maxlength="160" style="{{ $fs }}width:100%;margin-bottom:6px;" />@include('partials.hint', ['en' => 'The course or certification name — e.g. "Fire safety briefing" or "First aid renewal".', 'ms' => 'Nama kursus atau pensijilan — cth. "Taklimat keselamatan kebakaran" atau "Pembaharuan bantuan kecemasan".'])</div>
                <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Provider' : 'Penyedia'">Provider</span></label><input name="provider" value="{{ old('provider') }}" maxlength="120" style="{{ $fs }}width:100%;margin-bottom:6px;" />@include('partials.hint', ['en' => 'Who runs it, if relevant — internal trainer, vendor or institute. Optional.', 'ms' => 'Siapa yang mengendalikannya, jika berkaitan — jurulatih dalaman, vendor atau institut. Pilihan.'])</div>
                <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Due date' : 'Tarikh akhir'">Due date</span></label><input name="due_at" type="date" value="{{ old('due_at') }}" style="{{ $fs }}width:100%;margin-bottom:6px;" />@include('partials.hint', ['en' => 'The deadline to finish. Once this date passes without completion, it counts as Overdue.', 'ms' => 'Tarikh akhir untuk siap. Setelah tarikh ini berlalu tanpa selesai, ia dikira sebagai Overdue.'])</div>
                <div>
                    {{-- Spacer label (matches the 12px label + 5px margin on the other cells) so
                         the checkbox lines up with the inputs, not the field labels. --}}
                    <label aria-hidden="true" style="display:block;font-size:12px;margin-bottom:5px;">&nbsp;</label>
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--ink);height:38px;"><input type="checkbox" name="mandatory" value="1" @checked(old('mandatory')) /> <span x-text="$store.ui.lang==='en' ? 'Mandatory' : 'Wajib'">Mandatory</span></label>
                    @include('partials.hint', ['en' => 'Tick only if the role legally or formally requires this course. Mandatory ones are flagged in red.', 'ms' => 'Tanda hanya jika jawatan memerlukan kursus ini secara sah atau formal. Yang mandatory diserlahkan merah.', 'tone' => 'warn'])
                </div>
            </div>
            <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;margin-top:16px;"><span x-text="$store.ui.lang==='en' ? 'Assign course' : 'Tugaskan kursus'">Assign course</span></button>
        </form>
    </div>
@endif

<div class="uj-card">
    <div class="uj-card-head">
        <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'Training & certifications' : 'Latihan & pensijilan'">Training &amp; certifications</span></h3>
        @if ($privileged)<button @click="assign = ! assign" class="uj-btn-primary" style="height:34px;padding:0 13px;font-size:12.5px;"><span x-text="assign ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal') : ($store.ui.lang==='en' ? '+ Assign course' : '+ Tugaskan kursus')"></span></button>@endif
    </div>
    <div style="display:grid;grid-template-columns:2.2fr 1.4fr 1fr 1fr 1fr auto;gap:8px;padding:12px 20px;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;border-bottom:1px solid var(--hairline-soft);"><span x-text="$store.ui.lang==='en' ? 'Course' : 'Kursus'">Course</span><span x-text="$store.ui.lang==='en' ? 'Employee' : 'Pekerja'">Employee</span><span x-text="$store.ui.lang==='en' ? 'Due' : 'Tarikh'">Due</span><span x-text="$store.ui.lang==='en' ? 'Type' : 'Jenis'">Type</span><span x-text="$store.ui.lang==='en' ? 'Status' : 'Status'">Status</span><span></span></div>
    @forelse ($records as $r)
        @php
            $isOverdue = $r->status !== 'completed' && $r->due_at && $r->due_at->isPast();
            $canComplete = $r->status !== 'completed' && ($privileged || ($employee && $r->employee_id === $employee->id));
        @endphp
        {{-- Top-align: the Course cell has a provider subtitle (2 lines); centring would
             float the single-line values between the two lines. Aligning to start puts every
             value on the course-title line. A shared 20px line-height keeps them level. --}}
        <div style="display:grid;grid-template-columns:2.2fr 1.4fr 1fr 1fr 1fr auto;gap:8px;padding:13px 20px;border-bottom:1px solid var(--hairline-soft);align-items:start;line-height:20px;">
            <div><div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $r->course }}</div><div style="font-size:11.5px;color:var(--muted-soft);line-height:1.4;">{{ $r->provider }}</div></div>
            <span style="font-size:13px;color:var(--body);">{{ $r->employee?->name }}</span>
            <span style="font-size:12.5px;font-family:var(--font-mono);color:{{ $isOverdue ? 'var(--error)' : 'var(--body)' }};">{{ $r->due_at?->format('j M Y') ?? '—' }}{{ $isOverdue ? ' ⚠' : '' }}</span>
            <span style="font-size:11px;font-weight:600;color:{{ $r->mandatory ? 'var(--red)' : 'var(--muted)' }};"><span x-text="$store.ui.lang==='en' ? '{{ $r->mandatory ? 'Mandatory' : 'Optional' }}' : '{{ $r->mandatory ? 'Wajib' : 'Pilihan' }}'">{{ $r->mandatory ? 'Mandatory' : 'Optional' }}</span></span>
            <span style="display:inline-flex;align-items:center;gap:7px;font-size:12.5px;font-weight:600;color:{{ $sc[$r->status] }};"><span style="width:8px;height:8px;border-radius:50%;background:{{ $sc[$r->status] }};"></span><span x-text="$store.ui.lang==='en' ? '{{ $sl[$r->status] }}' : '{{ $slMs[$r->status] ?? $sl[$r->status] }}'">{{ $sl[$r->status] }}</span></span>
            <span style="text-align:right;">
                @if ($canComplete)
                    <form method="post" action="{{ route('training.complete', $r) }}">@csrf<button type="submit" class="uj-btn-ghost" style="height:30px;padding:0 11px;font-size:11.5px;"><span x-text="$store.ui.lang==='en' ? 'Mark complete' : 'Tanda selesai'">Mark complete</span></button></form>
                @endif
            </span>
        </div>
    @empty
        <div style="padding:28px 20px;text-align:center;">
            <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No training records yet' : 'Belum ada rekod latihan'">No training records yet</span></div>
            <div style="font-size:12px;color:var(--muted);line-height:1.5;">@if ($privileged)<span x-text="$store.ui.lang==='en' ? 'Click &quot;+ Assign course&quot; above to assign the first course — it will appear here with its status and due date.' : 'Klik &quot;+ Assign course&quot; di atas untuk tugaskan kursus pertama — ia akan muncul di sini dengan status dan tarikh akhirnya.'"></span>@else<span x-text="$store.ui.lang==='en' ? 'No courses have been assigned to you yet. When they are, they will show here.' : 'Belum ada kursus ditugaskan kepada anda. Apabila ada, ia akan dipaparkan di sini.'"></span>@endif</div>
        </div>
    @endforelse
</div>
</div>
@endsection
