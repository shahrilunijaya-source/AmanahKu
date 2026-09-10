@extends('layouts.app')

@section('screen')
@php
    $inputStyle = 'width:100%;height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;background:#fff;color:var(--ink);';
    $labelStyle = 'display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;';
    $s = $meetingSettings;
    $dayWords = [1 => ['Monday', 'Isnin'], 2 => ['Tuesday', 'Selasa'], 3 => ['Wednesday', 'Rabu'], 4 => ['Thursday', 'Khamis'], 5 => ['Friday', 'Jumaat'], 6 => ['Saturday', 'Sabtu'], 7 => ['Sunday', 'Ahad']];
    $roleWords = ['manager' => ['Manager', 'Pengurus'], 'hr' => ['HR', 'HR'], 'management' => ['Management', 'Pengurusan'], 'director' => ['Director', 'Pengarah']];
@endphp

@if (session('ok'))
    <div class="uj-card" style="padding:12px 18px;margin-bottom:14px;font-size:13px;color:var(--ink);border-left:3px solid var(--green,#1c7c54);">{{ session('ok') }}</div>
@endif
@if ($errors->any())
    <div class="uj-card" style="padding:12px 18px;margin-bottom:14px;font-size:13px;color:var(--error);border-left:3px solid var(--error);">{{ $errors->first() }}</div>
@endif

<div class="uj-card" style="padding:18px 22px;margin-bottom:14px;">
    <p style="font-size:12.5px;color:var(--muted);margin:0 0 14px;" x-text="$store.ui.lang==='en'
        ? 'Every Friday, each PM/PE and manager gets their own \'Update Track for management meeting\' card, due at the meeting time. A generic email reminder (queued, not sent in this environment) goes out earlier the same day. If the meeting day falls on a public holiday, both move to the working day before it.'
        : 'Setiap hari Jumaat, setiap PM/PE dan pengurus menerima kad \'Update Track for management meeting\' sendiri, tamat pada waktu mesyuarat. E-mel peringatan generik (dalam giliran, tidak dihantar dalam persekitaran ini) dihantar lebih awal pada hari yang sama. Jika hari mesyuarat jatuh pada cuti umum, kedua-duanya beralih ke hari bekerja sebelumnya.'">
    </p>

    <form method="post" action="{{ route('admin.management-meeting.update') }}" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
        @csrf
        <div style="width:150px;">
            <label style="{{ $labelStyle }}"><span x-text="$store.ui.lang==='en' ? 'Meeting day' : 'Hari mesyuarat'">Meeting day</span></label>
            <select name="meeting_day" style="{{ $inputStyle }}">
                @foreach ($dayWords as $num => [$en, $ms])
                    <option value="{{ $num }}" @selected($s->meeting_day === $num)>{{ $en }}</option>
                @endforeach
            </select>
        </div>
        <div style="width:120px;">
            <label style="{{ $labelStyle }}"><span x-text="$store.ui.lang==='en' ? 'Meeting time' : 'Waktu mesyuarat'">Meeting time</span></label>
            <input type="time" name="meeting_time" value="{{ $s->meeting_time }}" style="{{ $inputStyle }}" />
        </div>
        <div style="width:120px;">
            <label style="{{ $labelStyle }}"><span x-text="$store.ui.lang==='en' ? 'Task created' : 'Kad dicipta'">Task created</span></label>
            <input type="time" name="task_time" value="{{ $s->task_time }}" style="{{ $inputStyle }}" />
        </div>
        <div style="width:120px;">
            <label style="{{ $labelStyle }}"><span x-text="$store.ui.lang==='en' ? 'Reminder sent' : 'Peringatan dihantar'">Reminder sent</span></label>
            <input type="time" name="reminder_time" value="{{ $s->reminder_time }}" style="{{ $inputStyle }}" />
        </div>
        <div style="width:160px;">
            <label style="{{ $labelStyle }}"><span x-text="$store.ui.lang==='en' ? 'Paused until' : 'Dijeda sehingga'">Paused until</span></label>
            <input type="date" name="paused_until" value="{{ $s->paused_until?->toDateString() }}" style="{{ $inputStyle }}" />
        </div>

        <div style="flex:1 1 100%;">
            <label style="{{ $labelStyle }}"><span x-text="$store.ui.lang==='en' ? 'Who gets the card and the email' : 'Siapa menerima kad dan e-mel'">Who gets the card and the email</span></label>
            <div style="display:flex;gap:14px;flex-wrap:wrap;">
                @foreach ($roleWords as $role => [$en, $ms])
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;color:var(--ink);">
                        <input type="checkbox" name="attendee_roles[]" value="{{ $role }}" @checked(in_array($role, $s->roles(), true)) />
                        <span x-text="$store.ui.lang==='en' ? @js($en) : @js($ms)">{{ $en }}</span>
                    </label>
                @endforeach
            </div>
            <p style="font-size:11.5px;color:var(--muted);margin:6px 0 0;" x-text="$store.ui.lang==='en'
                ? 'Plus every active PM or PE on a live project, whatever their role.'
                : 'Ditambah setiap PM atau PE aktif pada projek yang masih berjalan, tanpa mengira peranan mereka.'">
            </p>
        </div>

        <div style="flex:1 1 100%;">
            <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 20px;" x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</button>
        </div>
    </form>
</div>
@endsection
