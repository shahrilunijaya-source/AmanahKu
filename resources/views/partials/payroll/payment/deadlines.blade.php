{{-- Spec F12: every statutory filing this company owes, its due date and its receipt.
     Expects $payrollSubmissions (Collection<PayrollSubmission>). --}}
@php
    $L = fn ($en, $ms) => '<span x-text="'.e("\$store.ui.lang==='en' ? ".json_encode($en).' : '.json_encode($ms)).'">'.e($en).'</span>';
    $fs = 'height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;outline:none;background:var(--surface,#fff);color:var(--ink);';
    $stateLabels = [
        'not_started' => ['Not started', 'Belum mula', 'var(--muted)'],
        'file_ready' => ['File downloaded', 'Fail dimuat turun', 'var(--body)'],
        'submitted' => ['Submitted', 'Dihantar', 'var(--success)'],
        'overdue' => ['Overdue', 'Lewat', 'var(--error)'],
    ];
@endphp
<div class="uj-card" style="max-width:1040px;">
    <div class="uj-card-head" style="padding:16px 22px;">
        <h3 class="uj-card-title">{!! $L('Statutory deadlines', 'Tarikh akhir berkanun') !!}</h3>
        <p style="font-size:12px;color:var(--muted);margin:2px 0 0;">{!! $L('EPF, SOCSO/EIS, PCB and the HRD Corp levy are due on the 15th of the following month. Form EA goes to employees by the end of February and Form E to LHDN by 31 March.', 'KWSP, PERKESO/SIP, PCB dan levi HRD Corp perlu dijelaskan pada 15 haribulan berikutnya. Borang EA kepada pekerja sebelum akhir Februari dan Borang E kepada LHDN sebelum 31 Mac.') !!}</p>
    </div>
    @if ($payrollSubmissions->isEmpty())
        <p style="padding:16px 22px;font-size:12.5px;color:var(--muted);margin:0;">{!! $L('Nothing due yet. Filings open when a run is finalized.', 'Tiada lagi. Penyerahan dibuka apabila run dimuktamadkan.') !!}</p>
    @else
        <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
            <tr style="color:var(--muted);text-align:left;">
                <th style="padding:8px 22px;font-weight:500;">{!! $L('Filing', 'Penyerahan') !!}</th>
                <th style="padding:8px;font-weight:500;">{!! $L('For', 'Untuk') !!}</th>
                <th style="padding:8px;font-weight:500;">{!! $L('Due', 'Tarikh akhir') !!}</th>
                <th style="padding:8px;font-weight:500;">{!! $L('Status', 'Status') !!}</th>
                <th style="padding:8px 22px;font-weight:500;">{!! $L('Receipt', 'Resit') !!}</th>
            </tr>
            @foreach ($payrollSubmissions as $s)
                @php $state = $stateLabels[$s->state()]; @endphp
                <tr style="border-top:1px solid var(--hairline-soft);">
                    <td style="padding:9px 22px;">{!! $L(...\App\Models\PayrollSubmission::LABELS[$s->agency]) !!}</td>
                    <td style="padding:9px 8px;">{{ $s->payrollRun !== null ? $s->payrollRun->label : $s->year }}</td>
                    <td style="padding:9px 8px;">{{ $s->due_on?->format('j M Y') }}</td>
                    <td style="padding:9px 8px;color:{{ $state[2] }};">{!! $L($state[0], $state[1]) !!}</td>
                    <td style="padding:9px 22px;">
                        @if ($s->submitted_at)
                            {{ $s->receipt_reference }}@if ($s->amount_paid) · RM {{ number_format($s->amount_paid, 2) }}@endif
                            <div style="font-size:11px;color:var(--muted);">{{ $s->submitted_at->format('j M Y') }}</div>
                        @else
                            <form method="post" action="{{ route('payroll.submissions.submit', $s) }}" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">@csrf
                                <input name="receipt_reference" maxlength="80" required placeholder="{{ __('Receipt no.') }}" style="{{ $fs }}width:130px;" />
                                <input name="amount_paid" type="number" step="0.01" min="0" placeholder="RM" style="{{ $fs }}width:100px;" />
                                <button type="submit" class="uj-btn-ghost" style="height:34px;padding:0 12px;font-size:12px;">{!! $L('Mark submitted', 'Tanda dihantar') !!}</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    @endif
</div>
