@extends('layouts.app')

@section('screen')
@include('partials.guide', [
    'key' => 'oversight',
    'en'  => [
        'title' => 'Oversight',
        'body'  => 'The company-wide reports open to anyone who manages staff: workforce, attendance, leave, timesheet cost, audit logs and profile test results. Pick a report below.',
    ],
    'ms'  => [
        'title' => 'Pengawasan',
        'body'  => 'Laporan seluruh syarikat yang terbuka kepada sesiapa yang menguruskan staf: tenaga kerja, kehadiran, cuti, kos lembaran masa, log audit dan keputusan ujian profil. Pilih laporan di bawah.',
    ],
])

@php
    $cards = [
        ['screen' => 'reports', 'en' => ['title' => 'Workforce Reports', 'sub' => 'Headcount, department capacity and workload split.'], 'ms' => ['title' => 'Laporan Tenaga Kerja', 'sub' => 'Bilangan staf, kapasiti jabatan dan taburan beban kerja.']],
        ['screen' => 'attendance-report', 'en' => ['title' => 'Attendance Reports', 'sub' => 'Every active employee, clocked in or not.'], 'ms' => ['title' => 'Laporan Kehadiran', 'sub' => 'Setiap pekerja aktif, clock in atau tidak.']],
        ['screen' => 'leave-report', 'en' => ['title' => 'Leave Reports', 'sub' => 'Leave taken by type and by person.'], 'ms' => ['title' => 'Laporan Cuti', 'sub' => 'Cuti diambil mengikut jenis dan individu.']],
        ['screen' => 'timesheet-reports', 'roles' => ['management', 'hr'], 'en' => ['title' => 'Timesheet Reports', 'sub' => 'Hours and cost by project and by person.'], 'ms' => ['title' => 'Laporan Lembaran Masa', 'sub' => 'Jam dan kos mengikut projek dan individu.']],
        ['screen' => 'audit', 'en' => ['title' => 'Audit Logs', 'sub' => 'Recent administrative and approval activity.'], 'ms' => ['title' => 'Log Audit', 'sub' => 'Aktiviti pentadbiran dan kelulusan terkini.']],
        ['screen' => 'profile-test-results', 'en' => ['title' => 'Profile Test Results', 'sub' => 'Everyone\'s answers — managers see their own staff.'], 'ms' => ['title' => 'Keputusan Ujian Profil', 'sub' => 'Jawapan semua orang — pengurus melihat staf sendiri.']],
    ];
@endphp

<div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(240px, 1fr));gap:14px;">
    @foreach ($cards as $c)
        @continue(isset($c['roles']) && ! in_array($role, $c['roles'], true))
        <a href="{{ route('app.screen', $c['screen']) }}" class="uj-card uj-card-clickable" style="display:block;padding:18px;text-decoration:none;">
            <h3 class="uj-card-title" style="margin-bottom:6px;" x-text="$store.ui.lang==='en' ? @js($c['en']['title']) : @js($c['ms']['title'])">{{ $c['en']['title'] }}</h3>
            <p style="font-size:12.5px;color:var(--muted);margin:0;" x-text="$store.ui.lang==='en' ? @js($c['en']['sub']) : @js($c['ms']['sub'])">{{ $c['en']['sub'] }}</p>
        </a>
    @endforeach
</div>
@endsection
