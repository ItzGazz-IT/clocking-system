<?php

declare(strict_types=1);

namespace App\Services;

final class EmployeeActivityPdfService
{
    /** @param array<string,mixed> $report */
    public function render(array $report): string
    {
        $pages = [];
        $days = $report['days'];
        $firstDays = array_splice($days, 0, 13);
        $pages[] = $this->summaryPage($report, $firstDays);
        foreach (array_chunk($days, 20) as $index => $chunk) {
            $pages[] = $this->dailyPage($report, $chunk, $index + 2);
        }

        $sourceRows = [];
        foreach ($report['days'] as $day) {
            foreach (array_slice($day['apps'], 0, 15) as $source) {
                $sourceRows[] = [$day['date'], 'Application', $source['name'], $source['classification'], (int) $source['duration_seconds']];
            }
            foreach (array_slice($day['websites'], 0, 15) as $source) {
                $sourceRows[] = [$day['date'], 'Website', $source['name'], $source['classification'], (int) $source['duration_seconds']];
            }
        }
        if ($sourceRows === []) {
            $pages[] = $this->emptySectionPage($report, 'Applications and websites', 'No application or website activity was recorded in this period.');
        } else {
            foreach (array_chunk($sourceRows, 24) as $index => $chunk) {
                $pages[] = $this->sourcesPage($report, $chunk, $index + 1);
            }
        }

        if ($report['events'] === []) {
            $pages[] = $this->emptySectionPage($report, 'Significant events', 'No significant attendance, Windows, or alert events were recorded.');
        } else {
            foreach (array_chunk($report['events'], 19) as $index => $chunk) {
                $pages[] = $this->eventsPage($report, $chunk, $index + 1);
            }
        }

        $count = count($pages);
        foreach ($pages as $index => &$commands) {
            $this->footer($commands, $index + 1, $count);
        }
        unset($commands);
        return $this->document(array_map(static fn (array $page): string => implode("\n", $page) . "\n", $pages));
    }

    /** @param array<string,mixed> $report @param list<array<string,mixed>> $days @return list<string> */
    private function summaryPage(array $report, array $days): array
    {
        $c = [];
        $employee = $report['employee'];
        $this->header($c, (string) ($employee['company_name'] ?? config('app.name')), 'Employee activity report', (string) $report['range_label']);
        $this->text($c, 38, 744, 18, (string) $employee['employee_name'], true, [.075,.157,.137]);
        $identity = 'Employee ' . (string) $employee['employee_number'];
        if (!empty($employee['department_name'])) $identity .= '  |  ' . $employee['department_name'];
        $this->text($c, 38, 724, 9, $identity, false, [.42,.49,.47]);

        $worked = array_sum(array_map(static fn (array $day): int => (int) $day['work_seconds'], $report['days']));
        $cards = [
            ['WORKED', $this->duration($worked)],
            ['TRACKED', $this->duration((int) $report['totals']['tracked_seconds'])],
            ['EST. IDLE', $this->duration((int) $report['totals']['idle_seconds'])],
            ['APPLICATIONS', (string) $report['totals']['app_count']],
            ['WEBSITES', (string) $report['totals']['website_count']],
            ['LATE DAYS', (string) $report['totals']['late_days']],
        ];
        foreach ($cards as $index => [$label, $value]) {
            $row = intdiv($index, 3); $column = $index % 3;
            $x = 38 + ($column * 176); $y = 650 - ($row * 66);
            $this->rect($c, $x, $y, 164, 54, [.956,.975,.968]);
            $this->text($c, $x + 12, $y + 36, 7, $label, true, [.42,.50,.47]);
            $this->text($c, $x + 12, $y + 14, 14, $value, true, [.075,.157,.137]);
        }
        $this->dailyTable($c, $days, 548, 'Daily overview');
        return $c;
    }

    /** @param array<string,mixed> $report @param list<array<string,mixed>> $days @return list<string> */
    private function dailyPage(array $report, array $days, int $part): array
    {
        $c = [];
        $this->sectionHeader($c, $report, 'Daily activity overview', 'Attendance, tracked activity and idle evidence · continued ' . $part);
        $this->dailyTable($c, $days, 700, null);
        return $c;
    }

