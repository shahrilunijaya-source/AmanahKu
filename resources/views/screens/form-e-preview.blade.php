<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Form E &amp; C.P.8D — {{ $year }} · Amanahku</title>
    {{ Vite::fonts() }}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.pwa-head')
</head>
<body style="background:var(--canvas);padding:28px;">

<a href="{{ route('app.screen', 'payroll') }}" style="display:inline-flex;align-items:center;gap:6px;font-size:13px;color:var(--muted);text-decoration:none;margin-bottom:16px;">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
    Back to payroll
</a>

<div class="uj-card" style="padding:22px 26px;max-width:900px;margin:0 auto;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:6px;flex-wrap:wrap;">
        <div>
            <div style="font-size:17px;font-weight:600;color:var(--ink);">Employer's annual return — Form E</div>
            <div style="font-size:12.5px;color:var(--muted);">Year {{ $year }} · Form E (C.P.8) + C.P.8D employee schedule</div>
        </div>
        <div style="display:flex;gap:8px;">
            <a href="{{ route('payroll.form-e.pdf', ['year' => $year]) }}" class="uj-btn-ghost" style="height:36px;padding:0 16px;font-size:12.5px;display:inline-flex;align-items:center;text-decoration:none;">Download Form E (PDF)</a>
            @if ($employerTinMissing)
                <span class="uj-btn-primary" aria-disabled="true" style="height:36px;padding:0 16px;font-size:12.5px;display:inline-flex;align-items:center;opacity:0.5;cursor:not-allowed;" title="Set the Employer TIN in Company Settings first.">Download C.P.8D (.txt)</span>
            @else
                <a href="{{ route('payroll.form-e.cp8d', ['year' => $year]) }}" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:12.5px;display:inline-flex;align-items:center;text-decoration:none;">Download C.P.8D (.txt)</a>
            @endif
        </div>
    </div>

    @if ($employerTinMissing)
        <div style="background:#fdecea;border:1px solid #e0836f;border-radius:8px;padding:10px 14px;margin:14px 0;font-size:12.5px;">
            <strong>The C.P.8D file cannot be downloaded yet.</strong> Its filename is required by LHDN to embed the
            company's Employer TIN, which is not set. <a href="{{ route('app.screen', 'settings') }}" style="color:inherit;text-decoration:underline;">Set it in Company Settings</a> first — Form E above is still available as a reference copy for hand completion.
        </div>
    @endif

    @php $missing = $cp8dRows->filter(fn ($r) => $r['data']['incomplete'] !== []); @endphp
    @if ($missing->isNotEmpty())
        <div style="background:#fff9ec;border:1px solid #d9a441;border-radius:8px;padding:10px 14px;margin:14px 0;font-size:12.5px;">
            <strong>{{ $missing->count() }} of {{ $cp8dRows->count() }} {{ \Illuminate\Support\Str::plural('employee', $missing->count()) }}</strong>
            {{ $missing->count() === 1 ? 'is' : 'are' }} missing a compulsory C.P.8D field. The C.P.8D file can still be
            downloaded, but <strong>LHDN will reject it</strong> until every compulsory field below is filled in by hand
            and re-entered before upload.
        </div>
    @else
        <div style="background:#f0f9f0;border:1px solid #8ec98e;border-radius:8px;padding:10px 14px;margin:14px 0;font-size:12.5px;">
            No compulsory C.P.8D fields are missing for any reportable employee.
        </div>
    @endif

    <div style="font-size:11px;font-weight:700;letter-spacing:0.6px;text-transform:uppercase;color:var(--muted);margin:18px 0 8px;">
        Form E items that could not be filled — write these in by hand before submitting
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
        <tr style="text-align:left;color:var(--muted);font-size:11px;text-transform:uppercase;">
            <th style="padding:4px 8px 4px 0;">Item</th><th style="padding:4px 0;">What it is</th>
        </tr>
        @foreach ($formE['incomplete'] as $box)
            <tr style="border-top:1px solid var(--hairline-soft);">
                <td style="padding:6px 8px 6px 0;font-family:monospace;">{{ $box['box'] }}</td>
                <td style="padding:6px 0;">{{ $box['label'] }}</td>
            </tr>
        @endforeach
    </table>

    <div style="font-size:11px;font-weight:700;letter-spacing:0.6px;text-transform:uppercase;color:var(--muted);margin:22px 0 8px;">
        C.P.8D — per-employee compulsory-field checklist ({{ $cp8dRows->count() }} {{ \Illuminate\Support\Str::plural('employee', $cp8dRows->count()) }})
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
        <tr style="text-align:left;color:var(--muted);font-size:11px;text-transform:uppercase;">
            <th style="padding:4px 8px 4px 0;">Employee</th><th style="padding:4px 0;">Missing compulsory fields</th>
        </tr>
        @forelse ($cp8dRows as $row)
            <tr style="border-top:1px solid var(--hairline-soft);">
                <td style="padding:6px 8px 6px 0;white-space:nowrap;">{{ $row['employee']->name }}</td>
                <td style="padding:6px 0;">
                    @if ($row['data']['incomplete'] === [])
                        <span style="color:#3a8f3a;">Complete</span>
                    @else
                        <span style="color:#b23c17;">{{ collect($row['data']['incomplete'])->pluck('label')->implode('; ') }}</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="2" style="padding:10px 0;color:var(--muted);">No employees with finalized payroll for {{ $year }}.</td></tr>
        @endforelse
    </table>
</div>

</body>
</html>
