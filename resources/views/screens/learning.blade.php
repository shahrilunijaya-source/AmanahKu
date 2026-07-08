@extends('layouts.app')

@php
    $catColor = [
        'Technical' => 'var(--info)',
        'Leadership' => 'var(--amber)',
        'Compliance' => 'var(--red)',
        'Soft Skills' => 'var(--success)',
    ];
    $statusLabel = ['enrolled' => 'Enrolled', 'in_progress' => 'In progress', 'completed' => 'Completed'];
    $statusLabelMs = ['enrolled' => 'Didaftar', 'in_progress' => 'Sedang berjalan', 'completed' => 'Selesai'];
    $statusColor = ['enrolled' => 'var(--muted)', 'in_progress' => 'var(--amber)', 'completed' => 'var(--success)'];
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'learning',
    'en'  => [
        'title' => 'Learning library',
        'body'  => 'Browse the company course catalogue and enrol in whatever you want to learn. This is self-serve — pick a course, track your own progress, and mark it complete when you finish. Separate from assigned compliance training.',
        'who'   => 'Staff browse & enrol · HR add courses',
        'steps' => [
            'Browse the catalogue on the left — each card shows the category, level, length and provider.',
            'Click Enrol on any course you want to take. You enrol once per course.',
            'Open "My learning" to update your progress or mark a course complete.',
            'Reaching 100% completes the course automatically.',
        ],
    ],
    'ms'  => [
        'title' => 'Perpustakaan pembelajaran',
        'body'  => 'Lihat katalog kursus syarikat dan daftar mana-mana yang anda mahu pelajari. Ini layan-diri — pilih kursus, jejak kemajuan sendiri, dan tanda selesai apabila habis. Berasingan daripada latihan compliance yang ditetapkan.',
        'who'   => 'Staf lihat & daftar · HR tambah kursus',
        'steps' => [
            'Lihat katalog di sebelah kiri — setiap kad tunjuk kategori, tahap, tempoh dan penyedia.',
            'Klik Enrol pada kursus yang anda mahu ikut. Daftar sekali sahaja setiap kursus.',
            'Buka "My learning" untuk kemas kini kemajuan atau tanda kursus selesai.',
            'Mencapai 100% akan menyelesaikan kursus secara automatik.',
        ],
    ],
])
@if (session('ok'))
    <div style="background:#e7f4ee;border:1px solid var(--success);color:var(--success);font-size:12.5px;border-radius:8px;padding:10px 14px;margin-bottom:16px;">{{ session('ok') }}</div>
@endif

