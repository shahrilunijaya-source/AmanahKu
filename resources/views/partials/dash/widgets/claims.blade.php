<div class="uj-dw-body uj-dw-flush">
    @if (empty($w['rows']))
        <p class="uj-dw-empty" x-text="$store.ui.lang==='en'
            ? 'No claims filed this year.'
            : 'Tiada tuntutan tahun ini.'">No claims filed this year.</p>
    @else
        <table class="uj-dw-tbl">
            <thead><tr>
                <th x-text="$store.ui.lang==='en' ? 'Type' : 'Jenis'">Type</th>
                <th class="r" x-text="$store.ui.lang==='en' ? 'Claimed' : 'Dituntut'">Claimed</th>
                <th class="r" x-text="$store.ui.lang==='en' ? 'Status' : 'Status'">Status</th>
            </tr></thead>
            <tbody>
                @foreach ($w['rows'] as $r)
                    <tr>
                        <td>{{ $r['type'] }}</td>
                        <td class="r"><span class="num">RM {{ number_format($r['amount'], 2) }}</span></td>
                        <td class="r"><span class="uj-dw-pill" data-k="{{ $r['status'] === 'Pending' ? 'warn' : 'ok' }}">{{ $r['status'] }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
<div class="uj-dw-total">
    <span class="l" x-text="$store.ui.lang==='en' ? 'Awaiting payout' : 'Menunggu bayaran'">Awaiting payout</span>
    <span class="v">RM {{ number_format($w['awaiting'] ?? 0, 2) }}</span>
</div>
