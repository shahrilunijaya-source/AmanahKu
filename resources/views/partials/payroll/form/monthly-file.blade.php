{{-- One monthly agency file on the Form screen, Worksy's company + month page (pattern A)
     or its Generate card (pattern D). $tab, $fileKey, $title, $pattern ('A'|'D'), optional
     $note / $noteMs. Rows come from the same StatutoryFile::rows() the download writes. --}}
@php
    $company = app(\App\Tenancy\CurrentTenant::class)->get();
    $file = \App\Services\Payroll\Statutory\StatutoryFileRegistry::find($fileKey);
    $period = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) request('period')) === 1
        ? (string) request('period')
        : \App\Services\Payroll\Statutory\StatutoryMonth::defaultPeriod($company);
    $month = \Carbon\CarbonImmutable::createFromFormat('!Y-m', $period);
    $data = \App\Services\Payroll\Statutory\StatutoryMonth::for($company, $period, $file);
    $gap = $file->missingEmployerNumber($company);
    $downloadUrl = $data['run'] && $gap === null ? route('payroll.export.statutory-file', [$data['run'], $fileKey]) : null;
    $monthUrl = fn (\Carbon\CarbonImmutable $m) => route('app.screen', ['screen' => 'payroll-form', 'tab' => $tab, 'period' => $m->format('Y-m')]);
    $rm = fn (float $v) => number_format($v, 2);
    $pattern ??= 'A';
