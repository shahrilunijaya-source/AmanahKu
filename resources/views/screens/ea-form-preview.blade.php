<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Form EA — {{ $employee->name }} · {{ $data['year'] }} · Amanahku</title>
    {{ Vite::fonts() }}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.pwa-head')
</head>
<body style="background:var(--canvas);padding:28px;">

<a href="{{ route('app.screen', 'payroll') }}" style="display:inline-flex;align-items:center;gap:6px;font-size:13px;color:var(--muted);text-decoration:none;margin-bottom:16px;">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
    Back to payroll
</a>

<div class="uj-card" style="padding:22px 26px;max-width:720px;margin:0 auto;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:6px;">
        <div>
            <div style="font-size:17px;font-weight:600;color:var(--ink);">Form EA — {{ $employee->name }}</div>
            <div style="font-size:12.5px;color:var(--muted);">Year {{ $data['year'] }} · this employer's figures only</div>
        </div>
        <a href="{{ route('payroll.ea-form.pdf', ['employee' => $employee, 'year' => $data['year']]) }}" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:12.5px;display:inline-flex;align-items:center;text-decoration:none;">Download PDF</a>
    </div>

    @if ($data['previous_employment'])
        <div style="background:#fff9ec;border:1px solid #d9a441;border-radius:8px;padding:10px 14px;margin:14px 0;font-size:12.5px;">
            This employee has previous-employer figures on file for {{ $data['year'] }} (from
            {{ $data['previous_employment']['previous_employer'] ?? 'a previous employer' }}). Form EA reports
            <strong>only what this employer paid</strong> — the employee will need a <strong>second EA</strong>
            from that previous employer to cover the rest of the year. This note is for HR only and never
            appears on the printed form.
        </div>
    @endif

    <div style="font-size:11px;font-weight:700;letter-spacing:0.6px;text-transform:uppercase;color:var(--muted);margin:18px 0 8px;">
        Boxes that could not be filled — write these in by hand before issuing
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
        <tr style="text-align:left;color:var(--muted);font-size:11px;text-transform:uppercase;">
            <th style="padding:4px 8px 4px 0;">Box</th><th style="padding:4px 0;">What it is</th>
        </tr>
        @foreach ($data['incomplete'] as $box)
            <tr style="border-top:1px solid var(--hairline-soft);">
                <td style="padding:6px 8px 6px 0;font-family:monospace;">{{ $box['box'] }}</td>
                <td style="padding:6px 0;">{{ $box['label'] }}</td>
            </tr>
        @endforeach
    </table>
</div>

</body>
</html>
