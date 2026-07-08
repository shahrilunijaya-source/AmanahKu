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
    <form method="post" action="{{ route('leave.setup.save') }}">
        @csrf
        <div style="display:flex;align-items:center;gap:9px;margin:0 0 11px;">
            <h2 style="font-size:14px;font-weight:600;color:var(--ink);margin:0;"><span x-text="$store.ui.lang==='en' ? 'Opening balances' : 'Baki permulaan'">Opening balances</span></h2>
            <span style="font-size:11px;font-weight:600;color:var(--muted);background:var(--canvas);border:1px solid var(--hairline);padding:2px 9px;border-radius:9999px;">{{ $setupStaff->count() }} <span x-text="$store.ui.lang==='en' ? 'staff' : 'staf'">staff</span></span>
        </div>

        <div class="uj-card" style="padding:0;overflow-x:auto;">
            <table style="border-collapse:collapse;width:100%;min-width:{{ 240 + $leaveTypes->count() * 96 }}px;font-size:13px;">
                <thead>
                    <tr style="border-bottom:1px solid var(--hairline);">
                        <th style="position:sticky;left:0;z-index:1;background:var(--surface,#fff);text-align:left;padding:11px 16px;font-size:11.5px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;"><span x-text="$store.ui.lang==='en' ? 'Staff' : 'Staf'">Staff</span></th>
                        @foreach ($leaveTypes as $type)
                            <th style="text-align:right;padding:11px 14px;font-size:12px;font-weight:600;color:var(--ink);white-space:nowrap;">{{ $type->name }}</th>
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
