<div class="uj-dw-body uj-dw-flush">
    @if (empty($w['rows']))
        <p class="uj-dw-empty" x-text="$store.ui.lang==='en'
            ? 'Nothing logged this month.'
            : 'Tiada rekod bulan ini.'">Nothing logged this month.</p>
    @else
        <table class="uj-dw-tbl">
            <thead><tr>
                <th x-text="$store.ui.lang==='en' ? 'Date' : 'Tarikh'">Date</th>
                <th x-text="$store.ui.lang==='en' ? 'Shift' : 'Syif'">Shift</th>
                <th class="r" x-text="$store.ui.lang==='en' ? 'In' : 'Masuk'">In</th>
                <th class="r" x-text="$store.ui.lang==='en' ? 'Out' : 'Keluar'">Out</th>
            </tr></thead>
            <tbody>
                @foreach ($w['rows'] as $r)
                    <tr>
                        <td><span class="day">{{ $r['day'] }}</span><span class="dnum">{{ $r['date'] }}</span></td>
                        <td>{{ $r['shift'] }}</td>
                        <td class="r"><span class="num">{{ $r['in'] }}</span></td>
                        <td class="r"><span class="num">{{ $r['out'] }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
