{{-- One LHDN staff form from StaffFormData::build(): $data, $title, $year, and $editable
     (screen only; the batch PDF passes false). Plain tables and inline styles so DomPDF
     draws it the same as the browser. --}}
@php
    $editable ??= false;
    $mono = 'font-family:DejaVu Sans Mono,ui-monospace,monospace;';
    $rm = fn ($v) => number_format((float) $v, 2);
@endphp
<div style="border:1px solid #d6d6d6;border-radius:6px;padding:14px 16px;background:#fff;color:#1a1a1a;">
    <table style="width:100%;border-collapse:collapse;margin-bottom:6px;">
        <tr>
            <td style="font-size:14px;font-weight:bold;">{{ $title }}</td>
            <td style="text-align:right;font-size:12px;color:#555;">{{ $year }}</td>
        </tr>
    </table>
    @foreach ($data['sections'] as $section)
        @if ($section['title'] !== '')
            <div style="font-size:11.5px;font-weight:bold;background:#eef1f4;padding:5px 8px;margin:12px 0 4px;">{{ $section['title'] }}</div>
        @endif
        <table style="width:100%;border-collapse:collapse;font-size:12px;">
            @foreach ($section['fields'] as $f)
                <tr style="border-bottom:1px solid #eee;">
                    <td style="width:34px;padding:5px 4px;color:#777;vertical-align:top;">{{ $f['no'] }}</td>
                    <td style="width:46%;padding:5px 6px;color:#444;vertical-align:top;">{{ $f['label'] }}</td>
                    <td style="padding:5px 6px;vertical-align:top;" data-field="{{ $f['key'] }}">
                        <span @if ($editable) x-show="!editing" @endif>
                            @if ($f['value'] === null)
                                <span style="color:#aaa;">-</span>
                            @elseif ($f['kind'] === 'boxes')
                                <table style="border-collapse:collapse;display:inline-table;"><tr>
                                    @foreach (mb_str_split($f['value']) as $ch)
                                        <td style="border:1px solid #999;width:14px;height:16px;text-align:center;font-size:11px;padding:0;{{ $mono }}">{{ $ch }}</td>
                                    @endforeach
                                </tr></table>
                            @elseif ($f['kind'] === 'money')
                                <span style="{{ $mono }}">{{ $rm($f['value']) }}</span>
                            @else
                                <span style="white-space:pre-line;">{{ $f['value'] }}</span>
                            @endif
                        </span>
                        @if ($editable)
                            <span x-show="editing" x-cloak>
                                @if (($f['options'] ?? []) !== [])
                                    <select name="fields[{{ $f['key'] }}]" style="border:1px solid var(--hairline);border-radius:8px;padding:0 8px;background:#fff;width:100%;height:30px;font-size:12px;">
                                        <option value="">-</option>
                                        @foreach ($f['options'] as $opt)
                                            <option value="{{ $opt }}" @selected($f['value'] === $opt)>{{ $opt }}</option>
                                        @endforeach
                                    </select>
                                @elseif ($f['kind'] === 'area')
                                    <textarea name="fields[{{ $f['key'] }}]" rows="3" style="border:1px solid var(--hairline);border-radius:8px;padding:6px 8px;background:#fff;width:100%;font-size:12px;">{{ $f['value'] }}</textarea>
                                @else
                                    <input name="fields[{{ $f['key'] }}]" value="{{ $f['value'] }}" style="border:1px solid var(--hairline);border-radius:8px;padding:0 8px;background:#fff;width:100%;height:30px;font-size:12px;" @if ($f['kind'] === 'money') inputmode="decimal" @endif>
                                @endif
                            </span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    @endforeach

    @isset($data['pcb'])
        <div style="font-size:11.5px;font-weight:bold;background:#eef1f4;padding:5px 8px;margin:12px 0 4px;">Butir-butir potongan cukai</div>
        <table style="width:100%;border-collapse:collapse;font-size:12px;" data-testid="pcb2-table">
            <tr style="color:#555;border-bottom:1px solid #ccc;">
                <th style="text-align:left;padding:5px 6px;font-weight:normal;">Bulan</th>
                <th style="text-align:right;padding:5px 6px;font-weight:normal;">PCB (RM)</th>
                <th style="text-align:right;padding:5px 6px;font-weight:normal;">CP38 (RM)</th>
                <th style="text-align:left;padding:5px 6px;font-weight:normal;">No. resit</th>
                <th style="text-align:left;padding:5px 6px;font-weight:normal;">Tarikh resit</th>
            </tr>
            @foreach ($data['pcb']['rows'] as $r)
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:4px 6px;">{{ $r['month'] }}</td>
                    <td style="padding:4px 6px;text-align:right;{{ $mono }}">{{ $r['pcb'] > 0 ? $rm($r['pcb']) : '-' }}</td>
                    <td style="padding:4px 6px;text-align:right;{{ $mono }}">{{ $r['cp38'] > 0 ? $rm($r['cp38']) : '-' }}</td>
                    <td style="padding:4px 6px;">{{ $r['receipt'] ?? '' }}</td>
                    <td style="padding:4px 6px;">{{ $r['receipt_on'] ?? '' }}</td>
                </tr>
            @endforeach
            <tr style="border-top:2px solid #ccc;font-weight:bold;">
                <td style="padding:5px 6px;">Jumlah</td>
                <td style="padding:5px 6px;text-align:right;{{ $mono }}">{{ $rm($data['pcb']['pcb_total']) }}</td>
                <td style="padding:5px 6px;text-align:right;{{ $mono }}">{{ $rm($data['pcb']['cp38_total']) }}</td>
                <td colspan="2"></td>
            </tr>
        </table>
    @endisset
</div>
