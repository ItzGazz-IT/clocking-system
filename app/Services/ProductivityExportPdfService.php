<?php

declare(strict_types=1);

namespace App\Services;

final class ProductivityExportPdfService
{
    /** @param array{company:string,start:string,end:string,range_label:string,employees:list<array<string,mixed>>,sources:list<array<string,mixed>>} $report */
    public function render(array $report): string
    {
        $pages = [];
        $employeeChunks = array_chunk($report['employees'], 15);
        if ($employeeChunks === []) {
            $employeeChunks = [[]];
        }
        foreach ($employeeChunks as $index => $rows) {
            $pages[] = $this->employeePage($report, $rows, $index + 1);
        }
        if ($report['sources'] === []) {
            $pages[] = $this->emptySourcesPage($report);
        } else {
            foreach (array_chunk($report['sources'], 22) as $index => $rows) {
                $pages[] = $this->sourcePage($report, $rows, $index + 1);
            }
        }

        $count = count($pages);
        foreach ($pages as $index => &$commands) {
            $this->footer($commands, $index + 1, $count);
        }
        unset($commands);

        return $this->document(array_map(static fn (array $page): string => implode("\n", $page) . "\n", $pages));
    }

    /** @param array<string,mixed> $report @param list<array<string,mixed>> $rows @return list<string> */
    private function employeePage(array $report, array $rows, int $part): array
    {
        $c = [];
        $this->header($c, $report, 'Productivity overview', $part > 1 ? 'Employee summary · continued ' . $part : 'Employee summary');

        $totals = ['tracked' => 0, 'productive' => 0, 'neutral' => 0, 'distracting' => 0];
        foreach ($report['employees'] as $row) {
            $totals['tracked'] += (int) $row['duration_seconds'];
            $totals['productive'] += (int) $row['productive_seconds'];
            $totals['neutral'] += (int) $row['neutral_seconds'];
            $totals['distracting'] += (int) $row['distracting_seconds'];
        }
        $cards = [
            ['TRACKED', $this->duration($totals['tracked'])],
            ['PRODUCTIVE', $this->duration($totals['productive'])],
            ['NEUTRAL', $this->duration($totals['neutral'])],
            ['DISTRACTING', $this->duration($totals['distracting'])],
        ];
        foreach ($cards as $index => [$label, $value]) {
            $x = 38 + ($index * 132);
            $this->rect($c, $x, 658, 120, 48, [.918,.957,1]);
            $this->text($c, $x + 11, 688, 6.6, $label, true, [.188,.31,.48]);
            $this->text($c, $x + 11, 670, 12.5, $value, true, [.027,.106,.247]);
        }

        $columns = [['EMPLOYEE',38],['DEPARTMENT',190],['TRACKED',295],['PRODUCTIVE',355],['NEUTRAL',421],['DISTRACT.',472],['SCORE',529]];
        $this->rect($c, 38, 615, 519, 25, [.027,.106,.247]);
        foreach ($columns as [$label, $x]) $this->text($c, $x + 5, 624, 6.3, $label, true, [1,1,1]);
        $y = 587;
        foreach ($rows as $index => $row) {
            $this->rect($c, 38, $y, 519, 26, $index % 2 === 0 ? [.976,.988,1] : [.925,.959,.998]);
            $productive = (int) $row['productive_seconds'];
            $distracting = (int) $row['distracting_seconds'];
            $score = ($productive + $distracting) > 0 ? (int) round(($productive / ($productive + $distracting)) * 100) : 0;
            $this->text($c, 43, $y + 10, 7.3, $this->shorten((string) ($row['employee_name'] ?? 'Unassigned'), 27), true, [.027,.106,.247]);
            $this->text($c, 195, $y + 10, 7, $this->shorten((string) ($row['department_name'] ?? '-'), 18), false, [.26,.35,.48]);
            $this->text($c, 300, $y + 10, 7, $this->duration((int) $row['duration_seconds']), false, [.12,.20,.32]);
            $this->text($c, 360, $y + 10, 7, $this->duration($productive), false, [.12,.20,.32]);
            $this->text($c, 426, $y + 10, 7, $this->duration((int) $row['neutral_seconds']), false, [.12,.20,.32]);
            $this->text($c, 477, $y + 10, 7, $this->duration($distracting), false, [.12,.20,.32]);
            $this->text($c, 551, $y + 10, 7.2, $score . '%', true, [.031,.42,.847], 'right');
            $y -= 28;
        }
        if ($rows === []) {
            $this->text($c, 297, 550, 10, 'No employee activity was recorded for this selection.', false, [.38,.46,.58], 'center');
        }
        return $c;
    }