    /** @param list<string> $c @param list<array<string,mixed>> $days */
    private function dailyTable(array &$c, array $days, float $top, ?string $title): void
    {
        if ($title !== null) $this->text($c, 38, $top + 25, 12, $title, true, [.075,.157,.137]);
        $columns = [['DATE',38],['IN',112],['OUT',154],['WORKED',198],['TRACKED',267],['IDLE',336],['APPS',397],['SITES',443],['LATE',492]];
        $this->rect($c, 38, $top - 1, 519, 25, [.075,.157,.137]);
        foreach ($columns as [$label,$x]) $this->text($c, $x + 6, $top + 8, 6.7, $label, true, [1,1,1]);
        $y = $top - 29;
        foreach ($days as $index => $day) {
            $this->rect($c, 38, $y, 519, 25, $index % 2 === 0 ? [.985,.991,.988] : [.958,.974,.967]);
            $values = [
                [$day['date'],38], [$day['first_in'] ? local_datetime((string) $day['first_in'], 'H:i') : '-',112],
                [$day['last_out'] ? local_datetime((string) $day['last_out'], 'H:i') : '-',154],
                [$this->duration((int) $day['work_seconds']),198], [$this->duration((int) $day['tracked_seconds']),267],
                [$this->duration((int) $day['idle_seconds']),336], [(string) count($day['apps']),397],
                [(string) count($day['websites']),443], [(int) $day['late_minutes'] > 0 ? $day['late_minutes'] . 'm' : '-',492],
            ];
            foreach ($values as [$value,$x]) $this->text($c, $x + 6, $y + 9, 7.6, (string) $value, $x === 38, [.15,.22,.20]);
            $y -= 27;
        }
        if ($days === []) $this->text($c, 297, $top - 45, 10, 'No daily activity was found.', false, [.45,.52,.49], 'center');
    }

    /** @param array<string,mixed> $report @param list<array{0:string,1:string,2:string,3:string,4:int}> $rows @return list<string> */
    private function sourcesPage(array $report, array $rows, int $part): array
    {
        $c = [];
        $this->sectionHeader($c, $report, 'Applications and websites', 'Top sources per day · page ' . $part);
        $columns = [['DATE',38],['TYPE',110],['SOURCE',178],['CLASSIFICATION',430],['TIME',514]];
        $this->rect($c, 38, 697, 519, 25, [.075,.157,.137]);
        foreach ($columns as [$label,$x]) $this->text($c, $x + 6, 706, 6.7, $label, true, [1,1,1]);
        $y = 669;
        foreach ($rows as $index => [$date,$type,$name,$classification,$seconds]) {
            $this->rect($c, 38, $y, 519, 25, $index % 2 === 0 ? [.985,.991,.988] : [.958,.974,.967]);
            $this->text($c, 44, $y + 9, 7.4, $date, true, [.15,.22,.20]);
            $this->text($c, 116, $y + 9, 7.4, $type, false, [.32,.40,.37]);
            $this->text($c, 184, $y + 9, 7.4, $this->shorten($name, 52), false, [.15,.22,.20]);
            $this->classification($c, 436, $y + 6, $classification);
            $this->text($c, 551, $y + 9, 7.4, $this->duration($seconds), true, [.15,.22,.20], 'right');
            $y -= 27;
        }
        return $c;
    }

    /** @param array<string,mixed> $report @param list<array<string,mixed>> $events @return list<string> */
    private function eventsPage(array $report, array $events, int $part): array
    {
        $c = [];
        $this->sectionHeader($c, $report, 'Significant events', 'Attendance, Windows agent and anomaly evidence · page ' . $part);
        $columns = [['OCCURRED',38],['GROUP',126],['EVENT',184],['SOURCE / DEVICE',315],['DETAIL',430]];
        $this->rect($c, 38, 697, 519, 25, [.075,.157,.137]);
        foreach ($columns as [$label,$x]) $this->text($c, $x + 5, 706, 6.4, $label, true, [1,1,1]);
        $y = 664;
        foreach ($events as $index => $event) {
            $this->rect($c, 38, $y, 519, 30, $index % 2 === 0 ? [.985,.991,.988] : [.958,.974,.967]);
            $this->text($c, 43, $y + 12, 7.1, local_datetime((string) $event['occurred_at'], 'd M Y H:i'), true, [.15,.22,.20]);
            $this->text($c, 131, $y + 12, 7.1, (string) $event['event_group'], false, [.32,.40,.37]);
            $this->text($c, 189, $y + 12, 7.1, $this->shorten(ucwords((string) $event['event_name']), 25), true, [.15,.22,.20]);
            $source = trim((string) $event['source'] . (!empty($event['device_name']) ? ' / ' . $event['device_name'] : ''));
            $this->text($c, 320, $y + 12, 6.8, $this->shorten($source, 24), false, [.32,.40,.37]);
            $detail = (string) ($event['detail'] ?: ($event['severity'] ?? '') ?: '-');
            $this->text($c, 435, $y + 12, 6.7, $this->shorten($detail, 27), false, [.32,.40,.37]);
            $y -= 32;
        }
        return $c;
    }

    /** @param array<string,mixed> $report @return list<string> */
    private function emptySectionPage(array $report, string $title, string $message): array
    {
        $c = [];
        $this->sectionHeader($c, $report, $title, 'Employee activity evidence');
        $this->rect($c, 38, 535, 519, 120, [.956,.975,.968]);
        $this->text($c, 297, 600, 13, $message, true, [.25,.34,.31], 'center');
        return $c;
    }

