@extends('layouts.app')

@section('screen')
@include('partials.guide', [
    'key' => 'leave-setup',
    'en'  => [
        'title' => 'Leave setup',
        'body'  => 'Set each person\'s opening leave balance, per leave type. Use this to carry forward balances from your previous system when you first go live — type the number of days each person has left, then save. These are the live balances: monthly accrual adds to them, and approved leave is deducted from them.',
        'who'   => 'HR & Management',
        'steps' => [
            'Find the person in the table. Each column is one leave type (annual, sick, and so on).',
            'Type the days they are carrying forward into the matching cell. Leave a cell blank to keep its current balance.',
            'Click "Save balances" at the bottom. The numbers show immediately on each profile and dashboard.',
        ],
    ],
    'ms'  => [
        'title' => 'Tetapan cuti',
        'body'  => 'Tetapkan baki cuti permulaan setiap orang, mengikut jenis cuti. Gunakan ini untuk membawa ke hadapan baki daripada sistem terdahulu semasa mula guna — taip bilangan hari yang tinggal bagi setiap orang, kemudian simpan. Ini adalah baki langsung: terakru bulanan ditambah kepadanya, dan cuti diluluskan ditolak daripadanya.',
        'who'   => 'HR & Pengurusan',
        'steps' => [
            'Cari orang itu dalam jadual. Setiap lajur ialah satu jenis cuti (tahunan, sakit, dan sebagainya).',
            'Taip hari yang dibawa ke hadapan dalam sel yang berkaitan. Biarkan sel kosong untuk kekalkan baki semasanya.',
            'Klik "Simpan baki" di bawah. Nombor akan dipapar serta-merta pada setiap profil dan papan pemuka.',
        ],
    ],
])

{{-- ============================ LEAVE TYPES ============================
     The master list every opening balance + request is set against. Managed here
     because Leave Setup is where "no leave types yet" otherwise dead-ends. --}}
<div style="display:flex;align-items:center;gap:9px;margin:0 0 11px;">
    <h2 style="font-size:14px;font-weight:600;color:var(--ink);margin:0;"><span x-text="$store.ui.lang==='en' ? 'Leave types' : 'Jenis cuti'">Leave types</span></h2>
    <span style="font-size:11px;font-weight:600;color:var(--muted);background:var(--canvas);border:1px solid var(--hairline);padding:2px 9px;border-radius:9999px;">{{ $leaveTypes->count() }}</span>
</div>

@if ($leaveTypes->isEmpty())
    <div class="uj-card" style="padding:20px;margin-bottom:14px;">
        <p style="font-size:13px;color:var(--muted);margin:0 0 12px;"><span x-text="$store.ui.lang==='en' ? 'No leave types yet. Load the standard Malaysian set to start, then tweak the numbers — or add your own below.' : 'Tiada jenis cuti lagi. Muat set standard Malaysia untuk mula, kemudian laras nombornya — atau tambah sendiri di bawah.'">No leave types yet.</span></p>
        <form method="post" action="{{ route('leave.types.standard') }}">
            @csrf
            <button type="submit" class="uj-btn-primary" style="height:40px;padding:0 18px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Load standard Malaysian set' : 'Muat set standard Malaysia'">Load standard Malaysian set</span></button>
        </form>
    </div>
@endif

<div class="uj-card" style="padding:0;margin-bottom:14px;" x-data="{ open: false }">
    <button @click="open = ! open" type="button" style="width:100%;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 20px;background:none;cursor:pointer;border:0;">
        <span style="display:flex;align-items:center;gap:9px;font-size:13.5px;font-weight:600;color:var(--ink);">
            <span style="width:24px;height:24px;border-radius:7px;background:var(--red-tint);color:var(--red);display:flex;align-items:center;justify-content:center;font-size:16px;line-height:1;">+</span>
            <span x-text="$store.ui.lang==='en' ? 'Add leave type' : 'Tambah jenis cuti'">Add leave type</span>
        </span>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" :style="open ? 'transform:rotate(180deg);transition:.15s' : 'transition:.15s'"><path d="M6 9l6 6 6-6"/></svg>
    </button>
    <div x-show="open" x-cloak style="padding:18px 22px;border-top:1px solid var(--hairline);">
        @include('partials.leave-type-form', ['type' => null, 'allLeaveTypes' => $leaveTypes, 'action' => route('leave.types.store'), 'submitLabel' => 'Add leave type'])
    </div>
