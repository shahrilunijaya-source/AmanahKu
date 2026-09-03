@extends('layouts.app')

@section('screen')
@include('partials.guide', [
    'key' => 'leave-setup',
    'en'  => [
        'title' => 'Leave setup',
        'body'  => 'Set each person\'s opening leave balance, per leave type. Use this to carry forward balances from your previous system when you first go live — type the number of days each person has left, then save. These are the live balances: monthly accrual adds to them, and approved leave is deducted from them.',
        'who'   => 'HR & Management',
        'steps' => [
            'Open the Balances tab and find the person. Each column is one leave type (annual, sick, and so on).',
            'Tick the types that apply to them — untick one and it stops being offered to them, so an intern gets no annual leave.',
            'Type the days they are carrying forward into the matching cell. Leave a cell blank to keep its current balance.',
            'Click "Save balances" at the bottom. The numbers show immediately on each profile and dashboard.',
        ],
    ],
    'ms'  => [
        'title' => 'Tetapan cuti',
        'body'  => 'Tetapkan baki cuti permulaan setiap orang, mengikut jenis cuti. Gunakan ini untuk membawa ke hadapan baki daripada sistem terdahulu semasa mula guna — taip bilangan hari yang tinggal bagi setiap orang, kemudian simpan. Ini adalah baki langsung: terakru bulanan ditambah kepadanya, dan cuti diluluskan ditolak daripadanya.',
        'who'   => 'HR & Pengurusan',
        'steps' => [
            'Buka tab Baki dan cari orang itu. Setiap lajur ialah satu jenis cuti (tahunan, sakit, dan sebagainya).',
            'Tandakan jenis yang layak untuknya — buang tanda dan jenis itu tidak lagi ditawarkan, jadi pelatih tiada cuti tahunan.',
            'Taip hari yang dibawa ke hadapan dalam sel yang berkaitan. Biarkan sel kosong untuk kekalkan baki semasanya.',
            'Klik "Simpan baki" di bawah. Nombor akan dipapar serta-merta pada setiap profil dan papan pemuka.',
        ],
    ],
])

@php
    // One screen, four jobs: the type list, the holiday calendar, the per-person balance
    // grid, and granting replacement quota. They used to stack into one very long page.
    // The tab lives in ?tab= so a redirect after a save comes back to the same one.
    $setupTabs = ['types', 'holidays', 'balances', 'replacement'];
    $initialTab = in_array(request()->query('tab'), $setupTabs, true) ? request()->query('tab') : 'types';
@endphp

