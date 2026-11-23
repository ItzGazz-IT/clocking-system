<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\WeeklyReportService;

final class ReportController extends Controller
{
    public function weekly(Request $request): Response
    {
        $user = Auth::user();
        $employeeId = $this->employeeId($request);
        $report = (new WeeklyReportService())->report($user->companyId, $this->week($request), $employeeId);

        return $this->view('reports/weekly', [
            'user' => $user,
            'report' => $report,
            'week' => $report['start'],
            'employees' => $this->employees($user->companyId),
            'selectedEmployeeId' => $employeeId,
        ]);
    }

    public function weeklyCsv(Request $request): Response
    {
        $user = Auth::user();
        $employeeId = $this->employeeId($request);
        $report = (new WeeklyReportService())->report($user->companyId, $this->week($request), $employeeId);
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['Weekly Attendance Report', $report['start'] . ' to ' . $report['end']]);
        fputcsv($handle, []);
        foreach ($report['employee_groups'] as $employee) {
            $inactiveLabel = ($employee['attendance_mode'] ?? 'fixed') === 'flexible' ? 'Inactive Time' : 'Lunch Time';
            fputcsv($handle, ['Employee No', $employee['employee_number'], 'Employee', $employee['employee_name'], 'Department', $employee['department_name'] ?? '']);
            fputcsv($handle, ['Day', 'Date', 'Clock In', 'Clock Out', $inactiveLabel, 'Work Time', 'Overtime', 'Session Times', 'Sessions', 'Status']);
            foreach ($employee['days'] as $day) {
                fputcsv($handle, [
                    $day['day_name'],
                    $day['work_date'],
                    $day['first_in'] ? local_datetime($day['first_in'], 'H:i') : '',
                    $day['last_out'] ? local_datetime($day['last_out'], 'H:i') : '',
                    format_minutes($day['break_minutes']),
                    format_minutes($day['work_minutes']),
                    format_minutes($day['overtime_minutes']),
                    $day['session_summary'],
                    count($day['sessions']),
                    $day['status'],
                ]);
            }
            fputcsv($handle, [
                'Employee total',
                '',
                '',
                '',
                format_minutes($employee['totals']['break']),
                format_minutes($employee['totals']['work']),
                format_minutes($employee['totals']['overtime']),
                '',
                $employee['totals']['sessions'],
                '',
            ]);
            fputcsv($handle, []);
        }
        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="weekly-attendance-' . $report['start'] . $this->fileSuffix($employeeId) . '.csv"',
        ]);
    }

    public function weeklyPdf(Request $request): Response
    {
        $user = Auth::user();
        $employeeId = $this->employeeId($request);
        $report = (new WeeklyReportService())->report($user->companyId, $this->week($request), $employeeId);
        $companyStatement = Database::connection()->prepare('SELECT name FROM companies WHERE id = :id');
        $companyStatement->execute(['id' => $user->companyId]);
        $companyName = (string) ($companyStatement->fetchColumn() ?: config('app.name'));
        $pdf = $this->styledPdf($report, $companyName);

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="weekly-attendance-' . $report['start'] . $this->fileSuffix($employeeId) . '.pdf"',
        ]);
    }

    private function week(Request $request): ?string
    {
        $week = trim((string) $request->input('week', ''));
        return $week === '' ? null : $week;
    }

    private function employeeId(Request $request): ?int
    {
        $employeeId = filter_var($request->input('employee_id'), FILTER_VALIDATE_INT);
        return $employeeId === false ? null : (int) $employeeId;
    }

    /** @return list<array<string, mixed>> */
    private function employees(int $companyId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT id, employee_number, first_name, last_name
             FROM employees
             WHERE company_id = :company_id AND deleted_at IS NULL
             ORDER BY first_name, last_name'
        );
        $statement->execute(['company_id' => $companyId]);
        return $statement->fetchAll();
    }

    private function fileSuffix(?int $employeeId): string
    {
        return $employeeId === null ? '' : '-employee-' . $employeeId;
    }

    /** @param array{start:string,end:string,rows:list<array<string,mixed>>,employee_groups:list<array<string,mixed>>,totals:array<string,int>} $report */
    private function styledPdf(array $report, string $companyName): string
    {
        $groups = $report['employee_groups'] ?: [[
            'employee_name' => 'No attendance recorded', 'employee_number' => '—',
            'department_name' => null, 'days' => [],
            'totals' => ['work' => 0, 'break' => 0, 'overtime' => 0, 'sessions' => 0],
        ]];
        $pages = [];
        $pageCount = count($groups);
        foreach ($groups as $pageIndex => $employee) {
            $inactiveLabel = ($employee['attendance_mode'] ?? 'fixed') === 'flexible' ? 'Inactive' : 'Lunch';
            $c = [];
            $this->pdfRect($c, 0, 772, 595, 70, [.012, .067, .149]);
            $this->pdfRect($c, 0, 766, 595, 6, [.098, .663, .961]);
            $this->pdfText($c, 38, 814, 9, strtoupper($companyName), true, [1, 1, 1]);
            $this->pdfText($c, 38, 790, 21, 'Weekly attendance report', true, [1, 1, 1]);
            $this->pdfText($c, 557, 793, 9, $report['start'] . ' to ' . $report['end'], false, [.78, .89, 1], 'right');
            $this->pdfText($c, 38, 758, 7, 'TIME & ATTENDANCE', true, [.031, .42, .847]);

            $this->pdfText($c, 38, 742, 15, (string) $employee['employee_name'], true, [.012, .067, .149]);
            $identity = 'Employee ' . (string) $employee['employee_number'];
            if (!empty($employee['department_name'])) $identity .= '  |  ' . (string) $employee['department_name'];
            $this->pdfText($c, 38, 724, 9, $identity, false, [.40, .46, .55]);

            $cards = [
                ['Worked', format_minutes((int) $employee['totals']['work'])],
                [$inactiveLabel, format_minutes((int) $employee['totals']['break'])],
                ['Overtime', format_minutes((int) $employee['totals']['overtime'])],
                ['Sessions', (string) $employee['totals']['sessions']],
            ];
            foreach ($cards as $i => [$label, $value]) {
                $x = 38 + ($i * 132);
                $this->pdfRect($c, $x, 657, 120, 52, [.941, .965, 1]);
                $this->pdfText($c, $x + 12, 691, 7, strtoupper($label), true, [.40, .46, .55]);
                $this->pdfText($c, $x + 12, 670, 14, $value, true, [.012, .067, .149]);
            }

            $columns = [['DAY',38],['DATE',106],['IN',190],['OUT',234],[strtoupper($inactiveLabel),278],['WORKED',338],['OT',408],['STATUS',465]];
            $this->pdfRect($c, 38, 615, 519, 26, [.012, .067, .149]);
            foreach ($columns as [$label, $x]) $this->pdfText($c, $x + 7, 625, 7, $label, true, [1,1,1]);
            $rowY = 583;
            foreach ($employee['days'] as $index => $day) {
                $this->pdfRect($c, 38, $rowY, 519, 30, $index % 2 === 0 ? [.980,.988,1] : [.941,.965,1]);
                $values = [
                    [(string) $day['day_name'],38], [(string) $day['work_date'],106],
                    [$day['first_in'] ? local_datetime($day['first_in'], 'H:i') : '—',190],
                    [$day['last_out'] ? local_datetime($day['last_out'], 'H:i') : '—',234],
                    [format_minutes((int) $day['break_minutes']),278], [format_minutes((int) $day['work_minutes']),338],
                    [format_minutes((int) $day['overtime_minutes']),408], [ucfirst((string) $day['status']),465],
                ];
                foreach ($values as [$value, $x]) $this->pdfText($c, $x + 7, $rowY + 11, 8, $value, $x === 38, [.15,.20,.29]);
                $rowY -= 32;
            }
            if ($employee['days'] === []) $this->pdfText($c, 297, 570, 11, 'No attendance was recorded for this week.', false, [.40,.46,.55], 'center');

            $this->pdfText($c, 38, 104, 8, 'Worked includes overtime. OT is recorded time above the daily target, before approval.', false, [.40,.46,.55]);
            $this->pdfText($c, 38, 91, 8, 'Automatic attendance: inactive time includes gaps without agent reports.', false, [.40,.46,.55]);
            $this->pdfRect($c, 38, 78, 519, 1, [.85,.89,.95]);
            $this->pdfText($c, 38, 57, 8, 'Generated ' . date('d M Y H:i') . '  |  Time & Attendance Tracking', false, [.40,.46,.55]);
            $this->pdfText($c, 557, 57, 8, 'Page ' . ($pageIndex + 1) . ' of ' . $pageCount, false, [.40,.46,.55], 'right');
            $pages[] = implode("\n", $c) . "\n";
        }
        return $this->pdfDocument($pages);
    }

    /** @param list<string> $pages */
    private function pdfDocument(array $pages): string
    {
        $kids = []; $objects = [];
        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
        $objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";
        $next = 5;
        foreach ($pages as $content) {
            $pageId = $next++; $contentId = $next++; $kids[] = $pageId . ' 0 R';
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$contentId} 0 R >>";
            $objects[$contentId] = "<< /Length " . strlen($content) . " >>\nstream\n{$content}endstream";
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $size = max(array_keys($objects)) + 1;
        $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";
        for ($i = 1; $i < $size; $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        return $pdf . "trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    private function pdfText(array &$commands, float $x, float $y, float $size, string $text, bool $bold = false, array $rgb = [0,0,0], string $align = 'left'): void
    {
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', $text) ?: $text;
        $encoded = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $encoded);
        $estimatedWidth = strlen($encoded) * $size * .5;
        if ($align === 'right') $x -= $estimatedWidth;
        if ($align === 'center') $x -= $estimatedWidth / 2;
        $commands[] = sprintf('BT /%s %.1f Tf %.3f %.3f %.3f rg %.1f %.1f Td (%s) Tj ET', $bold ? 'F2' : 'F1', $size, $rgb[0], $rgb[1], $rgb[2], $x, $y, $encoded);
    }

    private function pdfRect(array &$commands, float $x, float $y, float $width, float $height, array $rgb): void
    {
        $commands[] = sprintf('%.3f %.3f %.3f rg %.1f %.1f %.1f %.1f re f', $rgb[0], $rgb[1], $rgb[2], $x, $y, $width, $height);
    }
}
