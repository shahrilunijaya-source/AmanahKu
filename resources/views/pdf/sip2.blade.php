<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { size: A4 landscape; margin: 20px 24px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111; }
    table { width: 100%; border-collapse: collapse; }
    .grid th, .grid td { border: 1px solid #555; padding: 4px; vertical-align: top; }
    .grid th { font-weight: normal; background: #f0f0f0; }
    .bar { background: #1f3fbf; color: #fff; font-weight: bold; padding: 4px 8px; margin: 8px 0 0; font-size: 10px; }
    .mono { font-family: DejaVu Sans Mono, monospace; }
    .page { page-break-after: always; }
    .page:last-child { page-break-after: auto; }
</style>
</head>
<body>
@foreach ($pages as $rows)
    <div class="page">
        <table>
            <tr>
                <td style="width:60%;"><b style="font-size:12px;">BORANG SIP 2</b><br>BORANG PENDAFTARAN PEKERJA<br>
                    <span style="font-size:8px;">PERATURAN-PERATURAN SISTEM INSURANS PEKERJAAN (PENDAFTARAN DAN CARUMAN) 2017 (Peraturan 4)</span></td>
                <td style="text-align:right;">NO KOD MAJIKAN / MyCoID<br><b class="mono" style="font-size:12px;">{{ $tenant->socso_employer_code ?: '-' }}</b></td>
            </tr>
        </table>
        <div class="bar">A. BUTIRAN PEKERJA</div>
        <table class="grid">
            <tr>
                <th style="width:7%;">Jenis Kad Pengenalan<br>(1)</th>
                <th style="width:14%;">No. Kad Pengenalan (2)<br>Tarikh Lahir (3)</th>
                <th>Nama Pekerja (seperti dalam Kad Pengenalan)<br>(4)</th>
                <th style="width:5%;">Jantina (L/P)<br>(5)</th>
                <th style="width:7%;">Bangsa<br>(6)</th>
                <th style="width:9%;">Tarikh Mula Kerja<br>(7)</th>
                <th style="width:14%;">Pekerjaan<br>(8)</th>
                <th style="width:9%;">Bergaji melebihi RM4,000.00 sebulan (/)<br>(9)</th>
            </tr>
            @foreach ($rows as $e)
                <tr>
                    <td>{{ $e->nric ? 'KP Baru' : ($e->passport_no ? 'Pasport' : '') }}</td>
                    <td class="mono">{{ preg_replace('/\D/', '', (string) $e->nric) ?: $e->passport_no }}<br>{{ $e->date_of_birth?->format('d/m/Y') }}</td>
                    <td>{{ mb_strtoupper((string) ($e->full_name_ic ?: $e->name)) }}</td>
                    <td style="text-align:center;">{{ ['male' => 'L', 'female' => 'P'][$e->gender] ?? '' }}</td>
                    <td>{{ $e->race }}</td>
                    <td class="mono">{{ $e->joined_at?->format('d/m/Y') }}</td>
                    <td>{{ $e->position }}</td>
                    <td style="text-align:center;">{{ (float) $e->salary > 4000 ? '/' : '' }}</td>
                </tr>
            @endforeach
            @for ($i = count($rows); $i < 10; $i++)
                <tr><td style="height:18px;"></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
            @endfor
        </table>
        <div class="bar">B. PENGESAHAN MAJIKAN / WAKIL MAJIKAN</div>
        <p>Saya mengesahkan bahawa tiada seorang pun pekerja perusahaan ini sebagaimana yang dinyatakan dalam seksyen 16 Akta telah tertinggal daripada senarai di atas.</p>
        <table>
            <tr><td style="width:50%;">Tandatangan: ______________________________</td><td>Tarikh: {{ now()->format('d/m/Y') }}</td></tr>
            <tr><td>Nama Majikan/Nama Wakil Majikan: {{ $tenant->statutorySignatory?->name }}</td><td>Jawatan: {{ $tenant->statutorySignatory?->position }}</td></tr>
            <tr><td>Nama Perusahaan: {{ $tenant->name }}</td><td>E-mel: {{ $tenant->email }}</td></tr>
            <tr><td>No. Telefon Pejabat/No. Telefon Bimbit: {{ $tenant->payroll_contact_phone ?: $tenant->contact_number }}</td><td></td></tr>
        </table>
        <p style="text-align:center;font-size:8px;margin-top:10px;">Tandatangan tidak diperlukan sekiranya borang ini dihantar melalui medium elektronik tertakluk kepada pengesahan oleh PERKESO</p>
    </div>
@endforeach
</body>
</html>