</div>

@foreach ($leaveTypes as $lt)
    @php $pill = 'font-size:10.5px;padding:2px 8px;'; @endphp
    <div class="uj-card" style="padding:14px 20px;margin-bottom:10px;" x-data="{ edit: false }">
        <div style="display:flex;gap:12px;align-items:center;">
            <div style="flex:1;min-width:0;">
                <div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $lt->name }}</div>
                <div style="display:flex;gap:6px;margin-top:5px;flex-wrap:wrap;">
                    <span class="uj-pill" style="background:var(--canvas);color:var(--muted);{{ $pill }}">{{ $lt->entitlement + 0 }} <span x-text="$store.ui.lang==='en' ? 'days/yr' : 'hari/thn'">days/yr</span></span>
                    @if ($lt->min_notice_days)<span class="uj-pill" style="background:var(--canvas);color:var(--muted);{{ $pill }}">{{ $lt->min_notice_days }}<span x-text="$store.ui.lang==='en' ? 'd notice' : 'h notis'">d notice</span></span>@endif
                    @if ($lt->requires_attachment)<span class="uj-pill" style="background:var(--canvas);color:var(--muted);{{ $pill }}"><span x-text="$store.ui.lang==='en' ? 'Attachment' : 'Lampiran'">Attachment</span></span>@endif
                    @if ($lt->is_unplanned)<span class="uj-pill" style="background:var(--canvas);color:var(--muted);{{ $pill }}"><span x-text="$store.ui.lang==='en' ? 'Unplanned' : 'Tidak dirancang'">Unplanned</span></span>@endif
                    @if ($lt->deducts_from_leave_type_id)<span class="uj-pill" style="background:var(--red-tint);color:var(--red);{{ $pill }}">⚠ <span x-text="$store.ui.lang==='en' ? 'Deducts from {{ $leaveTypes->firstWhere('id', $lt->deducts_from_leave_type_id)?->name ?? 'Annual' }}' : 'Ditolak dari {{ $leaveTypes->firstWhere('id', $lt->deducts_from_leave_type_id)?->name ?? 'Tahunan' }}'">Deducts</span></span>@endif
                </div>
            </div>
            <button @click="edit = ! edit" type="button" class="uj-btn-ghost" style="height:32px;font-size:12px;padding:0 13px;"><span x-text="edit ? ($store.ui.lang==='en' ? 'Close' : 'Tutup') : ($store.ui.lang==='en' ? 'Edit' : 'Sunting')">Edit</span></button>
            <form method="post" action="{{ route('leave.types.delete', $lt) }}" onsubmit="return confirm('Delete this leave type?')">
                @csrf
                <button type="submit" class="uj-btn-ghost" style="height:32px;font-size:12px;padding:0 13px;color:var(--error);"><span x-text="$store.ui.lang==='en' ? 'Delete' : 'Padam'">Delete</span></button>
            </form>
        </div>
        <div x-show="edit" x-cloak style="margin-top:14px;padding-top:14px;border-top:1px solid var(--hairline-soft);">
            @include('partials.leave-type-form', ['type' => $lt, 'allLeaveTypes' => $leaveTypes, 'action' => route('leave.types.update', $lt), 'submitLabel' => 'Save changes'])
        </div>
    </div>
@endforeach

{{-- ============================ PUBLIC HOLIDAYS ============================
     The holiday calendar leave + attendance work against. Same home as leave types. --}}
<div style="display:flex;align-items:center;gap:9px;margin:24px 0 11px;">
    <h2 style="font-size:14px;font-weight:600;color:var(--ink);margin:0;"><span x-text="$store.ui.lang==='en' ? 'Public holidays' : 'Cuti umum'">Public holidays</span></h2>
    <span style="font-size:11px;font-weight:600;color:var(--muted);background:var(--canvas);border:1px solid var(--hairline);padding:2px 9px;border-radius:9999px;">{{ $holidays->count() }}</span>
</div>

