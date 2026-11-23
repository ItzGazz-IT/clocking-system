<?php
declare(strict_types=1);
namespace App\Services;

/** Daily idle evidence. Keep raw readings in storage; exclude implausible counters. */
final class IdleIntervals
{
    public static function valid(int $seconds): bool
    {
        return $seconds > 0 && $seconds <= 86400;
    }

    public static function summarize(array $samples, \DateTimeZone $timezone): array
    {
        $intervals = [];
        $count = 0;
        foreach ($samples as $sample) {
            $seconds = (int) ($sample['idle_seconds'] ?? 0);
            if (!self::valid($seconds)) continue;
            $time = (new \DateTimeImmutable($sample['occurred_at'], new \DateTimeZone('UTC')))->setTimezone($timezone);
            $end = $time->getTimestamp();
            $start = max($end - $seconds, $time->setTime(0, 0)->getTimestamp());
            if (!empty($sample['attendance_start'])) {
                $start = max($start, (new \DateTimeImmutable($sample['attendance_start'], new \DateTimeZone('UTC')))->getTimestamp());
            }
            if ($end <= $start) continue;
            $intervals[] = [$start, $end];
            $count++;
        }
        usort($intervals, static fn ($a, $b) => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($intervals as [$start, $end]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }
        $lengths = array_map(static fn ($interval) => $interval[1] - $interval[0], $merged);
        return ['idle_seconds' => array_sum($lengths), 'idle_samples' => $count,
            'idle_episodes' => count($merged), 'longest_idle_seconds' => $lengths === [] ? 0 : max($lengths)];
    }
}
