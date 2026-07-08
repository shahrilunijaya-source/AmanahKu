@extends('layouts.app')

@php
    $reqStatusMeta = [
        'open' => ['label' => 'Open', 'ms' => 'Dibuka', 'color' => 'var(--success)'],
        'on_hold' => ['label' => 'On Hold', 'ms' => 'Ditangguh', 'color' => 'var(--amber)'],
        'filled' => ['label' => 'Filled', 'ms' => 'Diisi', 'color' => 'var(--info)'],
        'closed' => ['label' => 'Closed', 'ms' => 'Ditutup', 'color' => 'var(--muted-soft)'],
    ];
    $stageMeta = [
        'applied' => ['label' => 'Applied', 'ms' => 'Memohon', 'color' => 'var(--muted)'],
        'screening' => ['label' => 'Screening', 'ms' => 'Saringan', 'color' => 'var(--info)'],
        'interview' => ['label' => 'Interview', 'ms' => 'Temuduga', 'color' => 'var(--amber)'],
        'offer' => ['label' => 'Offer', 'ms' => 'Tawaran', 'color' => 'var(--brand, var(--info))'],
        'hired' => ['label' => 'Hired', 'ms' => 'Diambil', 'color' => 'var(--success)'],
        'rejected' => ['label' => 'Rejected', 'ms' => 'Ditolak', 'color' => 'var(--red)'],
    ];
    $fs = 'height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;';
    $reqPill = function ($s) use ($reqStatusMeta) {
        $color = $reqStatusMeta[$s]['color'] ?? 'var(--muted)';
        $en = addslashes($reqStatusMeta[$s]['label'] ?? ucfirst($s));
        $ms = addslashes($reqStatusMeta[$s]['ms'] ?? ucfirst($s));
        return '<span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:'.$color.';"><span style="width:8px;height:8px;border-radius:50%;background:'.$color.';"></span><span x-text="$store.ui.lang===\'en\' ? \''.$en.'\' : \''.$ms.'\'">'.$en.'</span></span>';
    };
@endphp

@section('screen')

@include('partials.guide', [
    'key' => 'recruitment',
    'en'  => [
        'title' => 'Recruitment',
        'body'  => 'Open job requisitions and track every candidate through the hiring pipeline — from first application to hired. Pick a requisition on the left to see its candidates grouped by stage.',
        'who'   => 'HR & managers open roles and move candidates',
        'steps' => [
            'Open a requisition for the role you are hiring — set the title, department and how many openings.',
            'Add each candidate to that requisition as they come in.',
            'As a candidate progresses, use "Move" to shift them to the next stage (Screening → Interview → Offer → Hired).',
            'Hired candidates count toward the requisition\'s openings; the role auto-tracks how many are still left to fill.',
        ],
    ],
    'ms'  => [
        'title' => 'Pengambilan',
        'body'  => 'Buka jawatan kosong dan jejak setiap calon melalui saluran pengambilan — dari permohonan pertama sehingga diambil bekerja. Pilih satu jawatan di sebelah kiri untuk lihat calonnya mengikut peringkat.',
        'who'   => 'HR & pengurus buka jawatan dan gerakkan calon',
        'steps' => [
            'Buka jawatan untuk peranan yang anda ingin isi — tetapkan tajuk, jabatan dan berapa kekosongan.',
            'Tambah setiap calon ke jawatan itu apabila mereka memohon.',
            'Apabila calon maju, guna "Move" untuk alihkan mereka ke peringkat seterusnya (Screening → Interview → Offer → Hired).',
            'Calon yang diambil dikira terhadap kekosongan jawatan; sistem jejak sendiri berapa lagi yang perlu diisi.',
        ],
    ],
])