@if ($holidays->isEmpty())
    <div class="uj-card" style="padding:20px;margin-bottom:14px;">
        <p style="font-size:13px;color:var(--muted);margin:0 0 12px;"><span x-text="$store.ui.lang==='en' ? 'No public holidays yet. Load the 2026 Malaysian set — then verify the lunar-calendar dates (Raya, CNY, Deepavali…) against the official gazette.' : 'Tiada cuti umum lagi. Muat set Malaysia 2026 — kemudian sahkan tarikh kalendar lunar (Raya, Tahun Baru Cina, Deepavali…) dengan warta rasmi.'">No public holidays yet.</span></p>
        <form method="post" action="{{ route('holiday.standard') }}">
            @csrf
            <button type="submit" class="uj-btn-primary" style="height:40px;padding:0 18px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Load 2026 Malaysian holidays' : 'Muat cuti Malaysia 2026'">Load 2026 Malaysian holidays</span></button>
        </form>
    </div>
@endif

<div class="uj-card" style="padding:0;margin-bottom:14px;" x-data="{ open: false }">
    <button @click="open = ! open" type="button" style="width:100%;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 20px;background:none;cursor:pointer;border:0;">
        <span style="display:flex;align-items:center;gap:9px;font-size:13.5px;font-weight:600;color:var(--ink);">
            <span style="width:24px;height:24px;border-radius:7px;background:var(--red-tint);color:var(--red);display:flex;align-items:center;justify-content:center;font-size:16px;line-height:1;">+</span>
            <span x-text="$store.ui.lang==='en' ? 'Add public holiday' : 'Tambah cuti umum'">Add public holiday</span>
        </span>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" :style="open ? 'transform:rotate(180deg);transition:.15s' : 'transition:.15s'"><path d="M6 9l6 6 6-6"/></svg>
    </button>
    <div x-show="open" x-cloak style="padding:18px 22px;border-top:1px solid var(--hairline);">
        <form method="post" action="{{ route('holiday.store') }}" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
            @csrf
            <div style="flex:2;min-width:180px;">
                <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Name *' : 'Nama *'">Name *</span></label>
                <input name="name" required maxlength="120" placeholder="National Day" style="width:100%;height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
            </div>
            <div style="width:170px;">
                <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Date *' : 'Tarikh *'">Date *</span></label>
                <input type="date" name="date" required style="width:100%;height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
            </div>
            <div style="width:150px;">
                <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'State (optional)' : 'Negeri (pilihan)'">State (optional)</span></label>
                <input name="state" maxlength="80" placeholder="All" style="width:100%;height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
            </div>
            <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 16px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Add' : 'Tambah'">Add</span></button>
        </form>
    </div>
</div>

@if ($holidays->isNotEmpty())
    <div class="uj-card" style="padding:6px 0;margin-bottom:14px;">
        @foreach ($holidays as $h)
            <div style="display:flex;align-items:center;gap:12px;padding:8px 20px;{{ ! $loop->last ? 'border-bottom:1px solid var(--hairline-soft);' : '' }}">
                <span style="width:104px;flex-shrink:0;font-size:12.5px;font-family:var(--font-mono);color:var(--muted);">{{ $h->date->format('d M Y') }}</span>
                <span style="flex:1;min-width:0;font-size:13px;color:var(--ink);">{{ $h->name }}@if ($h->state)<span style="color:var(--muted);font-size:11.5px;"> · {{ $h->state }}</span>@endif</span>
                <form method="post" action="{{ route('holiday.delete', $h) }}" onsubmit="return confirm('Delete this holiday?')">
                    @csrf
                    <button type="submit" class="uj-btn-ghost" style="height:28px;font-size:11.5px;padding:0 10px;color:var(--error);"><span x-text="$store.ui.lang==='en' ? 'Delete' : 'Padam'">Delete</span></button>
                </form>
            </div>
        @endforeach
    </div>
@endif

<div style="height:1px;background:var(--hairline);margin:22px 0;"></div>

@php $cellStyle = 'width:74px;height:34px;padding:0 8px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;text-align:right;font-family:var(--font-mono);background:#fff;color:var(--ink);outline:none;'; @endphp

@if ($leaveTypes->isEmpty())
    <div class="uj-card" style="padding:22px;text-align:center;color:var(--muted);font-size:13px;">
        <span x-text="$store.ui.lang==='en' ? 'No leave types yet. Add leave types before setting opening balances.' : 'Tiada jenis cuti lagi. Tambah jenis cuti sebelum menetapkan baki permulaan.'">No leave types yet.</span>
    </div>
@elseif ($setupStaff->isEmpty())
    <div class="uj-card" style="padding:22px;text-align:center;color:var(--muted);font-size:13px;">
        <span x-text="$store.ui.lang==='en' ? 'No active staff to set balances for.' : 'Tiada staf aktif untuk menetapkan baki.'">No active staff.</span>
    </div>
