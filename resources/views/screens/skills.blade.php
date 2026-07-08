@extends('layouts.app')

@php
    // Proficiency level → cell colour for the heatmap. Higher = greener, low/none = grey.
    $levelColor = [
        0 => 'var(--hairline-soft)',
        1 => 'var(--red-tint)',
        2 => '#fbe6d2',
        3 => '#f3edd2',
        4 => '#e2f0e3',
        5 => '#d2ecdd',
    ];
    $levelInk = [
        0 => 'var(--muted-soft)',
        1 => 'var(--error)',
        2 => 'var(--amber)',
        3 => 'var(--amber)',
        4 => 'var(--success)',
        5 => 'var(--success)',
    ];
    $levelLabel = [1 => 'Novice', 2 => 'Beginner', 3 => 'Competent', 4 => 'Proficient', 5 => 'Expert'];
    $levelLabelMs = [1 => 'Novis', 2 => 'Permulaan', 3 => 'Kompeten', 4 => 'Mahir', 5 => 'Pakar'];
    $catColor = [
        'Technical' => 'var(--info)',
        'Leadership' => 'var(--amber)',
        'Communication' => 'var(--success)',
        'Domain' => 'var(--muted)',
    ];
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'skills',
    'en'  => [
        'title' => 'Skills matrix',
        'body'  => 'A shared catalogue of the skills the company cares about. Everyone self-rates their own proficiency from 1 (Novice) to 5 (Expert). Managers verify ratings, and management/HR see the full team matrix and where the gaps are — feeding development planning.',
        'who'   => 'Everyone self-rates · Managers verify · HR manage catalogue',
        'steps' => [
            'Find a skill in "My skills" below.',
            'Pick your honest proficiency level (1–5) and click Save.',
            'Your manager will review and verify it.',
            'Re-rate any time as you grow — it updates the same record.',
        ],
    ],
    'ms'  => [
        'title' => 'Matriks kemahiran',
        'body'  => 'Katalog kongsi kemahiran yang penting kepada syarikat. Semua orang nilai sendiri tahap kemahiran masing-masing dari 1 (Novice) hingga 5 (Expert). Pengurus sahkan penilaian, dan pengurusan/HR lihat matriks penuh pasukan serta di mana jurangnya — untuk perancangan pembangunan.',
        'who'   => 'Semua nilai sendiri · Pengurus sahkan · HR urus katalog',
        'steps' => [
            'Cari kemahiran dalam "My skills" di bawah.',
            'Pilih tahap kemahiran anda dengan jujur (1–5) dan klik Save.',
            'Pengurus anda akan menyemak dan mengesahkannya.',
            'Nilai semula bila-bila masa apabila anda berkembang — ia mengemas kini rekod yang sama.',
        ],
    ],
])
@if (session('ok'))
    <div style="background:#e7f4ee;border:1px solid var(--success);color:var(--success);font-size:12.5px;border-radius:8px;padding:10px 14px;margin-bottom:16px;">{{ session('ok') }}</div>
@endif

