<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Support\Csv;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The attendance ledger as a CSV Excel opens on a double-click.
 *
 * Deliberately not .xlsx: that needs a spreadsheet dependency, and the rest of
 * this app (employees, payroll) already exports plain CSV through App\Support\Csv.
 * The trade is no bold header and no second sheet — see the design doc.
 */
class AttendanceReportExportController extends Controller
{
    /** @var list<string> */
    private const HEADER = ['Date', 'Staff', 'Department', 'Clock in', 'Clock out', 'Hours', 'Status', 'Flags'];

    private const STATUS_LABEL = [
        'ontime' => 'On time', 'late' => 'Late', 'miss' => 'Missing clock-out',
        'absent' => 'No punch', 'leave' => 'On leave', 'half' => 'Half day',
        'pending' => 'Pending',
    ];

    private const FLAG_LABEL = [
        'off' => 'Off-site', 'visit' => 'Site visit', 'short' => 'Short hours',
        'early' => 'Left early', 'noloc' => 'No location', 'amended' => 'Clock-out amended',
    ];

    public function download(Request $request): StreamedResponse
    {
        // Exactly the screen's own gate (AppController::screen), not a narrower one:
        // canSeeAll also admits an employee-role user with a direct report, and a
        // visible Export button that 403s is worse than no button.
        abort_unless(
            (bool) $request->user()?->isSuperAdmin()
                || Permissions::canSeeAll(
                    $request->attributes->get('employee'),
                    (string) $request->attributes->get('tenantRole'),
                ),
            403
        );

        // Re-uses the screen's own payload, so the file can never disagree with
        // the table the user was looking at when they pressed the button.
        $data = app(AttendanceReportController::class)->screenData($request);
        $rows = $data['rows'];

        AuditLog::record(
            'Exported attendance report',
            $data['rangeLabel']['en'].' · '.$rows->count().' rows'
        );

        $filename = $data['from'] === $data['to']
            ? 'attendance-'.$data['from'].'.csv'
            : 'attendance-'.$data['from'].'-to-'.$data['to'].'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            // BOM so Excel reads the UTF-8 names correctly rather than as mojibake.
            fwrite($out, "\xEF\xBB\xBF");
            // Escape passed explicitly: PHP 8.5 deprecates the implicit default and
            // flips it in 9.0. '' is the RFC-4180 behaviour — no backslash escaping —
            // which is also what a spreadsheet actually expects to read back.
            fputcsv($out, self::HEADER, ',', '"', '');

            foreach ($rows as $row) {
                $flags = array_map(fn (string $f) => self::FLAG_LABEL[$f] ?? $f, $row['flags']);
                if ($row['leaveType']) {
                    $flags[] = $row['leaveType'];
                }

                // Staff names are user-controlled — neutralise formula injection (CWE-1236).
                fputcsv($out, Csv::safeRow([
                    $row['date'],
                    $row['name'],
                    $row['dept'] ?? '',
                    $row['in'] ?? '',
                    $row['out'] ?? '',
                    $row['hours'] !== null ? number_format($row['hours'], 2, '.', '') : '',
                    self::STATUS_LABEL[$row['status']] ?? $row['status'],
                    implode('; ', $flags),
                ]), ',', '"', '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
