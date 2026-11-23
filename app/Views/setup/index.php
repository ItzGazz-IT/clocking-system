<!doctype html>
<html lang="en-ZA">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register company | <?= e(config('app.name')) ?></title>
    <link rel="icon" href="<?= e(app_url('/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(config('app.url')) ?>/assets/css/app.css?v=unbranded-1">
</head>
<body>
<main class="auth-shell setup-shell">
    <section class="auth-card setup-card" aria-labelledby="setup-title">
        <p class="eyebrow">First setup</p>
        <h1 id="setup-title">Register your company</h1>
        <p class="muted">Create the company workspace and the first administrator account.</p>

        <?php if (!empty($errors['setup'])): ?>
            <div class="alert" role="alert"><?= e($errors['setup']) ?></div>
        <?php endif; ?>
        <?php if (!empty($flash)): ?>
            <div class="alert <?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= e(app_url('/setup')) ?>">
            <?= csrf_field() ?>

            <h2>Company details</h2>
            <label for="company_name">Company name</label>
            <input id="company_name" name="company_name" maxlength="160" value="<?= e(old('company_name')) ?>" required>
            <?php if (!empty($errors['company_name'])): ?><small class="error"><?= e($errors['company_name']) ?></small><?php endif; ?>

            <label for="timezone">Timezone</label>
            <select id="timezone" name="timezone">
                <option value="Africa/Johannesburg" <?= old('timezone', 'Africa/Johannesburg') === 'Africa/Johannesburg' ? 'selected' : '' ?>>Africa/Johannesburg</option>
            </select>
            <?php if (!empty($errors['timezone'])): ?><small class="error"><?= e($errors['timezone']) ?></small><?php endif; ?>

            <label for="company_phone">Company phone</label>
            <input id="company_phone" name="company_phone" maxlength="40" value="<?= e(old('company_phone')) ?>">

            <label for="address_line1">Address line 1</label>
            <input id="address_line1" name="address_line1" maxlength="180" value="<?= e(old('address_line1')) ?>">

            <label for="address_line2">Address line 2</label>
            <input id="address_line2" name="address_line2" maxlength="180" value="<?= e(old('address_line2')) ?>">

            <div class="form-row three">
                <div>
                    <label for="city">City</label>
                    <input id="city" name="city" maxlength="100" value="<?= e(old('city')) ?>">
                </div>
                <div>
                    <label for="province">Province</label>
                    <input id="province" name="province" maxlength="100" value="<?= e(old('province')) ?>">
                </div>
                <div>
                    <label for="postal_code">Postal code</label>
                    <input id="postal_code" name="postal_code" maxlength="20" value="<?= e(old('postal_code')) ?>">
                </div>
            </div>

            <label for="country">Country</label>
            <input id="country" name="country" maxlength="80" value="<?= e(old('country', 'South Africa')) ?>">

            <h2>First administrator</h2>
            <label for="admin_name">Admin name</label>
            <input id="admin_name" name="admin_name" maxlength="160" autocomplete="name" value="<?= e(old('admin_name')) ?>" required>
            <?php if (!empty($errors['admin_name'])): ?><small class="error"><?= e($errors['admin_name']) ?></small><?php endif; ?>

            <label for="admin_username">Admin username</label>
            <input id="admin_username" name="admin_username" maxlength="80" autocomplete="username" value="<?= e(old('admin_username')) ?>" required>
            <?php if (!empty($errors['admin_username'])): ?><small class="error"><?= e($errors['admin_username']) ?></small><?php endif; ?>

            <div class="form-row">
                <div>
                    <label for="admin_password">Password</label>
                    <input id="admin_password" name="admin_password" type="password" autocomplete="new-password" required>
                    <?php if (!empty($errors['admin_password'])): ?><small class="error"><?= e($errors['admin_password']) ?></small><?php endif; ?>
                </div>
                <div>
                    <label for="admin_password_confirmation">Confirm password</label>
                    <input id="admin_password_confirmation" name="admin_password_confirmation" type="password" autocomplete="new-password" required>
                    <?php if (!empty($errors['admin_password_confirmation'])): ?><small class="error"><?= e($errors['admin_password_confirmation']) ?></small><?php endif; ?>
                </div>
            </div>

            <button type="submit">Create company and admin</button>
        </form>
    </section>
</main>
</body>
</html>