@else
    {{-- fillCol uses a document query, NOT this.$root: it is called from the per-column
         header control which has its own x-data, so $root would resolve to that little
         scope (which holds no grid cells) instead of the table. One grid per page, so the
         [data-lt] selector is unambiguous. --}}
    <form method="post" action="{{ route('leave.setup.save') }}"
          x-data="{ fillCol(id, val) { if (val === '' || val === null) return; document.querySelectorAll('input[data-lt=\'' + id + '\']').forEach(i => { i.value = val; }); } }">
        @csrf
        <div style="display:flex;align-items:center;gap:9px;margin:0 0 6px;">
            <h2 style="font-size:14px;font-weight:600;color:var(--ink);margin:0;"><span x-text="$store.ui.lang==='en' ? 'Opening balances' : 'Baki permulaan'">Opening balances</span></h2>
            <span style="font-size:11px;font-weight:600;color:var(--muted);background:var(--canvas);border:1px solid var(--hairline);padding:2px 9px;border-radius:9999px;">{{ $setupStaff->count() }} <span x-text="$store.ui.lang==='en' ? 'staff' : 'staf'">staff</span></span>
        </div>
        <p style="font-size:12px;color:var(--muted);margin:0 0 11px;"><span x-text="$store.ui.lang==='en' ? 'Tip: type a number in a column header and click Set to fill that leave type for everyone, then Save balances.' : 'Petua: taip nombor pada tajuk lajur dan klik Set untuk isi jenis cuti itu bagi semua orang, kemudian Simpan baki.'"></span></p>

        <div class="uj-card" style="padding:0;overflow-x:auto;">
            <table style="border-collapse:collapse;width:100%;min-width:{{ 240 + $leaveTypes->count() * 96 }}px;font-size:13px;">
                <thead>
                    <tr style="border-bottom:1px solid var(--hairline);">
                        <th style="position:sticky;left:0;z-index:1;background:var(--surface,#fff);text-align:left;padding:11px 16px;font-size:11.5px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;"><span x-text="$store.ui.lang==='en' ? 'Staff' : 'Staf'">Staff</span></th>
                        @foreach ($leaveTypes as $type)
                            <th style="text-align:right;padding:11px 14px;font-size:12px;font-weight:600;color:var(--ink);white-space:nowrap;vertical-align:top;">
                                {{ $type->name }}
                                <div x-data="{ v: '' }" style="display:flex;gap:4px;justify-content:flex-end;margin-top:7px;font-weight:400;">
                                    <input type="number" step="0.5" min="0" max="9999" x-model="v" placeholder="all"
                                           @keydown.enter.prevent="fillCol({{ $type->id }}, v)"
                                           style="width:52px;height:28px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;text-align:right;font-family:var(--font-mono);outline:none;" />
                                    <button type="button" @click="fillCol({{ $type->id }}, v)" class="uj-btn-ghost" style="height:28px;padding:0 9px;font-size:11px;"><span x-text="$store.ui.lang==='en' ? 'Set' : 'Isi'">Set</span></button>
                                </div>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($setupStaff as $e)
                        @php $row = $balanceMatrix->get($e->id); @endphp
                        <tr style="border-bottom:1px solid var(--hairline-soft);">
                            <td style="position:sticky;left:0;z-index:1;background:var(--surface,#fff);padding:9px 16px;">
                                <div style="font-weight:600;color:var(--ink);white-space:nowrap;">{{ $e->name }}</div>
                                <div style="font-size:11px;color:var(--muted);white-space:nowrap;">{{ $e->position ?? '—' }}</div>
                            </td>
                            @foreach ($leaveTypes as $type)
                                @php $cell = $row?->get($type->id); @endphp
                                <td style="padding:7px 14px;text-align:right;">
                                    <input type="number" step="0.5" min="0" max="9999"
                                           name="balances[{{ $e->id }}][{{ $type->id }}]"
                                           data-lt="{{ $type->id }}"
                                           value="{{ $cell === null ? '' : ($cell == (int) $cell ? (int) $cell : $cell) }}"
                                           placeholder="—" style="{{ $cellStyle }}" />
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div style="display:flex;justify-content:flex-end;margin-top:16px;">
            <button type="submit" class="uj-btn-primary" style="height:40px;padding:0 22px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Save balances' : 'Simpan baki'">Save balances</span></button>
        </div>
    </form>
@endif
@endsection
