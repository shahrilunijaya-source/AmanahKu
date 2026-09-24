{{-- Borang Tuntutan Perjalanan: one person's claims for a month, in the layout of
     Unijaya's paper travel claim form. A mileage claim fills the whole row; any other
     type prints date, purpose (the title) and total only. Signature boxes are left
     blank for wet signatures.

     Params: $employee, $claims (Collection<Claim>), $month (Carbon), $rates. --}}
@php
    $rm = fn ($v) => $v === null ? '' : number_format((float) $v, 2);
    $grand = $claims->sum('amount');
    // The company logo from Settings, as the payslip prints it. Left off when none is uploaded.
    $logoPath = $employee->tenant?->logo_path ? \Illuminate\Support\Facades\Storage::disk('public')->path($employee->tenant->logo_path) : null;
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 22px 26px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #000; }
    .logo { max-height: 50px; max-width: 170px; margin-bottom: 6px; }
    h1 { text-align: center; font-size: 15px; margin: 0 0 14px; letter-spacing: .5px; }
    .meta { width: 100%; margin-bottom: 8px; font-size: 10.5px; }
    .meta td { padding: 3px 0; }
    .meta b { display: inline-block; min-width: 90px; }
    .line { border-bottom: 1px solid #000; display: inline-block; min-width: 260px; padding: 0 4px; }
    .rates { font-size: 10px; margin: 6px 0 4px; }
    table.grid { width: 100%; border-collapse: collapse; }
    .grid th { background: #ffffcc; border: 1px solid #000; padding: 5px 4px; font-size: 9px; text-align: center; }
    .grid td { border: 1px solid #000; padding: 4px; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .c { text-align: center; white-space: nowrap; }
    .tot td { font-weight: bold; }
    .grand td { font-weight: bold; border-bottom: 3px double #000; }
    table.sign { width: 100%; margin-top: 26px; border-collapse: collapse; font-size: 10px; }
    .sign td { width: 25%; padding: 0 10px; text-align: center; vertical-align: bottom; }
    .sign .gap { height: 46px; }
    .sign .who { border-top: 1px solid #000; padding-top: 4px; font-weight: bold; }
</style>
</head>
<body>
    @if ($logoPath && file_exists($logoPath))
        <img class="logo" src="{{ $logoPath }}" alt="logo">
    @endif
    <h1>BORANG TUNTUTAN PERJALANAN</h1>

    <table class="meta">
        <tr>
            <td><b>NAMA :</b> <span class="line">{{ $employee->name }}</span></td>
            <td><b>JAB :</b> <span class="line">{{ $employee->department?->name }}</span></td>
        </tr>
        <tr>
            <td><b>NO PEKERJA :</b> <span class="line">{{ $employee->staff_id }}</span></td>
            <td><b>BULAN :</b> <span class="line">{{ $month->format('m/Y') }}</span></td>
        </tr>
    </table>

    <div class="rates">Kereta : RM{{ number_format($rates['car'], 2) }}/KM &nbsp;&nbsp; Motosikal : RM{{ number_format($rates['motorcycle'], 2) }}/KM</div>

    <table class="grid">
        <thead>
            <tr>
                <th style="width:9%;">TARIKH</th>
                <th style="width:13%;">DARI</th>
                <th style="width:13%;">KE</th>
                <th>TUJUAN</th>
                <th style="width:7%;">KM PERJALANAN</th>
                <th style="width:8%;">KADAR JARAK TEMPUH</th>
                <th style="width:8%;">JUMLAH (RM)</th>
                <th style="width:7%;">TOL (RM)</th>
                <th style="width:7%;">PARKING (RM)</th>
                <th style="width:8%;">TOTAL (RM)</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($claims as $claim)
                @php
                    $rate = $claim->vehicle ? ($rates[$claim->vehicle] ?? null) : null;
                    $hasTrip = $claim->distance_km !== null && $rate !== null;
                @endphp
                <tr>
                    <td class="c">{{ $claim->date->format('d/m/Y') }}</td>
                    <td>{{ $claim->trip_from }}</td>
                    <td>{{ $claim->trip_to }}</td>
                    <td>{{ $claim->title }}@if ($claim->type !== 'mileage') ({{ ucfirst($claim->type) }})@endif</td>
                    <td class="num">{{ $hasTrip ? rtrim(rtrim(number_format($claim->distance_km, 1), '0'), '.') : '' }}</td>
                    <td class="num">{{ $hasTrip ? number_format($rate, 2) : '' }}</td>
                    {{-- What was paid for the distance, so the row still adds up if the rate changes later. --}}
                    <td class="num">{{ $hasTrip ? $rm($claim->amount - ($claim->toll ?? 0) - ($claim->parking ?? 0)) : '' }}</td>
                    <td class="num">{{ $claim->toll ? $rm($claim->toll) : '' }}</td>
                    <td class="num">{{ $claim->parking ? $rm($claim->parking) : '' }}</td>
                    <td class="num">{{ $rm($claim->amount) }}</td>
                </tr>
            @empty
                <tr><td colspan="10" class="c" style="padding:14px;">Tiada tuntutan untuk bulan ini.</td></tr>
            @endforelse
            <tr class="tot">
                <td colspan="8" style="border:0;"></td>
                <td class="c">SUB TOTAL</td>
                <td class="num">{{ $rm($grand) }}</td>
            </tr>
            <tr class="grand">
                <td colspan="8" style="border:0;"></td>
                <td class="c">GRAND TOTAL</td>
                <td class="num">RM {{ $rm($grand) }}</td>
            </tr>
        </tbody>
    </table>

    <table class="sign">
        <tr>
            <td></td>
            <td>Disemak oleh</td>
            <td>Dilulus oleh</td>
            <td>Diproses oleh</td>
        </tr>
        <tr><td class="gap" colspan="4"></td></tr>
        <tr>
            <td><div class="who">PEKERJA</div></td>
            <td><div class="who">PENYELIA</div></td>
            <td><div class="who">KETUA JABATAN</div></td>
            <td><div class="who">Jabatan Akaun / HR</div></td>
        </tr>
    </table>
</body>
</html>
