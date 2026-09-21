{{-- Zakat through payroll: the monthly amount on each salary structure. It is deducted
     from pay and set off against PCB ringgit for ringgit. Edited on the staff profile. --}}
@php
    $L = fn ($en, $ms) => '<span x-text="'.e("\$store.ui.lang==='en' ? ".json_encode($en).' : '.json_encode($ms)).'">'.e($en).'</span>';
    $zakatRows = \App\Models\SalaryStructure::with('employee:id,name')->where('zakat_monthly', '>', 0)->get()->sortBy(fn ($s) => $s->employee?->name);
    $th = 'text-align:left;font-size:11px;color:var(--muted);text-transform:uppercase;padding:10px 12px;';
    $td = 'padding:10px 12px;font-size:13px;color:var(--ink);border-top:1px solid var(--hairline-soft);';
@endphp
<div class="uj-card" style="max-width:720px;">
    <div class="uj-card-head" style="padding:16px 22px;">
        <div>
        <h3 class="uj-card-title">{!! $L('Tax Rebate (Zakat)', 'Rebat Cukai (Zakat)') !!}</h3>
        <p style="font-size:12px;line-height:1.5;color:var(--muted);margin:4px 0 0;max-width:72ch;">{!! $L('Zakat deducted through payroll reduces PCB by the same amount. Set the monthly amount on the staff member\'s profile, Bank & Statutory tab. Zakat the employee pays on their own goes under Personal Tax Relief (TP1).', 'Zakat yang dipotong melalui gaji mengurangkan PCB dengan jumlah yang sama. Tetapkan jumlah bulanan pada profil staf, tab Bank & Statutori. Zakat yang dibayar sendiri oleh pekerja direkod di bawah Pelepasan Cukai Peribadi (TP1).') !!}</p>
        </div>
    </div>
    @if ($zakatRows->isEmpty())
        <p style="padding:18px 22px;font-size:13px;color:var(--muted);margin:0;">{!! $L('No one has a monthly zakat deduction.', 'Tiada sesiapa mempunyai potongan zakat bulanan.') !!}</p>
    @else
        <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;">
            <thead><tr><th style="{{ $th }}">{!! $L('Employee', 'Pekerja') !!}</th><th style="{{ $th }}text-align:right;">{!! $L('Monthly zakat (RM)', 'Zakat bulanan (RM)') !!}</th><th style="{{ $th }}"></th></tr></thead>
            <tbody>
                @foreach ($zakatRows as $s)
                    <tr>
                        <td style="{{ $td }}">{{ $s->employee?->name }}</td>
                        <td style="{{ $td }}text-align:right;font-family:var(--font-mono);font-variant-numeric:tabular-nums;">{{ number_format((float) $s->zakat_monthly, 2) }}</td>
                        <td style="{{ $td }}text-align:right;"><a href="{{ route('app.screen', 'profile') }}?emp={{ $s->employee_id }}&tab=bank" style="color:var(--red);font-size:12px;">{!! $L('Open profile', 'Buka profil') !!}</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    @endif
</div>
