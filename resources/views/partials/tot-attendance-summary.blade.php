{{-- CR-09: chair and attendance at the top of the session drawer. Read-only summary
     always shows; a canManageSession actor gets a toggle to change the chair and
     re-record attendance. Two small forms (tot.update for the chair, tot.attendance for
     the list) rather than one combined submit — they are two different endpoints and
     nothing here needs them to save together. --}}
@php
    $presentRows = $session->attendance->where('present', true);
    $absentRows = $session->attendance->where('present', false);
@endphp
<div style="margin-bottom:16px;" x-data="{ managing: false }">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <div>
            <div class="wd-sech" style="margin-bottom:2px;" x-text="$store.ui.lang==='en' ? 'Pengerusi' : 'Pengerusi'">Pengerusi</div>
            <div style="font-size:14px;font-weight:600;color:var(--ink);">{{ $session->chair?->display_name ?? '—' }}</div>
        </div>
        @if ($session->attendance->isNotEmpty())
            <div style="margin-left:auto;text-align:right;font-size:12.5px;color:var(--body);">
                <div>{{ $presentRows->count() }} hadir</div>
                <div>{{ $absentRows->count() }} tidak hadir</div>
            </div>
        @endif
        @if ($canManageSession)
            <button type="button" class="tot-pillbtn" @click="managing = !managing" x-text="$store.ui.lang==='en' ? 'Edit chair &amp; attendance' : 'Sunting pengerusi &amp; kehadiran'">Edit chair &amp; attendance</button>
        @endif
    </div>

    @if ($absentRows->isNotEmpty())
        <ul style="margin:6px 0 0;padding-left:18px;font-size:12.5px;color:var(--body);">
            @foreach ($absentRows as $row)
                <li>{{ $row->employee?->display_name }} — {{ $row->reason }}</li>
            @endforeach
        </ul>
    @endif

    @if ($canManageSession)
        <div x-show="managing" x-cloak style="margin-top:12px;">
            <form method="post" action="{{ route('tot.update', $session) }}" style="max-width:620px;margin-bottom:14px;">
                @csrf
                <input type="hidden" name="year" value="{{ $session->year }}">
                <input type="hidden" name="month" value="{{ $session->month }}">
                <input type="hidden" name="nota_url" value="{{ $session->nota_url }}">
                <input type="hidden" name="next_agenda" value="{{ $session->next_agenda }}">
                <label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Pengerusi' : 'Pengerusi'">Pengerusi</label>
                <select class="tot-field" name="chair_employee_id">
                    <option value="">—</option>
                    @foreach ($assignableEmployees as $e)
                        <option value="{{ $e->id }}" @selected($session->chair_employee_id === $e->id)>{{ $e->name }}</option>
                    @endforeach
                </select>
                <button type="submit" class="tot-btn-g" style="margin-top:8px;" x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</button>
            </form>

            @include('partials.tot-attendance-form', ['session' => $session, 'assignableEmployees' => $assignableEmployees])
        </div>
    @endif
</div>