    /** @param list<string> $c @param array<string,mixed> $report */
    private function sectionHeader(array &$c, array $report, string $title, string $subtitle): void
    {
        $employee = $report['employee'];
        $this->header($c, (string) ($employee['company_name'] ?? config('app.name')), $title, (string) $report['range_label']);
        $this->text($c, 38, 744, 13, (string) $employee['employee_name'], true, [.075,.157,.137]);
        $this->text($c, 38, 726, 8.5, $subtitle . '  |  Employee ' . $employee['employee_number'], false, [.42,.49,.47]);
    }

    /** @param list<string> $c */
    private function header(array &$c, string $company, string $title, string $range): void
    {
        $this->rect($c, 0, 772, 595, 70, [.012,.067,.149]);
        $this->rect($c, 0, 766, 595, 6, [.098,.663,.961]);
        $this->text($c, 38, 814, 8.5, strtoupper($company), true, [1,1,1]);
        $this->text($c, 38, 790, 20, $title, true, [1,1,1]);
        $this->text($c, 557, 793, 8.5, $range, false, [.78,.89,1], 'right');
        $this->text($c, 38, 758, 7, 'TIME & ATTENDANCE', true, [.031,.42,.847]);
    }

    /** @param list<string> $c */
    private function footer(array &$c, int $page, int $count): void
    {
        $this->rect($c, 38, 72, 519, 1, [.86,.90,.88]);
        $this->text($c, 38, 51, 7.5, 'Generated ' . date('d M Y H:i') . ' SAST  |  Supporting evidence, subject to review', false, [.45,.52,.49]);
        $this->text($c, 557, 51, 7.5, 'Page ' . $page . ' of ' . $count, false, [.45,.52,.49], 'right');
    }

    /** @param list<string> $c */
    private function classification(array &$c, float $x, float $y, string $category): void
    {
        $colors = [
            'productive' => [[.88,.96,.93],[.06,.45,.32]],
            'neutral' => [[.91,.94,.95],[.33,.42,.47]],
            'distracting' => [[.98,.90,.88],[.70,.25,.18]],
            'unclassified' => [[.93,.95,.94],[.42,.48,.46]],
        ];
        [$background,$foreground] = $colors[$category] ?? $colors['unclassified'];
        $this->rect($c, $x, $y, 70, 14, $background);
        $this->text($c, $x + 35, $y + 4, 6.3, ucfirst($category), true, $foreground, 'center');
    }

    private function duration(int $seconds): string
    {
        $hours = intdiv(max(0, $seconds), 3600);
        $minutes = intdiv(max(0, $seconds) % 3600, 60);
        return $hours > 0 ? $hours . 'h ' . $minutes . 'm' : $minutes . 'm';
    }

    private function shorten(string $value, int $width): string
    {
        $value = trim($value);
        return strlen($value) <= $width ? $value : substr($value, 0, max(1, $width - 3)) . '...';
    }

    /** @param list<string> $pages */
    private function document(array $pages): string
    {
        $kids = []; $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', 4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>'];
        $next = 5;
        foreach ($pages as $content) {
            $pageId = $next++; $contentId = $next++; $kids[] = $pageId . ' 0 R';
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$contentId} 0 R >>";
            $objects[$contentId] = "<< /Length " . strlen($content) . ">>\nstream\n{$content}endstream";
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objects); $pdf = "%PDF-1.4\n"; $offsets = [0];
        foreach ($objects as $id => $object) { $offsets[$id] = strlen($pdf); $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n"; }
        $xref = strlen($pdf); $size = max(array_keys($objects)) + 1;
        $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";
        for ($i = 1; $i < $size; $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        return $pdf . "trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    /** @param list<string> $c @param array{0:float,1:float,2:float} $rgb */
    private function text(array &$c, float $x, float $y, float $size, string $text, bool $bold = false, array $rgb = [0,0,0], string $align = 'left'): void
    {
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', $text) ?: $text;
        $encoded = str_replace(['\\','(',')',"\r","\n"], ['\\\\','\\(','\\)',' ',' '], $encoded);
        $width = strlen($encoded) * $size * .5;
        if ($align === 'right') $x -= $width;
        if ($align === 'center') $x -= $width / 2;
        $c[] = sprintf('BT /%s %.1f Tf %.3f %.3f %.3f rg %.1f %.1f Td (%s) Tj ET', $bold ? 'F2' : 'F1', $size, $rgb[0], $rgb[1], $rgb[2], $x, $y, $encoded);
    }

    /** @param list<string> $c @param array{0:float,1:float,2:float} $rgb */
    private function rect(array &$c, float $x, float $y, float $width, float $height, array $rgb): void
    {
        $c[] = sprintf('%.3f %.3f %.3f rg %.1f %.1f %.1f %.1f re f', $rgb[0], $rgb[1], $rgb[2], $x, $y, $width, $height);
    }
}
