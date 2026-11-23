<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My productivity | <?= e(config('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(app_url('/assets/css/app.css?v=20260710-daily-attendance')) ?>">
    <link rel="icon" href="<?= e(app_url('/favicon.svg?v=clock-1')) ?>" type="image/svg+xml">
</head>
<body>
<?php include __DIR__ . '/../partials/nav.php'; ?>
<?php
$trackedSeconds = (int) ($summary['tracked_seconds'] ?? 0);
$focusSeconds = (int) ($summary['focus_seconds'] ?? 0);
$riskSeconds = (int) ($summary['risk_seconds'] ?? 0);
$neutralSeconds = (int) ($summary['neutral_seconds'] ?? 0);
$unclassifiedSeconds = (int) ($summary['unclassified_seconds'] ?? 0);
$scoredSeconds = $focusSeconds + $riskSeconds;
$classifiedSeconds = $focusSeconds + $neutralSeconds + $riskSeconds;
$focusScore = $scoredSeconds > 0 ? min(100, (int) round(($focusSeconds / $scoredSeconds) * 100)) : 0;
$confidence = $trackedSeconds > 0 ? min(100, (int) round(($classifiedSeconds / $trackedSeconds) * 100)) : 0;
$focusPercent = $trackedSeconds > 0 ? min(100, (int) round(($focusSeconds / $trackedSeconds) * 100)) : 0;
$riskPercent = $trackedSeconds > 0 ? min(100, (int) round(($riskSeconds / $trackedSeconds) * 100)) : 0;
?>
<main class="container productivity-page">
    <div class="page-heading productivity-heading">
        <div>
            <p class="eyebrow">My day</p>
            <h1>My productivity</h1>
            <p class="muted"><?= e(local_datetime($date . ' 00:00:00', 'l, d M Y')) ?></p>
        </div>
        <form method="get" action="<?= e(app_url('/my/productivity')) ?>" class="activity-filter employee-filter">
            <label>
                <span>Date</span>
                <input type="date" name="date" value="<?= e($date) ?>">
            </label>
            <button type="submit">Apply</button>
        </form>
    </div>

    <section class="productivity-hero employee-productivity-hero">
        <article class="focus-score">
            <span>Trusted focus score</span>
            <strong><?= e((string) $focusScore) ?>%</strong>
            <div class="score-grade"><?= e((string) $confidence) ?>% score confidence</div>
        </article>
        <article>
            <span>Tracked time</span>
            <strong><?= e(activity_duration($trackedSeconds)) ?></strong>
            <small>Foreground app and website time</small>
        </article>
        <article>
            <span>Productive time</span>
            <strong><?= e(activity_duration($focusSeconds)) ?></strong>
            <small><?= e(activity_percent($focusSeconds, $trackedSeconds)) ?> of tracked time</small>
        </article>
        <article>
            <span>Attention risk</span>
            <strong><?= e(activity_duration($riskSeconds)) ?></strong>
            <small><?= e(activity_percent($riskSeconds, $trackedSeconds)) ?> of tracked time</small>
        </article>
        <article>
            <span>Tracking coverage</span>
            <strong><?= e((string) ($summary['coverage_percent'] ?? 0)) ?>%</strong>
            <small><?= e(activity_duration((int) ($summary['clocked_seconds'] ?? 0))) ?> clocked</small>
        </article>
    </section>

    <section class="panel personal-split-panel">
        <div class="panel-heading">
            <div>
                <h2>My classified time</h2>
                <p class="muted">Productive and distracting time determine the score; neutral and unclassified time do not.</p>
            </div>
        </div>
        <div class="personal-split-content">
            <div class="split-chart-stats"><strong><?= e((string) $focusPercent) ?>%</strong><span>Focus</span><strong><?= e((string) $riskPercent) ?>%</strong><span>Risk</span></div>
            <div class="segment-meter" role="img" aria-label="<?= e((string) $focusPercent) ?> percent focus and <?= e((string) $riskPercent) ?> percent attention risk">
                <?php $focusSegments = (int) round($focusPercent / 5); $riskSegments = (int) round($riskPercent / 5); ?>
                <?php for ($segment = 1; $segment <= 20; $segment++): ?><i class="<?= $segment <= $focusSegments ? 'focus' : ($segment <= $focusSegments + $riskSegments ? 'risk' : 'neutral') ?>"></i><?php endfor; ?>
            </div>
            <?php if ($riskSeconds > 0): ?><div class="risk-callout"><i></i><strong><?= e(activity_duration($riskSeconds)) ?> attention risk</strong><span><?= e((string) $riskPercent) ?>% of tracked time</span></div><?php endif; ?>
            <div class="chart-legend"><span><i class="focus"></i>Productive <?= e(activity_duration($focusSeconds)) ?></span><span><i class="risk"></i>Distracting <?= e(activity_duration($riskSeconds)) ?></span><span><i class="neutral"></i>Unscored <?= e(activity_duration($neutralSeconds + $unclassifiedSeconds)) ?></span></div>
        </div>
    </section>

    <?php $showRiskEmployees = false; include __DIR__ . '/partials/risk-sources.php'; ?>

    <div class="productivity-grid">
        <section class="panel">
            <div class="panel-heading"><h2>My top apps</h2></div>
            <div class="rank-list">
                <?php foreach ($topApps as $row): ?>
                    <article>
                        <div>
                            <strong><?= e($row['app_name']) ?></strong>
                            <small><?= e(activity_duration((int) $row['duration_seconds'])) ?></small>
                        </div>
                        <span class="rank-segments"><?php for ($segment = 1; $segment <= 10; $segment++): ?><i class="<?= $segment <= max(1, (int) round($row['share'] / 10)) ? 'filled' : '' ?>"></i><?php endfor; ?></span>
                    </article>
                <?php endforeach; ?>
                <?php if ($topApps === []): ?><p class="empty">No app activity for this date.</p><?php endif; ?>
            </div>
        </section>

        <section class="panel">
            <div class="panel-heading"><h2>My top websites</h2></div>
            <div class="rank-list website-list">
                <?php foreach ($topWebsites as $row): ?>
                    <article>
                        <div>
                            <strong><?= e($row['website_domain']) ?></strong>
                            <small><?= e(activity_duration((int) $row['duration_seconds'])) ?></small>
                        </div>
                        <span class="rank-segments website"><?php for ($segment = 1; $segment <= 10; $segment++): ?><i class="<?= $segment <= max(1, (int) round($row['share'] / 10)) ? 'filled' : '' ?>"></i><?php endfor; ?></span>
                    </article>
                <?php endforeach; ?>
                <?php if ($topWebsites === []): ?><p class="empty">No website activity for this date.</p><?php endif; ?>
            </div>
        </section>
    </div>
</main>
</body>
</html>

<?php
function activity_duration(int $seconds): string
{
    $seconds = max(0, $seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($hours > 0) {
        return $hours . 'h ' . $minutes . 'm';
    }

    return $minutes . 'm';
}

function activity_percent(int $value, int $total): string
{
    return $total > 0 ? (string) min(100, (int) round(($value / $total) * 100)) . '%' : '0%';
}
?>