    /** @param array<string,mixed> $report @param list<array<string,mixed>> $rows @return list<string> */
    private function sourcePage(array $report, array $rows, int $part): array
    {
        $c = [];
        $this->header($c, $report, 'Applications and websites', 'Detailed source activity · page ' . $part);
        $columns = [['EMPLOYEE',38],['TYPE',172],['APPLICATION / WEBSITE',229],['CLASSIFICATION',440],['TIME',516]];
        $this->rect($c, 38, 697, 519, 25, [.027,.106,.247]);
        foreach ($columns as [$label, $x]) $this->text($c, $x + 5, 706, 6.3, $label, true, [1,1,1]);
        $y = 669;
        foreach ($rows as $index => $row) {
            $this->rect($c, 38, $y, 519, 25, $index % 2 === 0 ? [.976,.988,1] : [.925,.959,.998]);
            $this->text($c, 43, $y + 9, 7, $this->shorten((string) ($row['employee_name'] ?? 'Unassigned'), 23), true, [.027,.106,.247]);
            $this->text($c, 177, $y + 9, 6.8, (string) $row['record_type'], false, [.26,.35,.48]);
            $this->text($c, 234, $y + 9, 7, $this->shorten((string) $row['source_name'], 39), false, [.12,.20,.32]);
            $this->classification($c, 445, $y + 5, (string) $row['classification']);
            $this->text($c, 551, $y + 9, 7, $this->duration((int) $row['duration_seconds']), true, [.027,.106,.247], 'right');
            $y -= 27;
        }
        return $c;
    }

    /** @param array<string,mixed> $report @return list<string> */
    private function emptySourcesPage(array $report): array
    {
        $c = [];
        $this->header($c, $report, 'Applications and websites', 'Detailed source activity');
        $this->rect($c, 38, 535, 519, 120, [.918,.957,1]);
        $this->text($c, 297, 600, 11, 'No application or website activity was recorded.', true, [.188,.31,.48], 'center');
        return $c;
    }

    /** @param list<string> $c @param array<string,mixed> $report */
    private function header(array &$c, array $report, string $title, string $subtitle): void
    {
        $this->rect($c, 0, 764, 595, 78, [.012,.067,.149]);
        $this->rect($c, 0, 758, 595, 6, [.098,.663,.961]);
        $this->text($c, 38, 816, 9, strtoupper((string) $report['company']), true, [1,1,1]);
        $this->text($c, 38, 789, 20, $title, true, [1,1,1]);
        $this->text($c, 557, 814, 8, (string) $report['range_label'], false, [.78,.89,1], 'right');
        $this->text($c, 557, 790, 8, $subtitle, false, [.78,.89,1], 'right');
        $this->text($c, 38, 741, 7.5, 'TIME & ATTENDANCE', true, [.031,.42,.847]);
    }

    /** @param list<string> $c */
    private function footer(array &$c, int $page, int $count): void
    {
        $this->rect($c, 38, 72, 519, 1, [.82,.88,.95]);
        $this->text($c, 38, 51, 7.2, 'Confidential · Generated ' . date('d M Y H:i') . ' SAST', false, [.38,.46,.58]);
        $this->text($c, 557, 51, 7.2, 'Page ' . $page . ' of ' . $count, false, [.38,.46,.58], 'right');
    }

    /** @param list<string> $c */
    private function classification(array &$c, float $x, float $y, string $category): void
    {
        $colors = [
            'productive' => [[.88,.95,1],[.02,.32,.68]],
            'neutral' => [[.92,.94,.97],[.30,.39,.52]],
            'distracting' => [[1,.91,.89],[.68,.20,.14]],
            'unclassified' => [[.94,.95,.97],[.42,.48,.58]],
        ];
        [$background, $foreground] = $colors[$category] ?? $colors['unclassified'];
        $this->rect($c, $x, $y, 68, 14, $background);
        $this->text($c, $x + 34, $y + 4, 6.1, ucfirst($category), true, $foreground, 'center');
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
        $kids = [];
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
        ];
        $next = 5;
        foreach ($pages as $content) {
            $pageId = $next++;
            $contentId = $next++;
            $kids[] = $pageId . ' 0 R';
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$contentId} 0 R >>";
            $objects[$contentId] = "<< /Length " . strlen($content) . ">>\nstream\n{$content}endstream";
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
        for ($index = 1; $index < $size; $index++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$index] ?? 0);
        }
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