@endphp
<div class="uj-card uj-print-area" data-testid="monthly-file-{{ $tab }}" style="padding:20px;">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px;">
        <h3 class="uj-card-title" style="margin:0;">{{ $title }}</h3>
        @unless ($file->verified())
            <span class="uj-pill" style="font-size:10px;" x-text="$store.ui.lang==='en' ? 'check layout' : 'semak susun atur'">check layout</span>
        @endunless
        <span style="font-size:12.5px;color:var(--muted);">{{ $company->name }}</span>

        @if ($pattern === 'A')
            <div class="uj-no-print" style="margin-left:auto;display:flex;align-items:center;gap:6px;">
                <a href="{{ $monthUrl($month->subMonth()) }}" class="uj-btn-ghost" aria-label="Previous month" style="height:32px;width:32px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;">‹</a>
                <form method="get" action="{{ route('app.screen', 'payroll-form') }}">
                    <input type="hidden" name="tab" value="{{ $tab }}">
                    <input type="month" name="period" value="{{ $period }}" onchange="this.form.submit()" style="height:32px;padding:0 8px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;">
                </form>
                <a href="{{ $monthUrl($month->addMonth()) }}" class="uj-btn-ghost" aria-label="Next month" style="height:32px;width:32px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;">›</a>
                <div x-data="{ m: false }" style="position:relative;">
                    <button type="button" @click="m = !m" @click.outside="m = false" class="uj-btn-ghost" aria-label="More" style="height:32px;width:32px;">⋮</button>
                    <div x-show="m" x-cloak style="position:absolute;right:0;top:36px;z-index:20;min-width:200px;background:#fff;border:1px solid var(--hairline);border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.12);padding:6px;">
                        <button type="button" @click="m = false; window.print()" style="display:block;width:100%;text-align:left;padding:8px 10px;font-size:13px;border-radius:6px;" x-text="$store.ui.lang==='en' ? 'Print' : 'Cetak'">Print</button>
                        @if ($downloadUrl)
                            <a href="{{ $downloadUrl }}" style="display:block;padding:8px 10px;font-size:13px;color:var(--ink);text-decoration:none;border-radius:6px;" x-text="$store.ui.lang==='en' ? @js($downloadLabel ?? 'Download file') : @js($downloadLabelMs ?? 'Muat turun fail')">{{ $downloadLabel ?? 'Download file' }}</a>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>

    @if ($gap !== null)
        <a href="{{ route('app.screen', ['screen' => 'settings', 'section' => 'statutory']) }}" class="uj-no-print" data-testid="missing-{{ $fileKey }}" style="display:block;margin-bottom:12px;background:var(--red-tint);color:var(--red);font-size:12.5px;border-radius:8px;padding:9px 12px;text-decoration:none;">{{ $gap }}</a>
    @endif

    @if ($pattern === 'D')
        {{-- Worksy's generator card. One company per tenant, so no company picker. --}}
        <form method="get" action="{{ route('app.screen', 'payroll-form') }}" class="uj-no-print" style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <div>
                <label style="display:block;font-size:12.5px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Payroll period' : 'Tempoh gaji'">Payroll period</label>
                <input type="month" name="period" value="{{ $period }}" onchange="this.form.submit()" style="height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;">
            </div>
            @if ($downloadUrl)
                <a href="{{ $downloadUrl }}" class="uj-btn-primary" style="height:36px;padding:0 18px;font-size:13px;display:inline-flex;align-items:center;text-decoration:none;" x-text="$store.ui.lang==='en' ? 'Generate' : 'Jana'">Generate</a>
            @else
                <button type="button" disabled class="uj-btn-primary" style="height:36px;padding:0 18px;font-size:13px;opacity:.5;cursor:not-allowed;" x-text="$store.ui.lang==='en' ? 'Generate' : 'Jana'">Generate</button>
            @endif
        </form>
        @if (! $data['run'])
            <div style="margin-top:12px;font-size:13px;color:var(--muted);">There is no record for {{ $month->format('M Y') }}.</div>
        @else
            <div style="margin-top:12px;font-size:12.5px;color:var(--muted);">{{ count($data['rows']) }} <span x-text="$store.ui.lang==='en' ? 'employees in the file' : 'pekerja dalam fail'">employees in the file</span>@foreach ($file->columns() as $key => [$en, $ms]) · {{ $en }} RM {{ $rm($data['totals'][$key]) }}@endforeach</div>
        @endif
    @elseif (! $data['run'] || $data['rows'] === [])
        <div style="padding:30px 0;text-align:center;font-size:13px;color:var(--muted);">There is no record for {{ $month->format('M Y') }}.</div>
    @else
        <div style="font-size:12.5px;color:var(--muted);margin-bottom:8px;">{{ $month->format('F Y') }}</div>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
                <thead>
                    <tr style="text-align:left;color:var(--muted);border-bottom:1px solid var(--hairline);">
                        <th style="padding:8px 6px;font-weight:500;">#</th>
                        <th style="padding:8px 6px;font-weight:500;" x-text="$store.ui.lang==='en' ? 'Employee' : 'Pekerja'">Employee</th>
                        <th style="padding:8px 6px;font-weight:500;" x-text="$store.ui.lang==='en' ? 'IC no.' : 'No. KP'">IC no.</th>
                        @isset($refLabel)<th style="padding:8px 6px;font-weight:500;">{{ $refLabel }}</th>@endisset
                        @foreach ($file->columns() as [$en, $ms])
                            <th style="padding:8px 6px;font-weight:500;text-align:right;" x-text="$store.ui.lang==='en' ? @js($en) : @js($ms)">{{ $en }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($data['rows'] as $i => $r)
                        <tr style="border-bottom:1px solid var(--hairline-soft);">
                            <td style="padding:7px 6px;color:var(--muted);">{{ $i + 1 }}</td>
                            <td style="padding:7px 6px;color:var(--ink);">{{ $r['name'] }}</td>
                            <td style="padding:7px 6px;font-family:var(--font-mono);">{{ $r['ic'] ?: '-' }}</td>
                            @isset($refLabel)<td style="padding:7px 6px;font-family:var(--font-mono);">{{ $r['ref'] ?: '-' }}</td>@endisset
                            @foreach (array_keys($file->columns()) as $key)
                                <td style="padding:7px 6px;text-align:right;font-family:var(--font-mono);">{{ $rm($r['amounts'][$key]) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr style="border-top:2px solid var(--hairline);font-weight:600;">
                        <td colspan="{{ isset($refLabel) ? 4 : 3 }}" style="padding:8px 6px;" x-text="$store.ui.lang==='en' ? 'Total' : 'Jumlah'">Total</td>
                        @foreach (array_keys($file->columns()) as $key)
                            <td style="padding:8px 6px;text-align:right;font-family:var(--font-mono);">{{ $rm($data['totals'][$key]) }}</td>
                        @endforeach
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
    @isset($note)
        <p class="uj-no-print" style="font-size:12px;color:var(--muted);margin:12px 0 0;" x-text="$store.ui.lang==='en' ? @js($note) : @js($noteMs)">{{ $note }}</p>
    @endisset
</div>
@once
    <style>
        @media print {
            body * { visibility: hidden; }
            .uj-print-area, .uj-print-area * { visibility: visible; }
            .uj-print-area { position: absolute; left: 0; top: 0; width: 100%; box-shadow: none; border: 0; }
            .uj-no-print { display: none !important; }
        }
    </style>
@endonce
