{{-- Spec F11: statutory notices opened by a hire or a leaving date. Expects $payrollNotices, $salaryEmployees. --}}
@php
    $L = fn ($en, $ms) => '<span x-text="'.e("\$store.ui.lang==='en' ? ".json_encode($en).' : '.json_encode($ms)).'">'.e($en).'</span>';
    $fs = 'width:100%;height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;outline:none;background:var(--surface,#fff);color:var(--ink);';
    $labels = [
        'cp22' => ['CP22 (new employee)', 'CP22 (pekerja baharu)'],
        'cp22a' => ['CP22A (cessation)', 'CP22A (pemberhentian)'],
        'cp21' => ['CP21 (leaving Malaysia)', 'CP21 (meninggalkan Malaysia)'],
        'socso_form2' => ['SOCSO Form 2', 'Borang 2 PERKESO'],
        'kwsp_registration' => ['KWSP registration', 'Pendaftaran KWSP'],
    ];
@endphp
<div class="uj-card" style="max-width:980px;">
    <div class="uj-card-head" style="padding:16px 22px;">
        <h3 class="uj-card-title">{!! $L('Statutory notices', 'Notis berkanun') !!}</h3>
        <p style="font-size:12px;color:var(--muted);margin:2px 0 0;">{!! $L('CP22, SOCSO Form 2 and the KWSP registration open when someone joins; a CP22A opens when a last working day is set. A CP21 is opened by hand for someone leaving Malaysia.', 'CP22, Borang 2 PERKESO dan pendaftaran KWSP dibuka apabila seseorang menyertai; CP22A dibuka apabila hari bekerja terakhir ditetapkan. CP21 dibuka secara manual untuk yang meninggalkan Malaysia.') !!}</p>
    </div>

    <form method="post" action="{{ route('payroll.notices.cp21', ['employee' => 0]) }}" x-data="{ emp: '' }"
          @submit.prevent="$el.action = '{{ url('/app/payroll/employees') }}/' + emp + '/cp21'; $el.submit()"
          style="padding:12px 22px;border-bottom:1px solid var(--hairline-soft);display:flex;gap:10px;align-items:end;flex-wrap:wrap;">@csrf
        <div style="min-width:220px;"><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">{!! $L('Open a CP21 for', 'Buka CP21 untuk') !!}</label>
            <select x-model="emp" required style="{{ $fs }}">
                <option value="">—</option>
                @foreach ($salaryEmployees as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach
            </select></div>
        <button type="submit" class="uj-btn-ghost" style="height:34px;padding:0 14px;font-size:12.5px;">{!! $L('Open CP21', 'Buka CP21') !!}</button>
    </form>

    @if ($payrollNotices->isEmpty())
        <p style="padding:16px 22px;font-size:12.5px;color:var(--muted);margin:0;">{!! $L('No statutory notice open.', 'Tiada notis berkanun dibuka.') !!}</p>
    @else
        <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
            <tr style="color:var(--muted);text-align:left;">
                <th style="padding:8px 22px;font-weight:500;">{!! $L('Employee', 'Pekerja') !!}</th>
                <th style="padding:8px;font-weight:500;">{!! $L('Notice', 'Notis') !!}</th>
                <th style="padding:8px;font-weight:500;">{!! $L('Due', 'Tarikh akhir') !!}</th>
                <th style="padding:8px;font-weight:500;">{!! $L('Filed', 'Difailkan') !!}</th>
                <th style="padding:8px 22px;font-weight:500;"></th>
            </tr>
            @foreach ($payrollNotices as $n)
                <tr style="border-top:1px solid var(--hairline-soft);">
                    <td style="padding:9px 22px;">{{ $n->employee?->name }}</td>
                    <td style="padding:9px 8px;">{!! $L(...($labels[$n->type] ?? [$n->type, $n->type])) !!}</td>
                    <td style="padding:9px 8px;{{ $n->isOverdue() ? 'color:var(--error);' : '' }}">{{ $n->due_on?->format('j M Y') }}</td>
                    <td style="padding:9px 8px;">
                        @if ($n->filed_on)
                            {{ $n->filed_on->format('j M Y') }}{{ $n->reference ? ' · '.$n->reference : '' }}
                            @if ($n->type === 'cp22a')<div style="font-size:11px;color:var(--muted);">{!! $L('Clearance', 'Pelepasan') !!}: {{ $n->cleared_on?->format('j M Y') ?: '—' }}</div>@endif
                        @else
                            <form method="post" action="{{ route('payroll.notices.file', $n) }}" style="display:flex;gap:6px;align-items:center;">@csrf
                                <input type="date" name="filed_on" value="{{ now()->toDateString() }}" required style="{{ $fs }}width:150px;" />
                                <input name="reference" maxlength="80" placeholder="Ref" style="{{ $fs }}width:110px;" />
                                <button type="submit" class="uj-btn-ghost" style="height:34px;padding:0 12px;font-size:12px;">{!! $L('Mark filed', 'Tanda difailkan') !!}</button>
                            </form>
                        @endif
                    </td>
                    <td style="padding:9px 22px;text-align:right;white-space:nowrap;">
                        @if (in_array($n->type, ['cp21', 'cp22', 'cp22a'], true) && $n->employee)
                            @php $formYear = ($n->type === 'cp22' ? $n->employee->joined_at : $n->employee->last_working_day)?->year ?? now()->year; @endphp
                            <a class="uj-btn-ghost" style="height:26px;padding:0 10px;font-size:12px;display:inline-flex;align-items:center;margin-right:6px;" href="{{ route('app.screen', ['screen' => 'payroll-form', 'tab' => $n->type, 'year' => $formYear, 'employee' => $n->employee_id]) }}">{!! $L('View form', 'Lihat borang') !!}</a>
                        @endif
                        @if ($n->type === 'socso_form2' && $n->employee?->joined_at)
                            <a class="uj-btn-ghost" style="height:26px;padding:0 10px;font-size:12px;display:inline-flex;align-items:center;margin-right:6px;" href="{{ route('app.screen', ['screen' => 'payroll-form', 'tab' => 'sip2', 'from' => $n->employee->joined_at->toDateString(), 'to' => $n->employee->joined_at->toDateString()]) }}">{!! $L('View form', 'Lihat borang') !!}</a>
                        @endif
                        @if ($n->type === 'cp22a')
                            <a class="uj-btn-ghost" style="height:26px;padding:0 10px;font-size:12px;display:inline-flex;align-items:center;" href="{{ route('payroll.notices.pcb2ii', $n) }}">PCB 2(II)</a>
                            @if ($n->filed_on && ! $n->cleared_on)
                                <form method="post" action="{{ route('payroll.notices.clear', $n) }}" style="display:inline-flex;gap:6px;align-items:center;margin-left:6px;">@csrf
                                    <input type="date" name="cleared_on" value="{{ now()->toDateString() }}" required style="{{ $fs }}width:150px;" />
                                    <button type="submit" class="uj-btn-ghost" style="height:34px;padding:0 12px;font-size:12px;">{!! $L('Clearance received', 'Pelepasan diterima') !!}</button>
                                </form>
                            @endif
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    @endif
</div>
