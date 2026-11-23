<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Alert Details | <?= e(config('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(app_url('/assets/css/app.css?v=20260710-daily-attendance')) ?>">
    <link rel="icon" href="<?= e(app_url('/favicon.svg?v=clock-1')) ?>" type="image/svg+xml">
</head>
<body>
<?php require BASE_PATH . '/app/Views/partials/nav.php'; ?>
<main class="container">
    <div class="page-heading">
        <div>
            <p class="eyebrow">Anomaly Alert</p>
            <h1><?= e(ucfirst(str_replace('_', ' ', $alert['alert_type']))) ?></h1>
            <p class="muted"><?= e($alert['employee_name']) ?> (<?= e($alert['employee_number']) ?>)</p>
        </div>
        <a class="back-link" href="<?= e(app_url('/alerts')) ?>">← Back to review &amp; evidence</a>
    </div>

    <div class="alert-detail-grid">
        <section class="panel alert-info">
            <h2>Alert Details</h2>
            
            <div class="detail-row">
                <span class="label">Type</span>
                <span><code><?= e(str_replace('_', ' ', $alert['alert_type'])) ?></code></span>
            </div>

            <div class="detail-row">
                <span class="label">Severity</span>
                <span><strong class="severity-<?= e($alert['severity']) ?>"><?= e(ucfirst($alert['severity'])) ?></strong></span>
            </div>

            <div class="detail-row">
                <span class="label">Status</span>
                <span><strong><?= e(ucfirst($alert['status'])) ?></strong></span>
            </div>

            <div class="detail-row">
                <span class="label">Created</span>
                <span><?= e(local_datetime($alert['created_at'], 'M d, Y H:i:s')) ?> SAST</span>
            </div>

            <?php if ($alert['reviewed_at']): ?>
                <div class="detail-row">
                    <span class="label">Reviewed by</span>
                    <span><?= e($alert['reviewer_name']) ?> on <?= e(local_datetime($alert['reviewed_at'], 'M d, Y H:i')) ?></span>
                </div>
            <?php endif; ?>

            <div class="detail-row" style="grid-column: 1/-1;">
                <span class="label">Description</span>
                <p><?= e($alert['description']) ?></p>
            </div>

            <?php if ($alert['metadata']): ?>
                <div class="detail-row" style="grid-column: 1/-1;">
                    <span class="label">Details</span>
                    <pre><code><?= e(json_encode(json_decode($alert['metadata'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></code></pre>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($relatedData): ?>
            <section class="panel related-event">
                <h2>Related Event</h2>
                
                <?php if (isset($relatedData['code'])): // Attendance event ?>
                    <div class="detail-row">
                        <span class="label">Event type</span>
                        <span><?= e($relatedData['name']) ?></span>
                    </div>

                    <div class="detail-row">
                        <span class="label">Occurred at</span>
                        <span><?= e(local_datetime($relatedData['occurred_at'], 'M d, Y H:i:s')) ?> SAST</span>
                    </div>

                    <div class="detail-row">
                        <span class="label">Source</span>
                        <span><code><?= e(ucfirst($relatedData['source'])) ?></code></span>
                    </div>

                    <?php if ($relatedData['ip_address']): ?>
                        <div class="detail-row">
                            <span class="label">IP Address</span>
                            <span><code><?= e($relatedData['ip_address']) ?></code></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($relatedData['computer_name']): ?>
                        <div class="detail-row">
                            <span class="label">Computer</span>
                            <span><?= e($relatedData['computer_name']) ?></span>
                        </div>
                    <?php endif; ?>

                <?php elseif (isset($relatedData['idle_seconds'])): // Windows event ?>
                    <div class="detail-row">
                        <span class="label">Event type</span>
                        <span><?= e($relatedData['name']) ?></span>
                    </div>

                    <div class="detail-row">
                        <span class="label">Occurred at</span>
                        <span><?= e(local_datetime($relatedData['occurred_at'], 'M d, Y H:i:s')) ?> SAST</span>
                    </div>

                    <?php if ($relatedData['idle_seconds'] !== null): ?>
                        <div class="detail-row">
                            <span class="label">Idle time</span>
                            <span><strong><?= e(number_format($relatedData['idle_seconds'])) ?></strong> seconds (<?= e(number_format($relatedData['idle_seconds'] / 3600, 1)) ?> hours)</span>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>

    <?php if ($alert['status'] === 'pending'): ?>
        <section class="panel review-form">
            <h2>Review Action</h2>
            <form method="post" action="<?= e(app_url('/alerts/' . $alert['id'] . '/review')) ?>">
                <?= csrf_field() ?>
                
                <div class="form-row">
                    <div>
                        <label for="action">Action</label>
                        <select id="action" name="action" required>
                            <option value="">Select an action</option>
                            <option value="dismiss">Dismiss as resolved or false positive</option>
                            <option value="escalate">Escalate for investigation</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label for="notes">Review notes</label>
                    <textarea id="notes" name="notes" rows="4" placeholder="Optional: Add notes for the record"></textarea>
                </div>

                <button type="submit">Save review</button>
            </form>
        </section>
    <?php else: ?>
        <section class="panel review-summary">
            <h2>Review Summary</h2>
            <div class="detail-row">
                <span class="label">Reviewer</span>
                <span><?= e($alert['reviewer_name']) ?></span>
            </div>
            <div class="detail-row">
                <span class="label">Reviewed at</span>
                <span><?= e(local_datetime($alert['reviewed_at'], 'M d, Y H:i:s')) ?></span>
            </div>
            <?php if ($alert['review_notes']): ?>
                <div class="detail-row" style="grid-column: 1/-1;">
                    <span class="label">Notes</span>
                    <p><?= e($alert['review_notes']) ?></p>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>

<style>
.alert-detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin: 2rem 0; }
@media (max-width: 1024px) { .alert-detail-grid { grid-template-columns: 1fr; } }
.detail-row { display: grid; grid-template-columns: 150px 1fr; gap: 1rem; align-items: flex-start; padding: 1rem 0; border-bottom: 1px solid #eee; }
.detail-row:last-child { border-bottom: none; }
.detail-row .label { font-weight: bold; color: #666; }
.detail-row p { margin: 0; }
.detail-row code { background: #f5f5f5; padding: 0.25rem 0.5rem; border-radius: 3px; }
.detail-row pre { background: #f5f5f5; padding: 1rem; overflow-x: auto; }
.severity-high { color: #d32f2f; }
.severity-medium { color: #f57c00; }
.severity-low { color: #fbc02d; }
.review-form { margin-top: 2rem; }
.form-row { display: grid; grid-template-columns: 1fr; gap: 1rem; margin-bottom: 1.5rem; }
.form-row > div { display: flex; flex-direction: column; }
.form-row label { margin-bottom: 0.5rem; font-weight: bold; }
.form-row select, .form-row textarea { padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; }
.review-form button { padding: 0.75rem 1.5rem; background: #1976d2; color: white; border: none; border-radius: 4px; cursor: pointer; }
.review-form button:hover { background: #1565c0; }
</style>
</body>
</html>
