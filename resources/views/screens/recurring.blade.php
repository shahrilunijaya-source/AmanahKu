@extends('layouts.app')

@section('screen')
@include('partials.guide', [
    'key' => 'recurring',
    'en'  => [
        'title' => 'Recurring tasks',
        'body'  => 'A recurring task puts a card on someone\'s board every period without anyone raising it by hand: the company social every two months, a quarterly fire drill. The owner is a person or a position, so when someone leaves, the next card goes to whoever now holds the role.',
        'who'   => 'HR & Management',
        'steps' => [
            'Add a schedule: title, how often, the first date, the owner (a person or a position) and the people to tag as helpers.',
            'List the subtasks one per line. Every card gets them, in that order, with the same due date as the card.',
            'The engine runs each morning. It makes the card on the first working day of the period, due at the end of that month (or week).',
            'Skip one period with a reason, or pause the whole schedule. Nothing is created until you resume.',
        ],
    ],
    'ms'  => [
        'title' => 'Tugasan berulang',
        'body'  => 'Tugasan berulang meletakkan kad di papan seseorang setiap tempoh tanpa sesiapa perlu mengangkatnya: aktiviti sosial syarikat setiap dua bulan, latihan kebakaran setiap suku tahun. Pemilik ialah seseorang atau satu jawatan, jadi apabila seseorang berhenti, kad seterusnya pergi kepada pemegang jawatan semasa.',
        'who'   => 'HR & Pengurusan',
        'steps' => [
            'Tambah jadual: tajuk, kekerapan, tarikh pertama, pemilik (seseorang atau jawatan) dan orang yang ditag sebagai pembantu.',
            'Senaraikan subtugas satu setiap baris. Setiap kad mendapatnya, mengikut susunan itu, dengan tarikh akhir yang sama.',
            'Enjin berjalan setiap pagi. Ia membuat kad pada hari bekerja pertama tempoh itu, tamat pada hujung bulan (atau minggu) itu.',
            'Langkau satu tempoh dengan sebab, atau jeda keseluruhan jadual. Tiada apa dibuat sehingga anda sambung semula.',
        ],
    ],
])

@php
    $inputStyle = 'width:100%;height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;background:#fff;color:var(--ink);';
    $labelStyle = 'display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;';
    $frequencyWords = ['weekly' => ['Every week', 'Setiap minggu'], 'monthly' => ['Every month', 'Setiap bulan'], 'every_n_months' => ['Every N months', 'Setiap N bulan'], 'yearly' => ['Every year', 'Setiap tahun']];
@endphp

@if (session('ok'))
    <div class="uj-card" style="padding:12px 18px;margin-bottom:14px;font-size:13px;color:var(--ink);border-left:3px solid var(--green,#1c7c54);">{{ session('ok') }}</div>
@endif
@if ($errors->any())
    <div class="uj-card" style="padding:12px 18px;margin-bottom:14px;font-size:13px;color:var(--error);border-left:3px solid var(--error);">{{ $errors->first() }}</div>
@endif