<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    {{-- Course catalogue + my learning --}}
    <div style="flex:2;min-width:340px;display:flex;flex-direction:column;gap:16px;">
        <div class="uj-card" style="padding:0;">
            <div class="uj-card-head">
                <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'Course catalogue' : 'Katalog kursus'">Course catalogue</span></h3>
                <span style="font-size:12.5px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Self-serve — enrol in whatever you like' : 'Layan-diri — daftar mana-mana yang anda suka'">Self-serve — enrol in whatever you like</span></span>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;padding:18px 20px;">
                @forelse ($courses as $course)
                    @php $mine = $myEnrollments->get($course->id); @endphp
                    <div style="border:1px solid var(--hairline-soft);border-radius:10px;padding:15px 16px;display:flex;flex-direction:column;gap:8px;">
                        <div style="display:flex;align-items:center;gap:7px;flex-wrap:wrap;">
                            <span class="uj-pill" style="background:var(--hairline-soft);color:{{ $catColor[$course->category] ?? 'var(--muted)' }};">{{ $course->category }}</span>
                            <span style="font-size:11.5px;color:var(--muted);">{{ $course->level }}</span>
                            @if (! is_null($course->duration_hours))
                                <span style="margin-left:auto;font-size:11.5px;color:var(--muted);font-family:var(--font-mono);">{{ rtrim(rtrim(number_format((float) $course->duration_hours, 1), '0'), '.') }}h</span>
                            @endif
                        </div>
                        <div style="font-size:14px;font-weight:600;color:var(--ink);">{{ $course->title }}</div>
                        @if ($course->provider)
                            <div style="font-size:12px;color:var(--muted);">{{ $course->provider }}</div>
                        @endif
                        @if ($course->description)
                            <p style="font-size:12.5px;color:var(--muted);margin:0;line-height:1.5;">{{ $course->description }}</p>
                        @endif

                        @if ($mine)
                            <div style="margin-top:auto;">
                                <div style="display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:5px;">
                                    <span style="font-size:11.5px;font-weight:600;color:{{ $statusColor[$mine->status] ?? 'var(--muted)' }};" x-text="$store.ui.lang==='en' ? '{{ addslashes($statusLabel[$mine->status] ?? ucfirst($mine->status)) }}' : '{{ addslashes($statusLabelMs[$mine->status] ?? ucfirst($mine->status)) }}'">{{ $statusLabel[$mine->status] ?? ucfirst($mine->status) }}</span>
                                    <span style="font-size:11.5px;color:var(--muted);font-family:var(--font-mono);">{{ $mine->progress }}%</span>
                                </div>
                                <div style="height:6px;border-radius:6px;background:var(--hairline-soft);overflow:hidden;">
                                    <div style="height:100%;width:{{ $mine->progress }}%;background:{{ $mine->status === 'completed' ? 'var(--success)' : 'var(--info)' }};"></div>
                                </div>
                            </div>
                        @elseif (! $canEnroll)
                            <div style="margin-top:auto;font-size:12px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'No employee profile in this workspace — enrolment disabled.' : 'Tiada profil pekerja dalam workspace ini — pendaftaran dimatikan.'"></span></div>
                        @else
                            <form method="post" action="{{ route('learning.enroll', $course) }}" style="margin-top:auto;">
                                @csrf
                                <button type="submit" class="uj-btn-primary" style="width:100%;height:38px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Enrol' : 'Daftar'">Enrol</span></button>
                            </form>
                        @endif
                    </div>
                @empty
                    <div style="grid-column:1/-1;padding:28px 20px;text-align:center;">
                        <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No courses yet' : 'Belum ada kursus'"></span></div>
                        <div style="font-size:12px;color:var(--muted);line-height:1.5;">
                            @if ($privileged)
                                <span x-text="$store.ui.lang==='en' ? 'Use the Add course form on the right to create the first one. Courses you add show up here for staff to enrol.' : 'Guna borang Add course di sebelah kanan untuk cipta yang pertama. Kursus yang anda tambah muncul di sini untuk staf daftar.'"></span>
                            @else
                                <span x-text="$store.ui.lang==='en' ? 'No courses have been set up yet. Once HR adds them, you can enrol from here.' : 'Belum ada kursus disediakan. Sebaik HR menambahnya, anda boleh daftar dari sini.'"></span>
                            @endif
                        </div>
                    </div>
                @endforelse
            </div>
        </div>

        {{-- My learning: enrolled courses with progress + complete controls --}}
        @php $enrolled = $courses->filter(fn ($c) => $myEnrollments->get($c->id)); @endphp
        @if ($enrolled->isNotEmpty())
            <div class="uj-card" style="padding:0;">
                <div class="uj-card-head">
                    <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'My learning' : 'Pembelajaran saya'">My learning</span></h3>
                    <span style="font-size:12.5px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Update your progress or mark complete' : 'Kemas kini kemajuan atau tanda selesai'">Update your progress or mark complete</span></span>
                </div>
                @foreach ($enrolled as $course)
                    @php $mine = $myEnrollments->get($course->id); @endphp
                    <div style="padding:16px 20px;border-bottom:1px solid var(--hairline-soft);">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap;">
                            <span style="font-size:13.5px;font-weight:600;color:var(--ink);">{{ $course->title }}</span>
                            <span class="uj-pill" style="background:var(--hairline-soft);color:{{ $statusColor[$mine->status] ?? 'var(--muted)' }};" x-text="$store.ui.lang==='en' ? '{{ addslashes($statusLabel[$mine->status] ?? ucfirst($mine->status)) }}' : '{{ addslashes($statusLabelMs[$mine->status] ?? ucfirst($mine->status)) }}'">{{ $statusLabel[$mine->status] ?? ucfirst($mine->status) }}</span>
                            <span style="margin-left:auto;font-size:12px;color:var(--muted);font-family:var(--font-mono);">{{ $mine->progress }}%</span>
                        </div>
                        <div style="height:7px;border-radius:7px;background:var(--hairline-soft);overflow:hidden;margin-bottom:12px;">
                            <div style="height:100%;width:{{ $mine->progress }}%;background:{{ $mine->status === 'completed' ? 'var(--success)' : 'var(--info)' }};"></div>
                        </div>

                        @if ($mine->status !== 'completed')
                            <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
                                <form method="post" action="{{ route('learning.progress', $course) }}" style="display:flex;gap:10px;align-items:flex-end;">
                                    @csrf
                                    <div>
                                        <label style="display:block;font-size:11.5px;font-weight:500;color:var(--muted);margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'Progress %' : 'Kemajuan %'">Progress %</span></label>
                                        <input type="number" name="progress" min="0" max="100" value="{{ $mine->progress }}" style="width:90px;height:36px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
                                    </div>
                                    <button type="submit" class="uj-btn-ghost" style="height:36px;padding:0 16px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Update' : 'Kemas kini'">Update</span></button>
                                </form>
                                <form method="post" action="{{ route('learning.complete', $course) }}">
                                    @csrf
                                    <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Mark complete' : 'Tanda selesai'">Mark complete</span></button>
                                </form>
                            </div>
                        @else
                            <div style="font-size:12.5px;color:var(--success);font-weight:500;">✓ <span x-text="$store.ui.lang==='en' ? 'Completed' : 'Selesai'">Completed</span>{{ $mine->completed_at ? ' ' : '' }}@if ($mine->completed_at)<span x-text="$store.ui.lang==='en' ? 'on' : 'pada'">on</span> {{ $mine->completed_at->format('d M Y') }}@endif</div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Privileged: add a course + enrollment counts --}}
    <div style="flex:1;min-width:300px;display:flex;flex-direction:column;gap:16px;">
        @if ($privileged)
            <div class="uj-card" style="padding:20px;">
                <h3 class="uj-card-title" style="margin-bottom:14px;"><span x-text="$store.ui.lang==='en' ? 'Add course' : 'Tambah kursus'">Add course</span></h3>
                <form method="post" action="{{ route('learning.courses') }}">
                    @csrf
                    @if ($errors->any())
                        <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>
                    @endif

                    <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Course title' : 'Tajuk kursus'">Course title</span></label>
                    <input name="title" value="{{ old('title') }}" required maxlength="160" :placeholder="$store.ui.lang==='en' ? 'e.g. Leadership Essentials' : 'cth. Asas Kepimpinan'" style="width:100%;height:40px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;margin-bottom:13px;outline:none;" />

                    <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Category' : 'Kategori'">Category</span></label>
                    <select name="category" required style="width:100%;height:40px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;background:#fff;color:var(--ink);margin-bottom:13px;">
                        <option value="Technical" @selected(old('category') === 'Technical')>Technical</option>
                        <option value="Leadership" @selected(old('category') === 'Leadership')>Leadership</option>
                        <option value="Compliance" @selected(old('category') === 'Compliance')>Compliance</option>
                        <option value="Soft Skills" @selected(old('category') === 'Soft Skills')>Soft Skills</option>
                    </select>
                    @include('partials.hint', ['en' => 'Category sets the colour tag and helps staff find courses. Use Compliance only for required/regulatory training.', 'ms' => 'Kategori menetapkan tag warna dan membantu staf cari kursus. Guna Compliance hanya untuk latihan wajib/peraturan.'])

                    <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Level' : 'Tahap'">Level</span></label>
                    <select name="level" required style="width:100%;height:40px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;background:#fff;color:var(--ink);margin-bottom:13px;">
                        <option value="Beginner" @selected(old('level') === 'Beginner')>Beginner</option>
                        <option value="Intermediate" @selected(old('level') === 'Intermediate')>Intermediate</option>
                        <option value="Advanced" @selected(old('level') === 'Advanced')>Advanced</option>
                    </select>

                    <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Provider' : 'Penyedia'">Provider</span></label>
                    <input name="provider" value="{{ old('provider') }}" maxlength="120" :placeholder="$store.ui.lang==='en' ? 'Optional — e.g. Microsoft, PMI' : 'Pilihan — cth. Microsoft, PMI'" style="width:100%;height:40px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;margin-bottom:13px;outline:none;" />

                    <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Duration (hours)' : 'Tempoh (jam)'">Duration (hours)</span></label>
                    <input type="number" name="duration_hours" value="{{ old('duration_hours') }}" step="0.5" min="0" :placeholder="$store.ui.lang==='en' ? 'Optional' : 'Pilihan'" style="width:100%;height:40px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;margin-bottom:6px;outline:none;" />
                    @include('partials.hint', ['en' => 'Rough learning hours, shown on the course card. Leave blank if you are not sure.', 'ms' => 'Anggaran jam pembelajaran, dipapar pada kad kursus. Biar kosong jika tidak pasti.'])

                    <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Description' : 'Penerangan'">Description</span></label>
                    <textarea name="description" maxlength="1000" rows="2" :placeholder="$store.ui.lang==='en' ? 'Optional — what the course covers' : 'Pilihan — apa yang dirangkumi kursus'" style="width:100%;padding:9px 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;resize:vertical;outline:none;margin-bottom:16px;font-family:inherit;">{{ old('description') }}</textarea>

                    <button type="submit" class="uj-btn-primary" style="width:100%;height:42px;font-size:13.5px;"><span x-text="$store.ui.lang==='en' ? 'Add course' : 'Tambah kursus'">Add course</span></button>
                </form>
            </div>

            <div class="uj-card" style="padding:20px;">
                <h3 class="uj-card-title" style="margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'Enrolment counts' : 'Bilangan pendaftaran'">Enrolment counts</span></h3>
                <p style="font-size:12px;color:var(--muted);margin:0 0 14px;"><span x-text="$store.ui.lang==='en' ? 'Employees enrolled per course.' : 'Pekerja yang mendaftar setiap kursus.'">Employees enrolled per course.</span></p>
                @forelse ($allCourses as $course)
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:11px 0;border-bottom:1px solid var(--hairline-soft);">
                        <div style="min-width:0;">
                            <div style="font-size:13px;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $course->title }}</div>
                            <div style="font-size:11.5px;color:var(--muted);">{{ $course->category }}@unless ($course->is_active) · <span x-text="$store.ui.lang==='en' ? 'inactive' : 'tidak aktif'">inactive</span> @endunless</div>
                        </div>
                        <span style="font-size:14px;font-weight:600;font-family:var(--font-mono);color:var(--ink);flex-shrink:0;">{{ $course->enrolled_count }}</span>
                    </div>
                @empty
                    <div style="font-size:12.5px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'No courses created yet. Add one with the form above — enrolment numbers appear here as staff sign up.' : 'Belum ada kursus dicipta. Tambah satu dengan borang di atas — bilangan pendaftaran muncul di sini apabila staf mendaftar.'"></span></div>
                @endforelse
            </div>
        @endif
    </div>
</div>
@endsection