<div style="display:grid;grid-template-columns:minmax(260px,340px) 1fr;gap:16px;align-items:start;">

    {{-- ───────────────────────── Requisitions list ───────────────────────── --}}
    <div>
        @if ($privileged)
            <div class="uj-card" style="padding:20px;margin-bottom:16px;" x-data="{ open: {{ $errors->any() && old('_form') === 'req' ? 'true' : 'false' }} }">
                <div class="uj-card-head" style="padding:0;margin-bottom:14px;">
                    <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'Requisitions' : 'Permohonan Jawatan'">Requisitions</span></h3>
                    <button @click="open = ! open" class="uj-btn-primary" style="height:34px;padding:0 13px;font-size:12.5px;"><span x-text="open ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal') : ($store.ui.lang==='en' ? '+ New' : '+ Baharu')"></span></button>
                </div>
                <form x-show="open" x-cloak method="post" action="{{ route('recruitment.requisitions') }}">
                    @csrf
                    <input type="hidden" name="_form" value="req" />
                    @if ($errors->any() && old('_form') === 'req')
                        <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:12px;">{{ $errors->first() }}</div>
                    @endif
                    <div style="margin-bottom:12px;">
                        <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Title *' : 'Tajuk *'">Title *</span></label>
                        <input name="title" value="{{ old('title') }}" required maxlength="150" :placeholder="$store.ui.lang==='en' ? 'e.g. Senior Backend Engineer' : 'cth. Jurutera Backend Kanan'" style="{{ $fs }}width:100%;" />
                    </div>
                    <div style="margin-bottom:12px;">
                        <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Department' : 'Jabatan'">Department</span></label>
                        <select name="department_id" style="{{ $fs }}width:100%;">
                            <option value="" x-text="$store.ui.lang==='en' ? 'No department' : 'Tiada jabatan'">No department</option>
                            @foreach ($departments as $d)
                                <option value="{{ $d->id }}" @selected((string) old('department_id') === (string) $d->id)>{{ $d->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div>
                            <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Openings *' : 'Kekosongan *'">Openings *</span></label>
                            <input name="openings" type="number" min="1" max="999" value="{{ old('openings', 1) }}" required style="{{ $fs }}width:100%;margin-bottom:6px;" />
                            @include('partials.hint', ['en' => 'How many people you need to hire for this role. The pipeline tracks hires against this number.', 'ms' => 'Berapa orang yang perlu diambil untuk peranan ini. Saluran ini jejak pengambilan terhadap angka ini.'])
                        </div>
                        <div>
                            <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Location' : 'Lokasi'">Location</span></label>
                            <input name="location" value="{{ old('location') }}" maxlength="120" :placeholder="$store.ui.lang==='en' ? 'e.g. KL HQ' : 'cth. Ibu Pejabat KL'" style="{{ $fs }}width:100%;" />
                        </div>
                    </div>
                    <button type="submit" class="uj-btn-primary" style="height:40px;padding:0 18px;font-size:13px;margin-top:14px;"><span x-text="$store.ui.lang==='en' ? 'Open requisition' : 'Buka jawatan'">Open requisition</span></button>
                </form>
            </div>
        @endif

        <div class="uj-card">
            <div class="uj-card-head"><h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'Open roles' : 'Jawatan dibuka'">Open roles</span></h3></div>
            @forelse ($requisitions as $r)
                @php $isSel = $selected && $r->id === $selected->id; @endphp
                <a href="{{ route('app.screen', 'recruitment') }}?req={{ $r->id }}"
                   style="display:block;padding:14px 20px;border-bottom:1px solid var(--hairline-soft);text-decoration:none;{{ $isSel ? 'background:var(--canvas);' : '' }}">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;">
                        <div style="min-width:0;">
                            <div style="font-size:14px;font-weight:600;color:var(--ink);">{{ $r->title }}</div>
                            <div style="font-size:12px;color:var(--muted);margin-top:3px;">
                                @if ($r->department){{ $r->department->name }}@else<span x-text="$store.ui.lang==='en' ? 'No dept' : 'Tiada jabatan'">No dept</span>@endif{{ $r->location ? ' · '.$r->location : '' }}
                            </div>
                        </div>
                        {!! $reqPill($r->status) !!}
                    </div>
                    <div style="font-size:12px;color:var(--muted);margin-top:8px;">
                        {{ $r->candidates_count }} <span x-text="$store.ui.lang==='en' ? '{{ $r->candidates_count === 1 ? 'candidate' : 'candidates' }}' : 'calon'">{{ $r->candidates_count === 1 ? 'candidate' : 'candidates' }}</span> ·
                        {{ $r->hired_count }}/{{ $r->openings }} <span x-text="$store.ui.lang==='en' ? 'hired' : 'diambil'">hired</span>
                    </div>
                </a>
            @empty
                <div style="padding:28px 20px;text-align:center;">
                    <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No open roles yet' : 'Belum ada jawatan dibuka'"></span></div>
                    <div style="font-size:12px;color:var(--muted);line-height:1.5;">@if ($privileged)<span x-text="$store.ui.lang==='en' ? 'Use \'+ New\' above to open your first requisition. Each role you create appears here with its candidate count.' : 'Guna \'+ New\' di atas untuk buka jawatan pertama anda. Setiap peranan yang dibuka muncul di sini dengan bilangan calonnya.'"></span>@else <span x-text="$store.ui.lang==='en' ? 'No roles are being hired right now. New openings will show up here.' : 'Tiada jawatan sedang diambil sekarang. Kekosongan baharu akan muncul di sini.'"></span>@endif</div>
                </div>
            @endforelse
        </div>
    </div>

    {{-- ───────────────────────── Pipeline for selected requisition ───────────────────────── --}}
    <div>
        @if (! $selected)
            <div class="uj-card" style="padding:48px 24px;text-align:center;">
                <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No requisition selected' : 'Tiada jawatan dipilih'"></span></div>
                <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Click a role on the left to see its candidates grouped by hiring stage.' : 'Klik satu peranan di sebelah kiri untuk lihat calonnya mengikut peringkat pengambilan.'"></span> {{ $privileged ? '' : '' }}@if ($privileged)<span x-text="$store.ui.lang==='en' ? 'You can then add candidates and move them through the pipeline.' : 'Anda kemudian boleh tambah calon dan gerakkan mereka melalui saluran.'"></span>@endif</div>
            </div>
        @else
            <div class="uj-card" style="padding:20px;margin-bottom:16px;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;">
                    <div style="min-width:0;">
                        <div style="font-size:16px;font-weight:700;color:var(--ink);">{{ $selected->title }}</div>
                        <div style="font-size:12.5px;color:var(--muted);margin-top:4px;">
                            @if ($selected->department){{ $selected->department->name }}@else<span x-text="$store.ui.lang==='en' ? 'No department' : 'Tiada jabatan'">No department</span>@endif{{ $selected->location ? ' · '.$selected->location : '' }} · {{ $selected->openings }} <span x-text="$store.ui.lang==='en' ? '{{ $selected->openings === 1 ? 'opening' : 'openings' }}' : 'kekosongan'">{{ $selected->openings === 1 ? 'opening' : 'openings' }}</span>
                        </div>
                    </div>
                    {!! $reqPill($selected->status) !!}
                </div>

                @if ($privileged)
                    <div x-data="{ add: {{ $errors->any() && old('_form') === 'cand' ? 'true' : 'false' }} }" style="margin-top:16px;border-top:1px solid var(--hairline-soft);padding-top:16px;">
                        <button @click="add = ! add" class="uj-btn-primary" style="height:34px;padding:0 13px;font-size:12.5px;"><span x-text="add ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal') : ($store.ui.lang==='en' ? '+ Add candidate' : '+ Tambah calon')"></span></button>
                        <form x-show="add" x-cloak method="post" action="{{ route('recruitment.candidates', $selected) }}" style="margin-top:14px;">
                            @csrf
                            <input type="hidden" name="_form" value="cand" />
                            @if ($errors->any() && old('_form') === 'cand')
                                <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:12px;">{{ $errors->first() }}</div>
                            @endif
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
                                <div>
                                    <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Name *' : 'Nama *'">Name *</span></label>
                                    <input name="name" value="{{ old('name') }}" required maxlength="120" :placeholder="$store.ui.lang==='en' ? 'Candidate name' : 'Nama calon'" style="{{ $fs }}width:100%;" />
                                </div>
                                <div>
                                    <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Email' : 'Emel'">Email</span></label>
                                    <input name="email" type="email" value="{{ old('email') }}" maxlength="150" placeholder="name@example.com" style="{{ $fs }}width:100%;" />
                                </div>
                                <div>
                                    <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Phone' : 'Telefon'">Phone</span></label>
                                    <input name="phone" value="{{ old('phone') }}" maxlength="40" :placeholder="$store.ui.lang==='en' ? 'Optional' : 'Pilihan'" style="{{ $fs }}width:100%;" />
                                </div>
                            </div>
                            <div style="margin-top:12px;">
                                <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Notes' : 'Nota'">Notes</span></label>
                                <textarea name="notes" maxlength="2000" rows="2" :placeholder="$store.ui.lang==='en' ? 'Source, screening notes, etc.' : 'Sumber, nota saringan, dll.'" style="width:100%;padding:10px 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;resize:vertical;">{{ old('notes') }}</textarea>
                            </div>
                            <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 18px;font-size:13px;margin-top:14px;"><span x-text="$store.ui.lang==='en' ? 'Add to pipeline' : 'Tambah ke saluran'">Add to pipeline</span></button>
                        </form>
                    </div>
                @endif
            </div>

            {{-- Pipeline columns: one per stage --}}
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;">
                @foreach ($stages as $stage)
                    @php $bucket = $pipeline->get($stage, collect()); @endphp
                    <div class="uj-card" style="padding:0;">
                        <div style="padding:13px 14px;border-bottom:1px solid var(--hairline-soft);display:flex;justify-content:space-between;align-items:center;">
                            <span style="font-size:12.5px;font-weight:700;color:{{ $stageMeta[$stage]['color'] }};" x-text="$store.ui.lang==='en' ? '{{ $stageMeta[$stage]['label'] }}' : '{{ $stageMeta[$stage]['ms'] }}'">{{ $stageMeta[$stage]['label'] }}</span>
                            <span style="font-size:12px;color:var(--muted);font-weight:600;">{{ $bucket->count() }}</span>
                        </div>
                        <div style="padding:10px;">
                            @forelse ($bucket as $c)
                                <div x-data="{ move: false }" style="background:#fff;border:1px solid var(--hairline-soft);border-radius:10px;padding:11px 12px;margin-bottom:10px;">
                                    <div style="font-size:13px;font-weight:600;color:var(--ink);">{{ $c->name }}</div>
                                    @if ($c->email)
                                        <div style="font-size:11.5px;color:var(--muted);margin-top:2px;word-break:break-all;">{{ $c->email }}</div>
                                    @endif
                                    @if ($c->phone)
                                        <div style="font-size:11.5px;color:var(--muted);margin-top:2px;">{{ $c->phone }}</div>
                                    @endif
                                    @if ($c->notes)
                                        <div style="font-size:12px;color:var(--body);margin-top:7px;white-space:pre-line;">{{ $c->notes }}</div>
                                    @endif
                                    @if ($privileged)
                                        <button @click="move = ! move" class="uj-btn-ghost" style="height:28px;padding:0 10px;font-size:11.5px;margin-top:9px;"><span x-text="$store.ui.lang==='en' ? 'Move' : 'Alih'">Move</span></button>
                                        <form x-show="move" x-cloak method="post" action="{{ route('recruitment.move', $c) }}" style="margin-top:10px;">
                                            @csrf
                                            <label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'Stage *' : 'Peringkat *'">Stage *</span></label>
                                            <select name="stage" required style="{{ $fs }}width:100%;height:34px;margin-bottom:6px;">
                                                @foreach ($stages as $opt)
                                                    <option value="{{ $opt }}" @selected($c->stage === $opt) x-text="$store.ui.lang==='en' ? '{{ $stageMeta[$opt]['label'] }}' : '{{ $stageMeta[$opt]['ms'] }}'">{{ $stageMeta[$opt]['label'] }}</option>
                                                @endforeach
                                            </select>
                                            @include('partials.hint', ['en' => 'Move forward as they pass each step. Pick "Hired" to fill an opening, or "Rejected" if they will not proceed.', 'ms' => 'Gerakkan ke hadapan apabila mereka lepas setiap peringkat. Pilih "Hired" untuk isi kekosongan, atau "Rejected" jika mereka tidak diteruskan.'])
                                            <textarea name="notes" maxlength="2000" rows="2" :placeholder="$store.ui.lang==='en' ? 'Update notes (optional)' : 'Nota kemas kini (pilihan)'" style="width:100%;padding:8px 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;background:#fff;color:var(--ink);outline:none;resize:vertical;margin-top:8px;">{{ $c->notes }}</textarea>
                                            <button type="submit" class="uj-btn-primary" style="height:32px;padding:0 14px;font-size:12px;margin-top:9px;"><span x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</span></button>
                                        </form>
                                    @endif
                                </div>
                            @empty
                                <div style="padding:14px 6px;text-align:center;font-size:12px;color:var(--muted);">—</div>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

</div>

@endsection