{{-- ============================ ADD A SCHEDULE ============================ --}}
<div class="uj-card" style="padding:0;margin-bottom:14px;" x-data="{ open: {{ $errors->any() ? 'true' : 'false' }}, ownerKind: 'position', frequency: 'every_n_months' }">
    <button @click="open = ! open" type="button" style="width:100%;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 20px;background:none;cursor:pointer;border:0;">
        <span style="display:flex;align-items:center;gap:9px;font-size:13.5px;font-weight:600;color:var(--ink);">
            <span style="width:24px;height:24px;border-radius:7px;background:var(--red-tint);color:var(--red);display:flex;align-items:center;justify-content:center;font-size:16px;line-height:1;">+</span>
            <span x-text="$store.ui.lang==='en' ? 'Add a recurring task' : 'Tambah tugasan berulang'">Add a recurring task</span>
        </span>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" :style="open ? 'transform:rotate(180deg);transition:.15s' : 'transition:.15s'"><path d="M6 9l6 6 6-6"/></svg>
    </button>
    <div x-show="open" x-cloak style="padding:18px 22px;border-top:1px solid var(--hairline);">
        <form method="post" action="{{ route('admin.recurring.store') }}" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;" data-recurring-form>
            @csrf
            <div style="flex:2;min-width:240px;">
                <label style="{{ $labelStyle }}"><span x-text="$store.ui.lang==='en' ? 'Title *' : 'Tajuk *'">Title *</span></label>
                <input name="title" required maxlength="160" value="{{ old('title') }}" placeholder="Organise company social activity" style="{{ $inputStyle }}" />
            </div>
            <div style="width:170px;">
                <label style="{{ $labelStyle }}"><span x-text="$store.ui.lang==='en' ? 'How often *' : 'Kekerapan *'">How often *</span></label>
                <select name="frequency" x-model="frequency" style="{{ $inputStyle }}">
                    @foreach ($frequencyWords as $key => [$en, $ms])
                        <option value="{{ $key }}" @selected(old('frequency', 'every_n_months') === $key)>{{ $en }}</option>
                    @endforeach
                </select>
            </div>
            <div style="width:110px;" x-show="frequency !== 'monthly'">
                <label style="{{ $labelStyle }}"><span x-text="$store.ui.lang==='en' ? 'Every N' : 'Setiap N'">Every N</span></label>
                <input type="number" name="interval" min="1" max="60" value="{{ old('interval', 2) }}" style="{{ $inputStyle }}" />
            </div>
            <div style="width:170px;">
                <label style="{{ $labelStyle }}"><span x-text="$store.ui.lang==='en' ? 'First period starts *' : 'Tempoh pertama bermula *'">First period starts *</span></label>
                <input type="date" name="start_on" required value="{{ old('start_on') }}" style="{{ $inputStyle }}" />
            </div>

            <div style="flex:1 1 100%;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                <div style="width:160px;">
                    <label style="{{ $labelStyle }}"><span x-text="$store.ui.lang==='en' ? 'Owner is a' : 'Pemilik ialah'">Owner is a</span></label>
                    <select x-model="ownerKind" style="{{ $inputStyle }}">
                        <option value="position" x-text="$store.ui.lang==='en' ? 'Position (role)' : 'Jawatan (peranan)'">Position (role)</option>
                        <option value="person" x-text="$store.ui.lang==='en' ? 'Person' : 'Orang'">Person</option>
                    </select>
                </div>
                <div style="flex:1;min-width:220px;" x-show="ownerKind === 'position'">
                    <label style="{{ $labelStyle }}"><span x-text="$store.ui.lang==='en' ? 'Position title' : 'Nama jawatan'">Position title</span></label>
                    <input name="owner_position_title" list="recurring-positions" maxlength="120" value="{{ old('owner_position_title') }}" placeholder="Finance Manager" style="{{ $inputStyle }}" :disabled="ownerKind !== 'position'" />
                    <datalist id="recurring-positions">
                        @foreach ($schedulePositions as $title)<option value="{{ $title }}"></option>@endforeach
                    </datalist>
                </div>
                <div style="flex:1;min-width:220px;" x-show="ownerKind === 'person'" x-cloak>
                    <label style="{{ $labelStyle }}"><span x-text="$store.ui.lang==='en' ? 'Person' : 'Orang'">Person</span></label>
                    <select name="owner_employee_id" style="{{ $inputStyle }}" :disabled="ownerKind !== 'person'">
                        <option value=""></option>
                        @foreach ($schedulePeople as $p)<option value="{{ $p->id }}" @selected((int) old('owner_employee_id') === $p->id)>{{ $p->name }}</option>@endforeach
                    </select>
                </div>
                <div style="width:170px;">
                    <label style="{{ $labelStyle }}"><span x-text="$store.ui.lang==='en' ? 'Project' : 'Projek'">Project</span></label>
                    <select name="project_id" style="{{ $inputStyle }}">
                        <option value=""></option>
                        @foreach ($scheduleProjects as $p)<option value="{{ $p->id }}" @selected((int) old('project_id') === $p->id)>{{ $p->name }}</option>@endforeach
                    </select>
                </div>
                <div style="width:120px;">
                    <label style="{{ $labelStyle }}"><span x-text="$store.ui.lang==='en' ? 'Priority' : 'Keutamaan'">Priority</span></label>
                    <select name="priority" style="{{ $inputStyle }}">
                        @foreach (['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $v => $l)<option value="{{ $v }}" @selected(old('priority', 'medium') === $v)>{{ $l }}</option>@endforeach
                    </select>
                </div>
            </div>

            <div style="flex:1 1 100%;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start;">
                <div style="flex:1;min-width:240px;">
                    <label style="{{ $labelStyle }}"><span x-text="$store.ui.lang==='en' ? 'Tag as helpers' : 'Tag sebagai pembantu'">Tag as helpers</span></label>
                    <div style="max-height:150px;overflow:auto;border:1px solid var(--hairline);border-radius:8px;padding:8px 12px;display:flex;flex-direction:column;gap:4px;">
                        @foreach ($schedulePeople as $p)
                            <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--ink);"><input type="checkbox" name="tagged_employee_ids[]" value="{{ $p->id }}" @checked(in_array($p->id, (array) old('tagged_employee_ids', []))) /> {{ $p->name }}</label>
                        @endforeach
                    </div>
                </div>
                <div style="flex:1;min-width:240px;">
                    <label style="{{ $labelStyle }}"><span x-text="$store.ui.lang==='en' ? 'Subtasks, one per line' : 'Subtugas, satu setiap baris'">Subtasks, one per line</span></label>
                    <textarea name="subtasks_text" rows="6" placeholder="Propose 2 to 3 ideas with budget&#10;Director approval&#10;Create Event in The Playground with all staff as attendees&#10;Run the activity&#10;Post photos and lessons learnt" style="{{ $inputStyle }}height:auto;padding:8px 12px;font-family:inherit;">{{ old('subtasks_text') }}</textarea>
                </div>
                <div style="width:150px;display:flex;flex-direction:column;gap:10px;">
                    <div>
                        <label style="{{ $labelStyle }}"><span x-text="$store.ui.lang==='en' ? 'Create days early' : 'Buat lebih awal (hari)'">Create days early</span></label>
                        <input type="number" name="lead_days" min="0" max="60" value="{{ old('lead_days', 0) }}" style="{{ $inputStyle }}" />
                    </div>
                    <div>
                        <label style="{{ $labelStyle }}"><span x-text="$store.ui.lang==='en' ? 'Min. attended' : 'Min. hadir'">Min. attended</span></label>
                        <input type="number" name="min_attended" min="1" max="1000" value="{{ old('min_attended', 1) }}" style="{{ $inputStyle }}" />
                    </div>
                </div>
            </div>

            <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 16px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Add schedule' : 'Tambah jadual'">Add schedule</span></button>
        </form>
    </div>
</div>

{{-- ============================ THE SCHEDULES ============================ --}}
@if ($schedules->isEmpty())
    <div class="uj-card" style="padding:22px;text-align:center;color:var(--muted);font-size:13px;">
        <span x-text="$store.ui.lang==='en' ? 'No recurring tasks yet.' : 'Tiada tugasan berulang lagi.'">No recurring tasks yet.</span>
    </div>
@else
    <div class="uj-card" style="padding:6px 0;margin-bottom:14px;">
        @foreach ($schedules as $s)
            <div x-data="{ skipping: false }" data-recurring-row="{{ $s->id }}" style="padding:12px 20px;{{ ! $loop->last ? 'border-bottom:1px solid var(--hairline-soft);' : '' }}">
                <div style="display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap;">
                    <div style="flex:1;min-width:240px;">
                        <div style="font-size:13.5px;font-weight:600;color:var(--ink);display:flex;align-items:center;gap:8px;">
                            {{ $s->title }}
                            @if ($s->isPaused())
                                <span style="font-size:10.5px;font-weight:600;color:var(--muted);background:var(--canvas);border:1px solid var(--hairline);padding:1px 8px;border-radius:9999px;" x-text="$store.ui.lang==='en' ? 'Paused' : 'Dijeda'">Paused</span>
                            @endif
                        </div>
                        <div style="font-size:12px;color:var(--muted);margin-top:3px;">
                            {{ $s->cadenceText() }}
                            · <span x-text="$store.ui.lang==='en' ? 'Owner' : 'Pemilik'">Owner</span>:
                            {{ $s->owner_position_title ?: $s->owner?->display_name }}
                            @if ($s->owner_position_title)
                                <span style="color:var(--ink);">({{ $scheduleHolders[$s->id] ?? 'nobody holds it' }})</span>
                            @endif
                            @if ($s->project) · {{ $s->project->name }} @endif
                            · {{ ucfirst($s->priority) }}
                            @if (! empty($s->subtasks)) · {{ count($s->subtasks) }} <span x-text="$store.ui.lang==='en' ? 'subtasks' : 'subtugas'">subtasks</span> @endif
                        </div>
                        @if ($s->occurrences->isNotEmpty())
                            <div style="font-size:11.5px;color:var(--muted);margin-top:6px;display:flex;gap:6px;flex-wrap:wrap;">
                                @foreach ($s->occurrences as $o)
                                    <span style="border:1px solid var(--hairline);border-radius:6px;padding:1px 7px;{{ $o->work_item_id ? '' : 'text-decoration:line-through;' }}" title="{{ $o->skipped_reason }}">{{ $o->period->format('j M Y') }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <button type="button" class="uj-btn-ghost" style="height:28px;font-size:11.5px;padding:0 10px;" @click="skipping = ! skipping"><span x-text="$store.ui.lang==='en' ? 'Skip a period' : 'Langkau tempoh'">Skip a period</span></button>
                        @if ($s->isPaused())
                            <form method="post" action="{{ route('admin.recurring.resume', $s) }}">@csrf<button type="submit" class="uj-btn-primary" style="height:28px;font-size:11.5px;padding:0 12px;"><span x-text="$store.ui.lang==='en' ? 'Resume' : 'Sambung'">Resume</span></button></form>
                        @else
                            <form method="post" action="{{ route('admin.recurring.pause', $s) }}">@csrf<button type="submit" class="uj-btn-ghost" style="height:28px;font-size:11.5px;padding:0 10px;"><span x-text="$store.ui.lang==='en' ? 'Pause' : 'Jeda'">Pause</span></button></form>
                        @endif
                    </div>
                </div>
                <form x-show="skipping" x-cloak method="post" action="{{ route('admin.recurring.skip', $s) }}" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;padding:10px 0 2px;">
                    @csrf
                    <input type="date" name="period" required style="{{ $inputStyle }}width:170px;height:34px;" />
                    <input name="reason" required maxlength="255" placeholder="Why this period is skipped" style="{{ $inputStyle }}flex:1;min-width:200px;height:34px;" />
                    <button type="submit" class="uj-btn-primary" style="height:34px;padding:0 14px;font-size:12.5px;"><span x-text="$store.ui.lang==='en' ? 'Skip it' : 'Langkau'">Skip it</span></button>
                </form>
            </div>
        @endforeach
    </div>
@endif
@endsection
