<?php
declare(strict_types=1);
namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

final class FlexibleWorkCalculator
{
    /** Ordered evidence can include a boundary lookback/lookahead; totals are clipped to this day. */
    public function calculate(array $events, int $dayStart, int $calculationEnd, int $idleThreshold, int $offlineTimeout, bool $isToday): array
    {
        $idleThreshold = max(1, $idleThreshold);
        $offlineTimeout = max(1, $offlineTimeout);
        $activeFrom = $lastSignal = null;
        $intervals = [];
        $firstActive = $lastActiveAt = $lastEventAt = null;
        $untrackedSeconds = 0;
        $currentlyActive = false;
        $close = static function (int $end) use (&$activeFrom, &$intervals): void {
            if ($activeFrom !== null) $intervals[] = [$activeFrom, max($activeFrom, $end)];
            $activeFrom = null;
        };
        foreach ($events as $event) {
            $at = (new DateTimeImmutable((string) $event['occurred_at'], new DateTimeZone('UTC')))->getTimestamp();
            // Live calculations must never consume future evidence.
            if ($isToday && $at > $calculationEnd) continue;
            $code = $event['code'];
            if (!in_array($code, ['login','unlock','wake','activity','idle','lock','sleep','logout','shutdown','agent_exit','agent_uninstall'], true)) continue;
            if ($code === 'idle' && !IdleIntervals::valid((int) $event['idle_seconds'])) continue;
            $metadata = json_decode((string) ($event['metadata'] ?? '{}'), true) ?: [];
            // Legacy workers sent login on every process restart, including overnight.
            if ($code === 'login' && ($metadata['reason'] ?? '') === 'agent_start') continue;
            if ($lastSignal !== null && $at - $lastSignal > $offlineTimeout) {
                $close($lastSignal);
                $untrackedSeconds += max(0, min($at, $calculationEnd) - max($lastSignal, $dayStart));
            }
            $previousSignal = $lastSignal;
            $lastSignal = $at;
            if ($at >= $dayStart && $at <= $calculationEnd) $lastEventAt = $at;

            if (in_array($code, ['login','unlock','activity'], true)) {
                $activeFrom ??= $at;
            } elseif ($code === 'idle' && (int) $event['idle_seconds'] >= $idleThreshold) {
                $close($at - (int) $event['idle_seconds']);
            } elseif ($code === 'wake') {
                // A missing suspend callback must not turn a short sleep into work.
                $close($previousSignal ?? $at);
            } elseif (in_array($code, ['lock','sleep','logout','shutdown','agent_exit','agent_uninstall'], true)) {
                $end = $code === 'lock' && ($metadata['reason'] ?? '') === 'automatic_observation'
                    ? $at - max(0, (int) ($event['idle_seconds'] ?? 0)) : $at;
                $close($end);
            }
            // Wake (scheduled maintenance, network wake) alone never opens work.
        }
        $currentlyActive = $isToday && $activeFrom !== null && $lastSignal !== null
            && $calculationEnd - $lastSignal <= $offlineTimeout;
        if ($activeFrom !== null) {
            // Next-day evidence can establish continuity across midnight, but never
            // extend the current projection beyond its local day boundary.
            $close($currentlyActive ? $calculationEnd : (int) $lastSignal);
        }
        $activeSeconds = 0;
        foreach ($intervals as [$start, $end]) {
            $start = max($start, $dayStart);
            $end = min($end, $calculationEnd);
            if ($start > $end || (!$isToday && $start >= $calculationEnd)) continue;
            $firstActive ??= $start;
            $lastActiveAt = max($lastActiveAt ?? $end, $end);
            $activeSeconds += $end - $start;
        }
        $lastEventAt ??= $lastSignal === null ? null : min($lastSignal, $calculationEnd);
        return compact('activeSeconds','untrackedSeconds','currentlyActive','firstActive','lastActiveAt','lastEventAt');
    }
}
