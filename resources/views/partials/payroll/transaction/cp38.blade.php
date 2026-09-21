{{-- Spec F9: read-only list of every CP38 direction. Notices are recorded and edited on
     the staff profile (Bank & Statutory tab); the balance moves only on a finalized run. --}}
@php
    $L = fn ($en, $ms) => '<span x-text="'.e("\$store.ui.lang==='en' ? ".json_encode($en).' : '.json_encode($ms)).'">'.e($en).'</span>';
    $cp38Notices = \App\Models\PayrollCp38Notice::with('employee:id,name')->orderByRaw("status = 'active' desc")->orderByDesc('notice_date')->get();
    $th = 'text-align:left;font-size:11px;color:var(--muted);text-transform:uppercase;padding:10px 12px;';
    $td = 'padding:10px 12px;font-size:13px;color:var(--ink);border-top:1px solid var(--hairline-soft);';
@endphp
<div class="uj-card" style="max-width:980px;">
    <div class="uj-card-head" style="padding:16px 22px;">
        <h3 class="uj-card-title">CP38</h3>
        <p style="font-size:12px;color:var(--muted);margin:2px 0 0;">{!! $L('Extra monthly tax instalments ordered by LHDN. Each pay run deducts the instalment by itself. Record or change a notice on the staff member\'s profile, Bank & Statutory tab.', 'Ansuran cukai tambahan bulanan yang diarahkan LHDN. Setiap larian gaji memotong ansuran itu sendiri. Rekod atau ubah notis pada profil staf, tab Bank & Statutori.') !!}</p>
    </div>
    @if ($cp38Notices->isEmpty())
        <p style="padding:18px 22px;font-size:13px;color:var(--muted);margin:0;">{!! $L('No CP38 notices recorded.', 'Tiada notis CP38 direkodkan.') !!}</p>
    @else
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <thead><tr>
                    <th style="{{ $th }}">{!! $L('Employee', 'Pekerja') !!}</th><th style="{{ $th }}">{!! $L('Reference', 'Rujukan') !!}</th>
                    <th style="{{ $th }}">{!! $L('Period', 'Tempoh') !!}</th><th style="{{ $th }}text-align:right;">{!! $L('Monthly', 'Bulanan') !!}</th>
                    <th style="{{ $th }}text-align:right;">{!! $L('Balance', 'Baki') !!}</th><th style="{{ $th }}">Status</th><th style="{{ $th }}"></th>
                </tr></thead>
                <tbody>
                    @foreach ($cp38Notices as $n)
                        <tr>
                            <td style="{{ $td }}">{{ $n->employee?->name }}</td>
                            <td style="{{ $td }}">{{ $n->reference }}</td>
                            <td style="{{ $td }}">{{ $n->first_period }} – {{ $n->last_period }}</td>
                            <td style="{{ $td }}text-align:right;font-family:var(--font-mono);">{{ number_format((float) $n->monthly_instalment, 2) }}</td>
                            <td style="{{ $td }}text-align:right;font-family:var(--font-mono);">{{ number_format((float) $n->remaining_balance, 2) }}</td>
                            <td style="{{ $td }}">{{ ucfirst((string) $n->status) }}</td>
                            <td style="{{ $td }}text-align:right;"><a href="{{ route('app.screen', 'profile') }}?emp={{ $n->employee_id }}&tab=bank" style="color:var(--red);font-size:12px;">{!! $L('Open profile', 'Buka profil') !!}</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
