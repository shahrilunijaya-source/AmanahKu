{{-- Spec F2 readiness panel: every currently employed person, red mark per missing item.
     Expects $readinessEmployer (list<string>), $readinessCompanyWarnings (list<string>), $readinessRows, $readinessBlockingCount. --}}
@php
    $gapRows = array_filter($readinessRows, fn ($r) => $r['blocking'] !== [] || $r['warnings'] !== []);
@endphp
<div class="uj-card" style="padding:18px 20px;margin-bottom:16px;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Readiness' : 'Kesediaan'">Readiness</h3>
        @if ($readinessBlockingCount === 0)
            <span class="uj-pill" style="background:#eef9f1;color:var(--success);" x-text="$store.ui.lang==='en' ? 'Everyone is ready' : 'Semua sedia'">Everyone is ready</span>
        @else
            <span class="uj-pill" style="background:var(--red-tint);color:var(--error);"><span x-text="$store.ui.lang==='en' ? 'Not ready' : 'Belum sedia'">Not ready</span> · {{ $readinessBlockingCount }}</span>
        @endif
    </div>
    @error('readiness')<div style="margin-top:10px;background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;">{{ $message }}</div>@enderror
    @if ($readinessEmployer)
        <div style="margin-top:10px;font-size:12.5px;"><b x-text="$store.ui.lang==='en' ? 'Company' : 'Syarikat'">Company</b>: <span style="color:var(--error);">{{ implode(', ', $readinessEmployer) }}</span> · <a href="{{ route('app.screen', ['screen' => 'settings']) }}" style="color:var(--red);" x-text="$store.ui.lang==='en' ? 'Fix in Settings' : 'Betulkan di Tetapan'">Fix in Settings</a></div>
    @endif
    @foreach ($readinessCompanyWarnings as $w)
        @php preg_match('/\d+/', $w, $m); $n = $m[0] ?? '0'; @endphp
        <div style="margin-top:10px;background:#fff7e6;border:1px solid var(--amber);color:var(--amber);font-size:12.5px;border-radius:8px;padding:8px 11px;"
             x-text="$store.ui.lang==='en' ? @js($w) : @js('Levi HRD Corp dimatikan tetapi syarikat mempunyai '.$n.' pekerja warganegara Malaysia; pendaftaran adalah wajib pada 10.')">{{ $w }}</div>
    @endforeach
    @if ($gapRows)
        <table style="width:100%;border-collapse:collapse;font-size:12.5px;margin-top:10px;">
            @foreach ($gapRows as $r)
                <tr style="border-top:1px solid var(--hairline-soft);">
                    <td style="padding:8px 0;font-weight:500;color:var(--ink);white-space:nowrap;"><a href="{{ route('app.screen', ['screen' => 'profile', 'emp' => $r['employee']->id]) }}" style="color:inherit;text-decoration:none;">{{ $r['employee']->name }}</a></td>
                    <td style="padding:8px;">
                        @foreach ($r['blocking'] as $g)<span class="uj-pill" style="background:var(--red-tint);color:var(--error);margin-right:4px;">{{ $g }}</span>@endforeach
                        @foreach ($r['warnings'] as $g)<span class="uj-pill" style="background:#fff7e6;color:var(--amber);margin-right:4px;">{{ $g }} · <span x-text="$store.ui.lang==='en' ? 'warning' : 'amaran'">warning</span></span>@endforeach
                    </td>
                    <td style="padding:8px 0;text-align:right;white-space:nowrap;">
                        @if ($r['blocking'] !== [])
                            <label style="font-size:12px;color:var(--muted);cursor:pointer;"><input type="checkbox" form="create-run-form" name="exclude_employee_ids[]" value="{{ $r['employee']->id }}" @checked(in_array($r['employee']->id, (array) old('exclude_employee_ids', []))) @change="excluded += $event.target.checked ? 1 : -1" /> <span x-text="$store.ui.lang==='en' ? 'Leave out of this run' : 'Kecualikan dari run ini'">Leave out of this run</span></label>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    @endif
</div>