<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    {{-- My skills — self-rating (every employee) --}}
    <div style="flex:2;min-width:340px;display:flex;flex-direction:column;gap:16px;">
        <div class="uj-card" style="padding:0;">
            <div class="uj-card-head">
                <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'My skills' : 'Kemahiran saya'">My skills</span></h3>
                <span style="font-size:12.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Self-rate 1 (Novice) – 5 (Expert)' : 'Nilai sendiri 1 (Novis) – 5 (Pakar)'">Self-rate 1 (Novice) – 5 (Expert)</span>
            </div>
            @forelse ($skills as $skill)
                @php $mine = $myRatings->get($skill->id); @endphp
                <div style="padding:16px 20px;border-bottom:1px solid var(--hairline-soft);">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:5px;flex-wrap:wrap;">
                        <span class="uj-pill" style="background:var(--hairline-soft);color:{{ $catColor[$skill->category] ?? 'var(--muted)' }};">{{ $skill->category }}</span>
                        <span style="font-size:13.5px;font-weight:600;color:var(--ink);">{{ $skill->name }}</span>
                        @if ($mine)
                            <span style="margin-left:auto;font-size:12px;font-weight:500;color:{{ $levelInk[$mine->level] ?? 'var(--muted)' }};">
                                <span x-text="$store.ui.lang==='en' ? @js($levelLabel[$mine->level] ?? '—') : @js($levelLabelMs[$mine->level] ?? '—')">{{ $levelLabel[$mine->level] ?? '—' }}</span> ({{ $mine->level }}/5)
                                @if ($mine->verified)
                                    <span style="color:var(--success);">· ✓ <span x-text="$store.ui.lang==='en' ? 'verified' : 'disahkan'">verified</span></span>
                                @endif
                            </span>
                        @endif
                    </div>
                    @if ($skill->description)
                        <p style="font-size:12.5px;color:var(--muted);margin:0 0 12px;">{{ $skill->description }}</p>
                    @endif

                    @if (! $canRate)
                        <div style="font-size:12.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'No employee profile in this workspace — self-rating is disabled.' : 'Tiada profil pekerja dalam ruang kerja ini — penilaian sendiri dimatikan.'">No employee profile in this workspace — self-rating is disabled.</div>
                    @else
                        <form method="post" action="{{ route('skills.rate') }}" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
                            @csrf
                            <input type="hidden" name="skill_id" value="{{ $skill->id }}" />
                            <div>
                                <label style="display:block;font-size:11.5px;font-weight:500;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Proficiency' : 'Tahap kemahiran'">Proficiency</label>
                                <select name="level" style="height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);">
                                    @for ($l = $minLevel; $l <= $maxLevel; $l++)
                                        <option value="{{ $l }}" @selected(($mine->level ?? 3) === $l) x-text="@js((string) $l . ' — ') + ($store.ui.lang==='en' ? @js($levelLabel[$l]) : @js($levelLabelMs[$l]))">{{ $l }} — {{ $levelLabel[$l] }}</option>
                                    @endfor
                                </select>
                            </div>
                            <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 18px;font-size:13px;" x-text="@js($mine ? true : false) ? ($store.ui.lang==='en' ? 'Update' : 'Kemas kini') : ($store.ui.lang==='en' ? 'Save' : 'Simpan')">{{ $mine ? 'Update' : 'Save' }}</button>
                            <div style="flex-basis:100%;">@include('partials.hint', ['en' => 'Rate yourself honestly. 1 = just starting, 3 = can work independently, 5 = you teach others. Re-rating updates your existing record — it never creates duplicates.', 'ms' => 'Nilai diri anda dengan jujur. 1 = baru mula, 3 = boleh bekerja sendiri, 5 = anda mengajar orang lain. Menilai semula mengemas kini rekod sedia ada — tidak akan buat pendua.'])</div>
                        </form>
                    @endif
                </div>
            @empty
                <div style="padding:28px 20px;text-align:center;">
                    <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No skills yet' : 'Belum ada kemahiran'">No skills yet</span></div>
                    <div style="font-size:12px;color:var(--muted);line-height:1.5;">@if ($canManageCatalog)<span x-text="$store.ui.lang==='en' ? 'Use &quot;Add skill&quot; on the right to create the first one.' : 'Guna &quot;Add skill&quot; di sebelah kanan untuk cipta yang pertama.'">Use "Add skill" on the right to create the first one.</span>@else<span x-text="$store.ui.lang==='en' ? 'No skills have been set up yet. Once HR adds them, you can rate yourself here.' : 'Belum ada kemahiran disediakan. Sebaik HR menambahnya, anda boleh menilai diri di sini.'">No skills have been set up yet. Once HR adds them, you can rate yourself here.</span>@endif</div>
                </div>
            @endforelse
        </div>

        {{-- Team matrix / heatmap (manager+ only) --}}
        @if ($canViewMatrix)
            <div class="uj-card" style="padding:0;">
                <div class="uj-card-head">
                    <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'Team matrix' : 'Matriks pasukan'">Team matrix</span></h3>
                    <span style="font-size:12.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Proficiency heatmap — rows employees, columns skills' : 'Peta haba kemahiran — baris pekerja, lajur kemahiran'">Proficiency heatmap — rows employees, columns skills</span>
                </div>
                @if ($skills->isEmpty() || $matrixEmployees->isEmpty())
                    <div style="padding:28px 20px;text-align:center;font-size:12.5px;color:var(--muted);line-height:1.5;" x-text="$store.ui.lang==='en' ? 'Nothing to chart yet — add skills and let the team self-rate, then the heatmap will fill in here.' : 'Tiada untuk dipetakan lagi — tambah kemahiran dan biar pasukan menilai sendiri, kemudian peta haba akan terisi di sini.'">Nothing to chart yet — add skills and let the team self-rate, then the heatmap will fill in here.</div>
                @else
                    <div style="overflow-x:auto;padding:8px 0 4px;">
                        <table style="border-collapse:collapse;width:100%;font-size:12px;">
                            <thead>
                                <tr>
                                    <th style="position:sticky;left:0;background:#fff;text-align:left;padding:8px 14px;color:var(--muted);font-weight:500;white-space:nowrap;" x-text="$store.ui.lang==='en' ? 'Employee' : 'Pekerja'">Employee</th>
                                    @foreach ($skills as $skill)
                                        <th style="padding:8px 6px;color:var(--muted);font-weight:500;text-align:center;white-space:nowrap;min-width:64px;" title="{{ $skill->name }}">{{ \Illuminate\Support\Str::limit($skill->name, 12) }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($matrixEmployees as $emp)
                                    @php $row = $matrix[$emp->id] ?? collect(); @endphp
                                    <tr>
                                        <td style="position:sticky;left:0;background:#fff;padding:7px 14px;white-space:nowrap;border-top:1px solid var(--hairline-soft);">
                                            <span style="display:inline-flex;align-items:center;gap:7px;">
                                                <span style="width:20px;height:20px;border-radius:50%;background:{{ $emp->avatar_color ?? 'var(--muted)' }};color:#fff;font-size:9px;font-weight:700;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;">{{ $emp->initials ?? \Illuminate\Support\Str::of($emp->name)->substr(0, 1) }}</span>
                                                <span style="color:var(--ink);">{{ $emp->name }}</span>
                                            </span>
                                        </td>
                                        @foreach ($skills as $skill)
                                            @php
                                                $cell = is_array($row) ? ($row[$skill->id] ?? null) : $row->get($skill->id);
                                                $lvl = $cell?->level ?? 0;
                                            @endphp
                                            <td style="border-top:1px solid var(--hairline-soft);text-align:center;padding:5px 6px;">
                                                <span :title="@js($emp->name . ' · ' . $skill->name) + ({{ $cell ? 'true' : 'false' }} ? ' · ' + ($store.ui.lang==='en' ? @js($levelLabel[$lvl] ?? '') : @js($levelLabelMs[$lvl] ?? '')) : ' · ' + ($store.ui.lang==='en' ? 'not rated' : 'belum dinilai'))"
                                                      style="display:inline-block;min-width:26px;padding:3px 0;border-radius:6px;font-weight:600;background:{{ $levelColor[$lvl] ?? 'var(--hairline-soft)' }};color:{{ $levelInk[$lvl] ?? 'var(--muted-soft)' }};">{{ $lvl > 0 ? $lvl : '·' }}</span>
                                                @if ($cell && ! $cell->verified)
                                                    <form method="post" action="{{ route('skills.verify', $cell) }}" style="margin-top:3px;">
                                                        @csrf
                                                        <button type="submit" title="Verify this rating" :title="$store.ui.lang==='en' ? 'Verify this rating' : 'Sahkan penilaian ini'" style="font-size:10px;color:var(--info);background:none;padding:1px 4px;border-radius:5px;" x-text="$store.ui.lang==='en' ? 'verify' : 'sahkan'">verify</button>
                                                    </form>
                                                @elseif ($cell && $cell->verified)
                                                    <div style="font-size:10px;color:var(--success);margin-top:2px;">✓</div>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endif
    </div>

    {{-- Right rail: add-skill form (management/hr) + gap analysis (manager+) --}}
    <div style="flex:1;min-width:300px;display:flex;flex-direction:column;gap:16px;">
        @if ($canManageCatalog)
            <div class="uj-card" style="padding:20px;">
                <h3 class="uj-card-title" style="margin-bottom:14px;"><span x-text="$store.ui.lang==='en' ? 'Add skill' : 'Tambah kemahiran'">Add skill</span></h3>
                <form method="post" action="{{ route('skills.catalog') }}">
                    @csrf
                    @if ($errors->any())
                        <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>
                    @endif

                    <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Skill name' : 'Nama kemahiran'">Skill name</label>
                    <input name="name" value="{{ old('name') }}" required maxlength="120" placeholder="e.g. Payroll Processing" :placeholder="$store.ui.lang==='en' ? 'e.g. Payroll Processing' : 'cth. Pemprosesan Gaji'" style="width:100%;height:40px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;margin-bottom:13px;outline:none;" />

                    <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Category' : 'Kategori'">Category</label>
                    <select name="category" required style="width:100%;height:40px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;background:#fff;color:var(--ink);margin-bottom:13px;">
                        @foreach ($categories as $cat)
                            <option value="{{ $cat }}" @selected(old('category') === $cat)>{{ $cat }}</option>
                        @endforeach
                    </select>

                    <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Description' : 'Penerangan'">Description</label>
                    <textarea name="description" maxlength="1000" rows="2" placeholder="Optional — what this skill means" :placeholder="$store.ui.lang==='en' ? 'Optional — what this skill means' : 'Pilihan — apa maksud kemahiran ini'" style="width:100%;padding:9px 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;resize:vertical;outline:none;margin-bottom:16px;font-family:inherit;">{{ old('description') }}</textarea>

                    <button type="submit" class="uj-btn-primary" style="width:100%;height:42px;font-size:13.5px;" x-text="$store.ui.lang==='en' ? 'Add skill' : 'Tambah kemahiran'">Add skill</button>
                </form>
            </div>
        @endif

        @if ($canViewMatrix)
            <div class="uj-card" style="padding:20px;">
                <h3 class="uj-card-title" style="margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'Gap analysis' : 'Analisis jurang'">Gap analysis</span></h3>
                <p style="font-size:12px;color:var(--muted);margin:0 0 14px;" x-text="$store.ui.lang==='en' ? 'Skills with low team average or thin coverage — candidates for training.' : 'Kemahiran dengan purata pasukan rendah atau liputan tipis — calon untuk latihan.'">Skills with low team average or thin coverage — candidates for training.</p>
                @forelse ($gaps as $gap)
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:11px 0;border-bottom:1px solid var(--hairline-soft);">
                        <div style="min-width:0;">
                            <div style="font-size:13px;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $gap['skill']->name }}</div>
                            <div style="font-size:11.5px;color:var(--muted);">{{ $gap['rated'] }} <span x-text="$store.ui.lang==='en' ? 'rated' : 'dinilai'">rated</span> · {{ $gap['coverage'] }}% <span x-text="$store.ui.lang==='en' ? 'coverage' : 'liputan'">coverage</span></div>
                        </div>
                        @if ($gap['rated'] === 0)
                            <span style="font-size:13.5px;font-weight:600;font-family:var(--font-mono);color:var(--error);flex-shrink:0;" x-text="$store.ui.lang==='en' ? 'none' : 'tiada'">none</span>
                        @else
                            <span style="font-size:13.5px;font-weight:600;font-family:var(--font-mono);color:var(--amber);flex-shrink:0;"><span x-text="$store.ui.lang==='en' ? 'avg' : 'purata'">avg</span> {{ $gap['avg'] }}</span>
                        @endif
                    </div>
                @empty
                    <div style="font-size:12.5px;color:var(--success);line-height:1.5;" x-text="$store.ui.lang==='en' ? 'No gaps — every skill has solid coverage and a healthy team average.' : 'Tiada jurang — setiap kemahiran ada liputan kukuh dan purata pasukan yang sihat.'">No gaps — every skill has solid coverage and a healthy team average.</div>
                @endforelse
            </div>
        @endif
    </div>
</div>
@endsection
