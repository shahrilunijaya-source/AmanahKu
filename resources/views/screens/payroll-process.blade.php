@extends('layouts.app')

@php
    $tabs = [
        'monthly' => ['Monthly', 'Bulanan'],
        'bonus' => ['Bonus', 'Bonus'],
        'control' => ['Payroll Control', 'Kawalan Gaji'],
    ];
    $tab = array_key_exists((string) request('tab'), $tabs) ? (string) request('tab') : 'monthly';
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'payroll-process',
    'en' => [
        'title' => 'Payroll',
        'body' => 'Run monthly payroll for the whole company. Enter transactions, create a draft run here, check each payslip on Payroll Review, then approve and finalize on Payment to issue payslips and produce the bank file and statutory reports. Finalizing locks the run, so get the numbers right first.',
        'who' => 'HR & management only',
        'steps' => [
            'Make sure every active employee has a salary on their profile.',
            'If anyone joined partway through the year, set their opening figures first on Transaction, Payroll Figures Take On. Otherwise their PCB for the rest of the year will be wrong.',
            'On Process, Monthly, pick the pay month and generate the draft run. A draft payslip is created per employee and PCB is computed automatically.',
            'On Payroll Review, open each payslip to enter overtime, bonus or unpaid days. PCB can be overridden by hand; the override sticks until cleared.',
            'When every figure is verified, finalize on Payment, Payout Management. This locks payslips, notifies staff and marks claims paid. It cannot be undone.',
        ],
    ],
    'ms' => [
        'title' => 'Gaji',
        'body' => 'Jalankan gaji bulanan untuk seluruh syarikat. Masukkan transaksi, buat run draf di sini, semak setiap slip gaji di Semakan Gaji, kemudian lulus dan muktamadkan di Pembayaran untuk keluarkan slip gaji serta hasilkan fail bank dan laporan berkanun. Muktamadkan akan kunci run itu, jadi pastikan angka betul dahulu.',
        'who' => 'HR & pengurusan sahaja',
        'steps' => [
            'Pastikan setiap pekerja aktif ada gaji pada profil mereka.',
            'Jika ada pekerja yang menyertai di tengah tahun, tetapkan angka pembukaan dahulu di Transaksi, Angka Pembukaan Gaji. Jika tidak, PCB mereka untuk baki tahun akan salah.',
            'Di Proses, Bulanan, pilih bulan gaji dan jana run draf. Satu slip gaji draf dibuat bagi setiap pekerja dan PCB dikira automatik.',
            'Di Semakan Gaji, buka setiap slip gaji untuk masukkan kerja lebih masa, bonus atau hari tanpa gaji. PCB boleh ditindih secara manual; tindihan kekal sehingga dikosongkan.',
            'Apabila setiap angka disahkan, muktamadkan di Pembayaran, Pengurusan Bayaran. Ini kunci slip gaji, maklumkan staf dan tanda tuntutan sebagai dibayar. Ia tidak boleh dibatalkan.',
        ],
    ],
])
<div x-data="{ tab: @js($tab) }" x-init="$watch('tab', t => { const u = new URL(location.href); u.searchParams.set('tab', t); history.replaceState(null, '', u); })">
    @php
        $money = fn ($v) => 'RM '.number_format((float) $v, 2);
        $latest = $activeRun?->totals ?? [];
    @endphp
    {{-- Stat row --}}
    <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:18px;">
        <div class="uj-card uj-stat" style="flex:1;min-width:170px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Latest run' : 'Run terkini'">Latest run</div><div class="uj-stat-value" style="font-size:18px;">{{ $activeRun?->label ?? '—' }}</div></div>
        <div class="uj-card uj-stat" style="flex:1;min-width:170px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Net payout' : 'Bayaran bersih'">Net payout</div><div class="uj-stat-value" style="color:var(--success);">{{ $money($latest['net'] ?? 0) }}</div></div>
        <div class="uj-card uj-stat" style="flex:1;min-width:170px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Employer cost' : 'Kos majikan'">Employer cost</div><div class="uj-stat-value">{{ $money($latest['employer_cost'] ?? 0) }}</div></div>
        <div class="uj-card uj-stat" style="flex:1;min-width:170px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Headcount' : 'Bilangan staf'">Headcount</div><div class="uj-stat-value">{{ $latest['headcount'] ?? 0 }}</div></div>
    </div>

    @include('partials.payroll.tabs', ['tabs' => $tabs])

    <div x-show="tab === 'monthly'" x-cloak>
        @include('partials.payroll.process.monthly')
    </div>
    <div x-show="tab === 'bonus'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'Bonus', 'body' => 'A separate bonus run with its own PCB treatment.', 'bodyMs' => 'Run bonus berasingan dengan layanan PCB tersendiri.', 'pill' => 'Spec F10'])
    </div>
    <div x-show="tab === 'control'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'Payroll Control', 'body' => 'Lock or unlock a pay month with a remark and attachment, and see who changed what.', 'bodyMs' => 'Kunci atau buka bulan gaji dengan catatan dan lampiran, dan lihat siapa mengubah apa.', 'pill' => 'Follow-up'])
    </div>
</div>
@endsection
