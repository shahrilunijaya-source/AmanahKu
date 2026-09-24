{{-- Worksy's Zakat Form: month arrows plus a zakat authority dropdown, the staff with zakat
     deducted that month, total, Print and a CSV. Per-authority upload files wait for their
     specs, so there is no agency file yet. --}}
@php
    use App\Services\Payroll\Statutory\StatutoryMonth;
    use App\Services\Payroll\Statutory\ZakatMonth;
    use App\Support\StatutoryOptions;

    $company = app(\App\Tenancy\CurrentTenant::class)->get();
    $mine = request('tab') === 'zakat';
    $period = $mine && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) request('period')) === 1 ? (string) request('period') : StatutoryMonth::defaultPeriod($company);
    $authority = $mine ? (string) request('authority') : '';
    $authority = $authority === ZakatMonth::NONE || array_key_exists($authority, StatutoryOptions::ZAKAT_AUTHORITIES) ? $authority : null;
    $month = \Carbon\CarbonImmutable::createFromFormat('!Y-m', $period);
    $data = ZakatMonth::for($company, $period, $authority);
    $url = fn (\Carbon\CarbonImmutable $m) => route('app.screen', array_filter(['screen' => 'payroll-form', 'tab' => 'zakat', 'period' => $m->format('Y-m'), 'authority' => $authority]));
    $fs = 'height:32px;padding:0 8px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;background:#fff;';
@endphp
<div class="uj-card uj-print-area" data-testid="zakat-form" style="padding:20px;">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px;">
        <h3 class="uj-card-title" style="margin:0;">Zakat Form</h3>
        <span style="font-size:12.5px;color:var(--muted);">{{ $company->name }}{{ $company->zakat_employer_no ? ' · '.$company->zakat_employer_no : '' }}</span>
        <form method="get" action="{{ route('app.screen', 'payroll-form') }}" class="uj-no-print" style="margin-left:auto;display:flex;align-items:center;gap:6px;">
            <input type="hidden" name="tab" value="zakat">
            <a href="{{ $url($month->subMonth()) }}" class="uj-btn-ghost" aria-label="Previous month" style="height:32px;width:32px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;">‹</a>
            <input type="month" name="period" value="{{ $period }}" onchange="this.form.submit()" style="{{ $fs }}">
            <a href="{{ $url($month->addMonth()) }}" class="uj-btn-ghost" aria-label="Next month" style="height:32px;width:32px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;">›</a>
            <select name="authority" onchange="this.form.submit()" style="{{ $fs }}" aria-label="Zakat authority">
                <option value="">All authorities</option>
                @foreach (StatutoryOptions::ZAKAT_AUTHORITIES as $key => $label)
                    <option value="{{ $key }}" @selected($authority === $key)>{{ $label }}</option>
                @endforeach
                <option value="{{ ZakatMonth::NONE }}" @selected($authority === ZakatMonth::NONE)>No zakat authority set</option>
            </select>
            <div x-data="{ m: false }" style="position:relative;">
                <button type="button" @click="m = !m" @click.outside="m = false" class="uj-btn-ghost" aria-label="More" style="height:32px;width:32px;">⋮</button>
                <div x-show="m" x-cloak style="position:absolute;right:0;top:36px;z-index:20;min-width:180px;background:#fff;border:1px solid var(--hairline);border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.12);padding:6px;">
                    <button type="button" @click="m = false; window.print()" style="display:block;width:100%;text-align:left;padding:8px 10px;font-size:13px;border-radius:6px;" x-text="$store.ui.lang==='en' ? 'Print' : 'Cetak'">Print</button>
                    @if ($data['rows'] !== [])
                        <a href="{{ route('payroll.export.zakat', array_filter(['period' => $period, 'authority' => $authority])) }}" style="display:block;padding:8px 10px;font-size:13px;color:var(--ink);text-decoration:none;border-radius:6px;" x-text="$store.ui.lang==='en' ? 'Download CSV' : 'Muat turun CSV'">Download CSV</a>
                    @endif
                </div>
            </div>
        </form>
    </div>

    @if ($data['rows'] === [])
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
                        <th style="padding:8px 6px;font-weight:500;" x-text="$store.ui.lang==='en' ? 'Zakat authority' : 'Pihak berkuasa zakat'">Zakat authority</th>
                        <th style="padding:8px 6px;font-weight:500;text-align:right;" x-text="$store.ui.lang==='en' ? 'Zakat (RM)' : 'Zakat (RM)'">Zakat (RM)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($data['rows'] as $i => $r)
                        <tr style="border-bottom:1px solid var(--hairline-soft);">
                            <td style="padding:7px 6px;color:var(--muted);">{{ $i + 1 }}</td>
                            <td style="padding:7px 6px;color:var(--ink);">{{ $r['name'] }}</td>
                            <td style="padding:7px 6px;font-family:var(--font-mono);">{{ $r['ic'] ?: '-' }}</td>
                            <td style="padding:7px 6px;{{ $r['authority'] === null ? 'color:var(--red);' : '' }}">{{ $r['authority'] ?? 'No zakat authority set' }}</td>
                            <td style="padding:7px 6px;text-align:right;font-family:var(--font-mono);">{{ number_format($r['amount'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr style="border-top:2px solid var(--hairline);font-weight:600;">
                        <td colspan="4" style="padding:8px 6px;" x-text="$store.ui.lang==='en' ? 'Total' : 'Jumlah'">Total</td>
                        <td style="padding:8px 6px;text-align:right;font-family:var(--font-mono);">{{ number_format($data['total'], 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
    <p class="uj-no-print" style="font-size:12px;color:var(--muted);margin:12px 0 0;" x-text="$store.ui.lang==='en' ? 'Set each person\'s zakat authority in their profile, Bank &amp; Statutory. Each zakat body has its own upload format, so pay from this listing for now.' : 'Tetapkan pihak berkuasa zakat setiap orang di profil, Bank &amp; Statutori. Setiap badan zakat ada format muat naik sendiri, jadi bayar menggunakan senarai ini buat masa ini.'">Set each person's zakat authority in their profile, Bank &amp; Statutory.</p>
</div>