<div x-data="{
        tab: @js($initialTab),
        go(t) {
            this.tab = t;
            // Keep the URL honest without navigating, so a save (which redirects back) and
            // a shared link both land on the tab you were on.
            const u = new URL(window.location);
            t === 'types' ? u.searchParams.delete('tab') : u.searchParams.set('tab', t);
            history.replaceState(null, '', u);
        },
    }">

    <div class="uj-lv-tabs" role="tablist" style="margin-bottom:16px;">
        <button type="button" class="uj-lv-tab" role="tab" :data-on="tab === 'types' ? '' : null"
                :aria-selected="tab === 'types'" @click="go('types')">
            <span x-text="$store.ui.lang==='en' ? 'Leave types' : 'Jenis cuti'">Leave types</span>
        </button>
        <button type="button" class="uj-lv-tab" role="tab" :data-on="tab === 'holidays' ? '' : null"
                :aria-selected="tab === 'holidays'" @click="go('holidays')">
            <span x-text="$store.ui.lang==='en' ? 'Public holidays' : 'Cuti umum'">Public holidays</span>
        </button>
        <button type="button" class="uj-lv-tab" role="tab" :data-on="tab === 'balances' ? '' : null"
                :aria-selected="tab === 'balances'" @click="go('balances')">
            <span x-text="$store.ui.lang==='en' ? 'Balances' : 'Baki'">Balances</span>
        </button>
        <button type="button" class="uj-lv-tab" role="tab" :data-on="tab === 'replacement' ? '' : null"
                :aria-selected="tab === 'replacement'" @click="go('replacement')">
            <span x-text="$store.ui.lang==='en' ? 'Replacement' : 'Cuti ganti'">Replacement</span>
        </button>
    </div>

    <div role="tabpanel" x-show="tab === 'types'" x-cloak>
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
    </div>

    <div role="tabpanel" x-show="tab === 'holidays'" x-cloak>
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
    </div>

    <div role="tabpanel" x-show="tab === 'balances'" x-cloak>
    @php $cellStyle = 'width:74px;height:34px;padding:0 8px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;text-align:center;font-family:var(--font-mono);background:#fff;color:var(--ink);outline:none;'; @endphp

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
              x-data="{
                  q: '',
                  rows: @js($setupStaff->map(fn ($e) => mb_strtolower(trim($e->display_name.' '.$e->name.' '.$e->position)))->values()),
                  hit(h) { return this.q.trim() === '' || h.includes(this.q.trim().toLowerCase()); },
                  get shown() { return this.rows.filter(h => this.hit(h)).length; },
                  fillCol(id, val) { if (val === '' || val === null) return; document.querySelectorAll('input[data-lt=\'' + id + '\']').forEach(i => { if (! i.disabled && i.closest('tr').style.display !== 'none') { i.value = val; } }); } }">
            @csrf
            <div style="display:flex;align-items:center;gap:9px;margin:0 0 6px;flex-wrap:wrap;">
                <h2 style="font-size:14px;font-weight:600;color:var(--ink);margin:0;"><span x-text="$store.ui.lang==='en' ? 'Opening balances' : 'Baki permulaan'">Opening balances</span></h2>
                <span style="font-size:11px;font-weight:600;color:var(--muted);background:var(--canvas);border:1px solid var(--hairline);padding:2px 9px;border-radius:9999px;"><span x-text="shown">{{ $setupStaff->count() }}</span> <span x-text="$store.ui.lang==='en' ? 'staff' : 'staf'">staff</span></span>
                <div style="margin-left:auto;position:relative;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2" stroke-linecap="round" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);pointer-events:none;"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                    <input type="search" x-model="q" @keydown.escape="q = ''"
                           :placeholder="$store.ui.lang==='en' ? 'Search name or nickname' : 'Cari nama atau gelaran'"
                           style="width:230px;height:32px;padding:0 12px 0 30px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;outline:none;background:var(--surface,#fff);color:var(--ink);" />
                </div>
            </div>
            <p style="font-size:12px;color:var(--muted);margin:0 0 11px;"><span x-text="$store.ui.lang==='en' ? 'Tick the leave types a person is entitled to, and type the days they carry forward. Untick a type and it stops being offered to them — an intern gets no annual leave. Tip: type a number in a column header and click Set to fill that type for everyone.' : 'Tandakan jenis cuti yang layak untuk seseorang, dan taip hari yang dibawa ke hadapan. Buang tanda dan jenis itu tidak lagi ditawarkan kepadanya — pelatih tiada cuti tahunan. Petua: taip nombor pada tajuk lajur dan klik Set untuk isi bagi semua orang.'"></span></p>

            <div class="uj-card" style="padding:0;overflow-x:auto;">
                <table style="border-collapse:collapse;width:100%;min-width:{{ 240 + $leaveTypes->count() * 96 }}px;font-size:13px;">
                    <thead>
                        <tr style="border-bottom:1px solid var(--hairline);">
                            <th style="position:sticky;left:0;z-index:1;background:var(--surface,#fff);text-align:left;padding:11px 16px;font-size:11.5px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;"><span x-text="$store.ui.lang==='en' ? 'Staff' : 'Staf'">Staff</span></th>
                            @foreach ($leaveTypes as $type)
                                <th style="text-align:center;padding:11px 14px;font-size:12px;font-weight:600;color:var(--ink);white-space:nowrap;vertical-align:top;">
                                    {{ $type->name }}
                                    @if ($type->is_hr_granted_only)
                                        {{-- Quota is handed out one grant at a time on the Replacement tab, each with
                                             the remark saying what earned it, so it is not an opening balance to type
                                             into a grid. Shown here read-only. --}}
                                        <div style="margin-top:7px;font-size:11px;font-weight:400;color:var(--muted);white-space:nowrap;"
                                             x-text="$store.ui.lang==='en' ? 'granted, see Replacement' : 'diberi, lihat Cuti ganti'">granted, see Replacement</div>
                                    @elseif ($type->is_unpaid)
                                        {{-- Not an entitlement — salary not paid for a day not worked. No quota
                                             to open, and open to everyone, so there is nothing to tick. --}}
                                        <div style="margin-top:7px;font-size:11px;font-weight:400;color:var(--muted);white-space:nowrap;"
                                             x-text="$store.ui.lang==='en' ? 'no quota, open to all' : 'tiada kuota, untuk semua'">no quota, open to all</div>
                                    @elseif ($type->deducts_from_leave_type_id)
                                        {{-- Spends another type's balance, so there is nothing to open here. --}}
                                        <div style="margin-top:7px;font-size:11px;font-weight:400;color:var(--muted);white-space:nowrap;"
                                             x-text="$store.ui.lang==='en' ? 'off {{ $leaveTypes->firstWhere('id', $type->deducts_from_leave_type_id)?->name ?? 'Annual' }}' : 'dari {{ $leaveTypes->firstWhere('id', $type->deducts_from_leave_type_id)?->name ?? 'Annual' }}'">off {{ $leaveTypes->firstWhere('id', $type->deducts_from_leave_type_id)?->name ?? 'Annual' }}</div>
                                    @else
                                    <div x-data="{ v: '' }" style="display:flex;gap:4px;justify-content:center;margin-top:7px;font-weight:400;">
                                        <input type="number" step="0.5" min="0" max="9999" x-model="v" placeholder="all"
                                               @keydown.enter.prevent="fillCol({{ $type->id }}, v)"
                                               style="width:52px;height:28px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;text-align:right;font-family:var(--font-mono);outline:none;" />
                                        <button type="button" @click="fillCol({{ $type->id }}, v)" class="uj-btn-ghost" style="height:28px;padding:0 9px;font-size:11px;"><span x-text="$store.ui.lang==='en' ? 'Set' : 'Isi'">Set</span></button>
                                    </div>
                                    @endif
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($setupStaff as $e)
                            @php $row = $balanceMatrix->get($e->id); @endphp
                            <tr x-show="hit(rows[{{ $loop->index }}])" style="border-bottom:1px solid var(--hairline-soft);">
                                <td style="position:sticky;left:0;z-index:1;background:var(--surface,#fff);padding:9px 16px;">
                                    <div style="font-weight:600;color:var(--ink);white-space:nowrap;">{{ $e->display_name }}</div>
                                    <div style="font-size:11px;color:var(--muted);white-space:nowrap;">{{ $e->position ?? '—' }}@if ($e->display_name !== $e->name) · {{ $e->name }}@endif</div>
                                </td>
                                @foreach ($leaveTypes as $type)
                                    @php $cell = $row?->get($type->id); @endphp
                                    <td style="padding:7px 14px;text-align:center;">
                                        @if ($type->is_hr_granted_only)
                                            {{-- Read-only: the balance is the sum of the grants, not something typed here. --}}
                                            <span style="font-size:12px;color:var(--muted);font-family:var(--font-mono);">{{ $cell === null ? '—' : ($cell == (int) $cell ? (int) $cell : $cell) }}</span>
                                        @elseif ($type->is_unpaid)
                                            <span style="font-size:12px;color:var(--muted);font-family:var(--font-mono);">—</span>
                                        @elseif ($type->deducts_from_leave_type_id)
                                            @php $src = $row?->get($type->deducts_from_leave_type_id); @endphp
                                            <span style="font-size:12px;color:var(--muted);font-family:var(--font-mono);">{{ $src === null ? '—' : ($src == (int) $src ? (int) $src : $src) }}</span>
                                        @else
                                            {{-- The tick IS the eligibility: a person is offered a leave type
                                                 exactly when they have a balance row for it, so unticking here
                                                 deletes the row on save. The hidden 0 rides in front of the box
                                                 so an unticked cell posts something — an unchecked checkbox
                                                 sends nothing at all, which the server cannot tell apart from
                                                 a field that was never on the form. --}}
                                            <div x-data="{ on: @js($cell !== null) }" style="display:flex;align-items:center;gap:8px;justify-content:center;">
                                                <input type="hidden" name="applies[{{ $e->id }}][{{ $type->id }}]" value="0" />
                                                <input type="checkbox" x-model="on" value="1"
                                                       name="applies[{{ $e->id }}][{{ $type->id }}]"
                                                       :aria-label="$store.ui.lang==='en' ? '{{ $type->name }} applies to {{ $e->display_name }}' : '{{ $type->name }} terpakai untuk {{ $e->display_name }}'"
                                                       style="width:15px;height:15px;accent-color:var(--red);cursor:pointer;flex-shrink:0;" />
                                                <input type="number" step="0.5" min="0" max="9999"
                                                       name="balances[{{ $e->id }}][{{ $type->id }}]"
                                                       data-lt="{{ $type->id }}"
                                                       value="{{ $cell === null ? '' : ($cell == (int) $cell ? (int) $cell : $cell) }}"
                                                       :disabled="! on" :style="on ? '' : 'opacity:.4;'"
                                                       placeholder="—" style="{{ $cellStyle }}" />
                                            </div>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div x-show="shown === 0" x-cloak style="padding:22px;text-align:center;color:var(--muted);font-size:13px;">
                    <span x-text="$store.ui.lang==='en' ? 'Nobody matches that name.' : 'Tiada nama yang sepadan.'">Nobody matches that name.</span>
                </div>
            </div>

            <div style="display:flex;justify-content:flex-end;margin-top:16px;">
                <button type="submit" class="uj-btn-primary" style="height:40px;padding:0 22px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Save balances' : 'Simpan baki'">Save balances</span></button>
            </div>
        </form>
    @endif
    </div>

    <div role="tabpanel" x-show="tab === 'replacement'" x-cloak>
    {{-- Grant replacement quota — a type flagged "HR grants only" (Replacement) carries no
         yearly entitlement, so its quota is whatever HR hands out here. The days land on the
         employee's balance and they apply for them themselves through the normal chain
         (LeaveController::grant). Each grant keeps its remark so the days can be traced back
         to the rest day that earned them. --}}
    @php
        $grantedTypes = $leaveTypes->where('is_hr_granted_only', true);
        $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.');
    @endphp
    @if ($grantedTypes->isEmpty() || $setupStaff->isEmpty())
        <div class="uj-card" style="padding:22px;text-align:center;color:var(--muted);font-size:13px;">
            <span x-text="$store.ui.lang==='en' ? 'Nothing to grant here yet. Mark a leave type “HR grants only” on the Leave types tab (Replacement is the usual one) and add active staff.' : 'Tiada kuota untuk diberi lagi. Tandakan jenis cuti sebagai “HR beri sahaja” pada tab Jenis cuti (biasanya Cuti ganti) dan tambah staf aktif.'">Nothing to grant here yet.</span>
        </div>
    @else
        <div style="display:flex;align-items:center;gap:9px;margin:0 0 6px;">
            <h2 style="font-size:14px;font-weight:600;color:var(--ink);margin:0;"><span x-text="$store.ui.lang==='en' ? 'Grant replacement quota' : 'Beri kuota cuti ganti'">Grant replacement quota</span></h2>
        </div>
        <p style="font-size:12px;color:var(--muted);margin:0 0 11px;"><span x-text="$store.ui.lang==='en' ? 'Give the days someone earned by working a rest day. They then apply for those days themselves, whenever they want, and cannot apply for more than they hold. Enter a negative number to correct a mistake.' : 'Beri hari yang diperoleh kerana bekerja pada hari rehat. Mereka memohon hari itu sendiri, bila-bila masa, dan tidak boleh memohon lebih daripada baki. Masukkan nombor negatif untuk membetulkan kesilapan.'"></span></p>

        <div class="uj-card" style="padding:18px 22px;">
            <form method="post" action="{{ route('leave.grant') }}" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                @csrf
                <div style="flex:2;min-width:200px;">
                    <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Staff *' : 'Staf *'">Staff *</span></label>
                    <select name="employee_id" required style="width:100%;height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;background:#fff;">
                        @foreach ($setupStaff as $e)
                            <option value="{{ $e->id }}" @selected(old('employee_id') == $e->id)>{{ $e->display_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div style="width:170px;">
                    <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Leave type *' : 'Jenis cuti *'">Leave type *</span></label>
                    <select name="leave_type_id" required style="width:100%;height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;background:#fff;">
                        @foreach ($grantedTypes as $t)
                            <option value="{{ $t->id }}" @selected(old('leave_type_id') == $t->id)>{{ $t->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div style="width:130px;">
                    <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Days *' : 'Hari *'">Days *</span></label>
                    {{-- Half days are real here: a half rest day worked earns 0.5. --}}
                    <input type="number" name="days" step="0.5" required value="{{ old('days', '1') }}" placeholder="1.5" style="width:100%;height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
                </div>
                <div style="flex:2;min-width:220px;">
                    <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'What is this quota for? *' : 'Kuota ini untuk apa? *'">What is this quota for? *</span></label>
                    <input name="remark" maxlength="255" required value="{{ old('remark') }}" placeholder="Worked Saturday 31 Aug" style="width:100%;height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
                </div>
                <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 16px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Grant' : 'Beri'">Grant</span></button>
            </form>
        </div>

        @if (($leaveGrants ?? collect())->isNotEmpty())
            <h2 style="font-size:14px;font-weight:600;color:var(--ink);margin:22px 0 6px;"><span x-text="$store.ui.lang==='en' ? 'Recent grants' : 'Kuota diberi terkini'">Recent grants</span></h2>
            <div class="uj-card" style="padding:0;overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead>
                        <tr style="text-align:left;color:var(--muted);font-size:12px;">
                            <th style="padding:10px 16px;font-weight:500;"><span x-text="$store.ui.lang==='en' ? 'Staff' : 'Staf'">Staff</span></th>
                            <th style="padding:10px 16px;font-weight:500;"><span x-text="$store.ui.lang==='en' ? 'Days' : 'Hari'">Days</span></th>
                            <th style="padding:10px 16px;font-weight:500;"><span x-text="$store.ui.lang==='en' ? 'For' : 'Untuk'">For</span></th>
                            <th style="padding:10px 16px;font-weight:500;"><span x-text="$store.ui.lang==='en' ? 'Granted' : 'Diberi'">Granted</span></th>
                            <th style="padding:10px 16px;"><span class="uj-sr-only" x-text="$store.ui.lang==='en' ? 'Edit' : 'Ubah'">Edit</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($leaveGrants as $g)
                            {{-- A grant can be corrected in place: days and remark only. The balance
                                 moves by the difference (LeaveController::updateGrant). The staff
                                 member and the leave type stay put — those would be a new grant. --}}
                            <tr style="border-top:1px solid var(--hairline);" x-data="{ edit: false }">
                                <td style="padding:10px 16px;">{{ $g->employee?->display_name }}</td>
                                <td style="padding:10px 16px;font-variant-numeric:tabular-nums;color:{{ $g->days < 0 ? 'var(--danger, #c0392b)' : 'var(--ink)' }};">
                                    <span x-show="! edit">{{ $g->days > 0 ? '+' : '' }}{{ $num($g->days) }}</span>
                                    <input type="number" name="days" step="0.5" required form="grant-edit-{{ $g->id }}" value="{{ $num($g->days) }}"
                                           x-show="edit" x-cloak
                                           style="width:80px;height:32px;padding:0 8px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;outline:none;" />
                                </td>
                                <td style="padding:10px 16px;color:var(--muted);">
                                    <span x-show="! edit">{{ $g->remark }}</span>
                                    <input name="remark" maxlength="255" required form="grant-edit-{{ $g->id }}" value="{{ $g->remark }}"
                                           x-show="edit" x-cloak
                                           style="width:100%;min-width:180px;height:32px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;outline:none;" />
                                </td>
                                <td style="padding:10px 16px;color:var(--muted);white-space:nowrap;">{{ $g->created_at?->format('j M Y') }}{{ $g->grantedBy ? ' · '.$g->grantedBy->display_name : '' }}</td>
                                <td style="padding:10px 16px;text-align:right;white-space:nowrap;">
                                    <button type="button" x-show="! edit" @click="edit = true" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12px;">
                                        <span x-text="$store.ui.lang==='en' ? 'Edit' : 'Ubah'">Edit</span>
                                    </button>
                                    <button type="submit" form="grant-edit-{{ $g->id }}" x-show="edit" x-cloak class="uj-btn-primary" style="height:30px;padding:0 12px;font-size:12px;">
                                        <span x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</span>
                                    </button>
                                    <button type="button" x-show="edit" x-cloak @click="edit = false" class="uj-btn-ghost" style="height:30px;padding:0 10px;font-size:12px;">
                                        <span x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</span>
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                {{-- The edit forms live outside the table: a <form> cannot wrap a <tr>, so the
                     cells' inputs point at these by id instead. --}}
                @foreach ($leaveGrants as $g)
                    <form id="grant-edit-{{ $g->id }}" method="post" action="{{ route('leave.grant.update', $g->id) }}" hidden>
                        @csrf
                        @method('PATCH')
                    </form>
                @endforeach
            </div>
        @endif
    @endif
    </div>
</div>

@endsection
