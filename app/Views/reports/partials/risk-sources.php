<section class="panel risk-sources-panel">
    <div class="panel-heading">
        <div><h2>Attention-risk sources</h2><p class="muted">The exact apps and websites included in attention-risk time.</p></div>
    </div>
    <div class="risk-source-list">
        <?php foreach ($riskSources as $source): ?>
            <article>
                <span class="risk-source-icon">!</span>
                <div>
                    <strong><?= e($source['website_domain'] ?: $source['app_name']) ?></strong>
                    <small><?= !empty($showRiskEmployees) ? e(($source['employee_name'] ?? 'Unassigned') . ' · ') : '' ?><?= e(local_datetime($source['first_seen_at'], 'H:i')) ?>–<?= e(local_datetime($source['last_seen_at'], 'H:i')) ?></small>
                </div>
                <b><?= e(activity_duration((int) $source['duration_seconds'])) ?></b>
            </article>
        <?php endforeach; ?>
        <?php if ($riskSources === []): ?><p class="empty">No attention-risk activity was recorded for this date.</p><?php endif; ?>
    </div>
</section>
