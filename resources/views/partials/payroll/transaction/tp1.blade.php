{{-- Spec F8: Form TP1 monthly optional deductions and zakat. Feeds PCB through
     PcbYearToDate. Expects $tp1Year, $tp1Claims, $salaryEmployees. --}}
@php
    use App\Support\Tp1Reliefs;
    $L = fn ($en, $ms) => '<span x-text="'.e("\$store.ui.lang==='en' ? ".json_encode($en).' : '.json_encode($ms)).'">'.e($en).'</span>';
    $fs = 'width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;outline:none;background:var(--surface,#fff);color:var(--ink);';
    $lbl = 'display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;';
    $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
@endphp
<div class="uj-card" style="max-width:980px;">
    <div class="uj-card-head" style="padding:16px 22px;">
        <h3 class="uj-card-title">{!! $L('Personal Tax Relief (TP1)', 'Pelepasan Cukai Peribadi (TP1)') !!}</h3>
        <p style="font-size:12px;color:var(--muted);margin:2px 0 0;">{!! $L('What an employee declared on Form TP1 for a month. Each relief is capped for the year ('.Tp1Reliefs::YEAR.' figures, LHDN MTD specification). Zakat declared here is paid by the employee to Pusat Zakat, so it never shows on the payslip or the EA form.', 'Apa yang pekerja isytiharkan dalam Borang TP1 untuk sesuatu bulan. Setiap pelepasan ada had tahunan (angka '.Tp1Reliefs::YEAR.', spesifikasi PCB LHDN). Zakat di sini dibayar sendiri oleh pekerja kepada Pusat Zakat, jadi ia tidak muncul pada slip gaji atau Borang EA.') !!}</p>
    </div>

    <form method="post" action="{{ route('payroll.tp1.store') }}" style="padding:14px 22px;border-bottom:1px solid var(--hairline-soft);display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;align-items:end;">@csrf
        <div><label style="{{ $lbl }}">{!! $L('Employee', 'Pekerja') !!}</label>
            <select name="employee_id" required style="{{ $fs }}">
                @foreach ($salaryEmployees as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach
            </select></div>
        <div><label style="{{ $lbl }}">{!! $L('Year', 'Tahun') !!}</label><input name="year" type="number" min="2020" max="2100" value="{{ $tp1Year }}" required style="{{ $fs }}" /></div>
        <div><label style="{{ $lbl }}">{!! $L('Month', 'Bulan') !!}</label>
            <select name="month" required style="{{ $fs }}">
                @foreach ($months as $i => $m)<option value="{{ $i + 1 }}" @selected($i + 1 === (int) now()->month)>{{ $m }}</option>@endforeach
            </select></div>
        <div style="grid-column:span 2;"><label style="{{ $lbl }}">{!! $L('Relief', 'Pelepasan') !!}</label>
            <select name="relief_code" style="{{ $fs }}">
                <option value="">{{ __('—') }}</option>
                @foreach (Tp1Reliefs::LIST as $code => $r)
                    <option value="{{ $code }}">{{ $r['label'] }} (max RM {{ number_format($r['cap'], 2) }})</option>
                @endforeach
            </select></div>
        <div><label style="{{ $lbl }}">{!! $L('Amount (RM)', 'Jumlah (RM)') !!}</label><input name="amount" type="number" step="0.01" min="0" style="{{ $fs }}" /></div>
        <div><label style="{{ $lbl }}">{!! $L('Zakat (RM)', 'Zakat (RM)') !!}</label><input name="zakat_amount" type="number" step="0.01" min="0" style="{{ $fs }}" /></div>
        <div style="grid-column:span 2;"><label style="{{ $lbl }}">{!! $L('Note', 'Nota') !!}</label><input name="note" maxlength="255" style="{{ $fs }}" /></div>
        <div><button type="submit" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:13px;">{!! $L('Record claim', 'Rekod tuntutan') !!}</button></div>
    </form>

    @if ($tp1Claims->isEmpty())
        <p style="padding:16px 22px;font-size:12.5px;color:var(--muted);margin:0;">{!! $L('No TP1 claim recorded for this year.', 'Tiada tuntutan TP1 direkodkan untuk tahun ini.') !!}</p>
    @else
        <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
            <tr style="color:var(--muted);text-align:left;">
                <th style="padding:8px 22px;font-weight:500;">{!! $L('Employee', 'Pekerja') !!}</th>
                <th style="padding:8px;font-weight:500;">{!! $L('Month', 'Bulan') !!}</th>
                <th style="padding:8px;font-weight:500;">{!! $L('Relief', 'Pelepasan') !!}</th>
                <th style="padding:8px;font-weight:500;">{!! $L('Amount', 'Jumlah') !!}</th>
                <th style="padding:8px;font-weight:500;">{!! $L('Zakat', 'Zakat') !!}</th>
                <th></th>
            </tr>
            @foreach ($tp1Claims as $c)
                <tr style="border-top:1px solid var(--hairline-soft);">
                    <td style="padding:9px 22px;">{{ $c->employee?->name }}</td>
                    <td style="padding:9px 8px;">{{ $months[$c->month - 1] ?? $c->month }} {{ $c->year }}</td>
                    <td style="padding:9px 8px;">{{ $c->relief_code === null ? '—' : (Tp1Reliefs::LIST[$c->relief_code]['label'] ?? $c->relief_code) }}</td>
                    <td style="padding:9px 8px;">RM {{ number_format($c->amount, 2) }}</td>
                    <td style="padding:9px 8px;">RM {{ number_format($c->zakat_amount, 2) }}</td>
                    <td style="padding:9px 22px;text-align:right;">
                        <form method="post" action="{{ route('payroll.tp1.delete', $c) }}" onsubmit="return confirm('Remove this TP1 claim?')">@csrf
                            <button type="submit" class="uj-btn-ghost" style="height:26px;padding:0 10px;font-size:12px;">{!! $L('Remove', 'Buang') !!}</button>
                        </form>
                    </td>
                </tr>
            @endforeach
        </table>
    @endif
</div>
