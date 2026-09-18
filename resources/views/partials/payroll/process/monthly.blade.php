@php
    $statusColor = ['draft' => 'var(--amber)', 'approved' => 'var(--info)', 'finalized' => 'var(--success)'];
    $statusMs = ['draft' => 'Draf', 'approved' => 'Diluluskan', 'finalized' => 'Difinalize'];
    $money = fn ($v) => 'RM '.number_format((float) $v, 2);
@endphp
{{-- excluded: how many blocked people HR has ticked "leave out", so the create button
     re-enables once every blocker is accounted for (the server-side gate still decides). --}}
<div x-data="{ excluded: 0 }">
@include('partials.payroll.process.readiness')
<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    <div class="uj-card" style="flex:1;min-width:260px;max-width:340px;padding:20px;">
        <h3 class="uj-card-title" style="margin-bottom:14px;" x-text="$store.ui.lang==='en' ? 'New payroll run' : 'Payroll run baharu'">New payroll run</h3>
        <form method="post" action="{{ route('payroll.runs.create') }}" id="create-run-form">
            @csrf
            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Pay month' : 'Bulan gaji'">Pay month</label>
            <input name="period" type="month" value="{{ old('period', now()->format('Y-m')) }}" required style="width:100%;height:42px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;margin-bottom:6px;" />
            @error('period')<div style="font-size:12px;color:var(--error);margin-bottom:8px;">{{ $message }}</div>@enderror
            @include('partials.hint', ['en' => 'The month you are paying for. One draft run per month — you can edit it freely until you finalize.', 'ms' => 'Bulan yang anda bayar gaji. Satu draft run setiap bulan — anda boleh sunting dengan bebas sehingga finalize.'])
            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin:12px 0 6px;" x-text="$store.ui.lang==='en' ? 'Payment date (optional)' : 'Tarikh bayaran (pilihan)'">Payment date (optional)</label>
            <input name="payment_date" type="date" value="{{ old('payment_date') }}" style="width:100%;height:42px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;margin-bottom:6px;" />
            @error('payment_date')<div style="font-size:12px;color:var(--error);margin-bottom:8px;">{{ $message }}</div>@enderror
            @include('partials.hint', ['en' => 'The day salaries reach staff bank accounts.', 'ms' => 'Hari gaji masuk ke akaun bank staf.'])
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 12px;margin:14px 0 4px;">
                @foreach ([
                    'fixed' => ['Pull monthly allowance/deduction', 'Tarik elaun/potongan bulanan'],
                    'claims' => ['Pull claim data', 'Tarik data tuntutan'],
                    'overtime' => ['Pull overtime', 'Tarik kerja lebih masa'],
                    'unpaid' => ['Pull unpaid leave', 'Tarik cuti tanpa gaji'],
                ] as $src => [$en, $ms])
                    <label style="display:flex;align-items:flex-start;gap:8px;font-size:12.5px;color:var(--ink);cursor:pointer;">
                        <input type="hidden" name="pull_{{ $src }}" value="0">
                        <input type="checkbox" name="pull_{{ $src }}" value="1" @checked(old('pull_'.$src, '1') === '1') style="margin-top:2px;accent-color:var(--red);">
                        <span x-text="$store.ui.lang==='en' ? @js($en) : @js($ms)">{{ $en }}</span>
                    </label>
                @endforeach
            </div>
            @include('partials.hint', ['en' => 'Untick a source to leave it out of this run. Anything left out stays waiting for the next run.', 'ms' => 'Nyahtanda sumber untuk mengecualikannya daripada run ini. Apa yang dikecualikan kekal menunggu run seterusnya.'])
            <p style="font-size:11.5px;color:var(--muted);margin:6px 0 14px;" x-text="$store.ui.lang==='en' ? 'Generates a draft payslip for every active employee with a salary structure.' : 'Menjana draft payslip untuk setiap pekerja aktif yang ada struktur gaji.'">Generates a draft payslip for every active employee with a salary structure. Approved claims are pulled in as reimbursements.</p>
            <button type="submit" class="uj-btn-primary" style="height:40px;width:100%;font-size:13.5px;" @if ($readinessBlockingCount > 0) :disabled="{{ $readinessBlockingCount }} - excluded > 0" title="Fix the readiness list above, or leave the person out of this run" @endif><span x-text="$store.ui.lang==='en' ? 'Generate draft run' : 'Jana draft run'">Generate draft run</span>@if ($readinessBlockingCount > 0) · {{ $readinessBlockingCount }}@endif</button>
        </form>
    </div>

    <div class="uj-card" style="flex:2;min-width:420px;padding:0;">
        <div class="uj-card-head"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Payroll runs' : 'Run gaji'">Payroll runs</h3></div>
        <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
            <thead><tr style="color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.5px;">
                <th style="text-align:left;padding:10px 18px;" x-text="$store.ui.lang==='en' ? 'Period' : 'Tempoh'">Period</th>
                <th style="text-align:left;padding:10px 8px;" x-text="$store.ui.lang==='en' ? 'Cycle' : 'Kitaran'">Cycle</th>
                <th style="text-align:left;padding:10px 8px;">Status</th>
                <th style="text-align:right;padding:10px 8px;" x-text="$store.ui.lang==='en' ? 'Staff' : 'Staf'">Staff</th>
                <th style="text-align:right;padding:10px 8px;" x-text="$store.ui.lang==='en' ? 'Net' : 'Bersih'">Net</th>
                <th style="padding:10px 18px;"></th>
            </tr></thead>
            <tbody>
            @forelse ($runs as $r)
                <tr style="border-top:1px solid var(--hairline-soft);">
                    <td style="padding:12px 18px;font-weight:500;color:var(--ink);">{{ $r->label }}</td>
                    <td style="padding:12px 8px;">Month End</td>
                    <td style="padding:12px 8px;"><span class="uj-pill" style="background:#fff;border:1px solid var(--hairline);color:{{ $statusColor[$r->status] ?? 'var(--muted)' }};text-transform:capitalize;font-size:10.5px;" x-text="$store.ui.lang==='en' ? @js($r->status) : @js($statusMs[$r->status] ?? $r->status)">{{ $r->status }}</span></td>
                    <td style="padding:12px 8px;text-align:right;">{{ $r->payslips_count }}</td>
                    <td style="padding:12px 8px;text-align:right;font-family:var(--font-mono);">{{ $money($r->totals['net'] ?? 0) }}</td>
                    <td style="padding:12px 18px;text-align:right;white-space:nowrap;">
                        <a href="{{ route('app.screen', ['screen' => 'payroll-review', 'tab' => 'individual', 'run' => $r->id]) }}" style="color:var(--red);font-size:12px;text-decoration:none;margin-right:10px;" x-text="$store.ui.lang==='en' ? 'Review' : 'Semak'">Review</a>
                        <a href="{{ route('app.screen', ['screen' => 'payroll-payment', 'tab' => 'payout', 'run' => $r->id]) }}" style="color:var(--red);font-size:12px;text-decoration:none;" x-text="$store.ui.lang==='en' ? 'Payment' : 'Bayaran'">Payment</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" style="padding:20px 18px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'No payroll runs yet. Create one to begin.' : 'Belum ada run gaji. Buat satu untuk mula.'">No payroll runs yet. Create one to begin.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
</div>
