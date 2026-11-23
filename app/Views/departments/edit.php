<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Edit department | <?= e(config('app.name')) ?></title><link rel="stylesheet" href="<?= e(app_url('/assets/css/app.css?v=20260710-daily-attendance')) ?>">    <link rel="icon" href="<?= e(app_url('/favicon.svg?v=clock-1')) ?>" type="image/svg+xml">
</head>
<body><?php require BASE_PATH . '/app/Views/partials/nav.php'; ?><main class="container narrow">
<div class="page-heading"><div><p class="eyebrow">Organisation</p><h1>Edit department</h1></div><a class="back-link" href="<?= e(app_url('/departments')) ?>">← Back to departments</a></div>
<section class="panel"><form method="post" action="<?= e(app_url('/departments/' . $department['id'] . '/edit')) ?>"><?= csrf_field() ?>
<div class="form-row"><div><label for="code">Code</label><input id="code" name="code" value="<?= e($department['code']) ?>" required><?php if(isset($errors['code'])):?><small class="error"><?= e($errors['code']) ?></small><?php endif;?></div><div><label for="name">Name</label><input id="name" name="name" value="<?= e($department['name']) ?>" required><?php if(isset($errors['name'])):?><small class="error"><?= e($errors['name']) ?></small><?php endif;?></div></div>
<label for="description">Description</label><textarea id="description" name="description" rows="4"><?= e($department['description']) ?></textarea><button type="submit">Save changes</button>
</form></section></main></body></html>
