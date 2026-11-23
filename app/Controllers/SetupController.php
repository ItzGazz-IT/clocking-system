<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Services\DatabaseSetup;
use PDO;
use PDOException;

final class SetupController extends Controller
{
    public function show(Request $request): Response
    {
        if (DatabaseSetupController::needsDatabase()) {
            return Response::redirect(app_url('/setup/database'));
        }

        if (!$this->setupIsOpen()) {
            return Response::redirect(app_url('/login'));
        }
        if (DatabaseSetup::provisioned() && !DatabaseSetupController::authorized()) {
            return Response::redirect(app_url('/setup/database'));
        }

        return $this->view('setup/index', [
            'errors' => Session::pull('_errors', []),
            'flash' => Session::pull('_flash'),
        ]);
    }

    public function store(Request $request): Response
    {
        if (DatabaseSetupController::needsDatabase()) {
            return Response::redirect(app_url('/setup/database'));
        }

        if (!$this->setupIsOpen()) {
            return Response::redirect(app_url('/login'));
        }
        if (DatabaseSetup::provisioned() && !DatabaseSetupController::authorized()) {
            return Response::redirect(app_url('/setup/database'));
        }

        $input = $request->body();
        $errors = $this->validateSetup($input);
        if ($errors !== []) {
            Session::put('_errors', $errors);
            Session::put('_old_input', array_diff_key($input, array_flip(['admin_password', 'admin_password_confirmation'])));
            return Response::redirect(app_url('/setup'));
        }

        $pdo = Database::connection();
        DatabaseSetup::lock($pdo);
        try {
            $pdo->beginTransaction();

            if (!$this->setupIsOpen($pdo)) {
                $pdo->rollBack();
                return Response::redirect(app_url('/login'));
            }

            $companyId = $this->createCompany($pdo, $input);
            $userId = $this->createAdminUser($pdo, $companyId, $input);
            $roleId = $this->ensureAdministratorRole($pdo);

            $assignRole = $pdo->prepare('INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (:user_id, :role_id, :assigned_by)');
            $assignRole->execute([
                'user_id' => $userId,
                'role_id' => $roleId,
                'assigned_by' => $userId,
            ]);

            $pdo->commit();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Session::put('_errors', ['setup' => 'The company could not be registered. Check the details and try again.']);
            Session::put('_old_input', array_diff_key($input, array_flip(['admin_password', 'admin_password_confirmation'])));
            return Response::redirect(app_url('/setup'));
        } finally {
            DatabaseSetup::unlock($pdo);
        }

        Session::forget('_database_setup_key');
        Session::forget('_old_input');
        Session::flash('success', 'Company registered. Sign in with the admin account you just created.');
        return Response::redirect(app_url('/login'));
    }

    private function setupIsOpen(?PDO $pdo = null): bool
    {
        $pdo ??= Database::connection();
        $companyCount = (int) $pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn();
        $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

        return $companyCount === 0 && $userCount === 0;
    }

    /** @param array<string, mixed> $input */
    private function validateSetup(array $input): array
    {
        $errors = [];

        if (mb_strlen(trim((string) ($input['company_name'] ?? ''))) < 2) {
            $errors['company_name'] = 'Enter the company name.';
        }
        $companyEmail = trim((string) ($input['company_email'] ?? ''));
        if ($companyEmail !== '' && !filter_var($companyEmail, FILTER_VALIDATE_EMAIL)) {
            $errors['company_email'] = 'Enter a valid company email address.';
        }
        $timezone = trim((string) ($input['timezone'] ?? 'Africa/Johannesburg'));
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            $errors['timezone'] = 'Choose a valid timezone.';
        }
        if (mb_strlen(trim((string) ($input['admin_name'] ?? ''))) < 2) {
            $errors['admin_name'] = 'Enter the admin name.';
        }
        if (!preg_match('/^[A-Za-z0-9_.-]{2,80}$/', trim((string) ($input['admin_username'] ?? '')))) {
            $errors['admin_username'] = 'Use 2-80 letters, numbers, dots, dashes or underscores.';
        }

        $passwordErrors = Validator::passwordChange([
            'current_password' => 'first-run',
            'password' => $input['admin_password'] ?? '',
            'password_confirmation' => $input['admin_password_confirmation'] ?? '',
        ]);
        if (isset($passwordErrors['password'])) {
            $errors['admin_password'] = $passwordErrors['password'];
        }
        if (isset($passwordErrors['password_confirmation'])) {
            $errors['admin_password_confirmation'] = $passwordErrors['password_confirmation'];
        }

        return $errors;
    }

    /** @param array<string, mixed> $input */
    private function createCompany(PDO $pdo, array $input): int
    {
        $address = array_filter([
            'line1' => trim((string) ($input['address_line1'] ?? '')),
            'line2' => trim((string) ($input['address_line2'] ?? '')),
            'city' => trim((string) ($input['city'] ?? '')),
            'province' => trim((string) ($input['province'] ?? '')),
            'postal_code' => trim((string) ($input['postal_code'] ?? '')),
            'country' => trim((string) ($input['country'] ?? 'South Africa')),
        ], static fn (string $value): bool => $value !== '');

        $insert = $pdo->prepare(
            'INSERT INTO companies (name, registration_number, timezone, email, phone, address, is_active)
             VALUES (:name, NULL, :timezone, :email, :phone, :address, TRUE)'
        );
        $insert->execute([
            'name' => trim((string) $input['company_name']),
            'timezone' => trim((string) ($input['timezone'] ?? 'Africa/Johannesburg')) ?: 'Africa/Johannesburg',
            'email' => trim((string) ($input['company_email'] ?? '')) ?: null,
            'phone' => trim((string) ($input['company_phone'] ?? '')) ?: null,
            'address' => $address === [] ? null : json_encode($address, JSON_THROW_ON_ERROR),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @param array<string, mixed> $input */
    private function createAdminUser(PDO $pdo, int $companyId, array $input): int
    {
        $insert = $pdo->prepare(
            'INSERT INTO users (company_id, employee_id, username, email, display_name, password_hash, is_active, email_verified_at, password_changed_at, must_change_password)
             VALUES (:company_id, NULL, :username, :email, :display_name, :password_hash, TRUE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, FALSE)'
        );
        $username = strtolower(trim((string) $input['admin_username']));
        $insert->execute([
            'company_id' => $companyId,
            'username' => $username,
            'email' => $username . '@local.attendance',
            'display_name' => trim((string) $input['admin_name']),
            'password_hash' => password_hash(
                (string) $input['admin_password'],
                Config::get('security.password.algorithm', PASSWORD_DEFAULT),
                (array) Config::get('security.password.options', [])
            ),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function ensureAdministratorRole(PDO $pdo): int
    {
        $select = $pdo->query("SELECT id FROM roles WHERE company_id IS NULL AND slug = 'administrator' AND deleted_at IS NULL LIMIT 1");
        $roleId = (int) ($select->fetchColumn() ?: 0);
        if ($roleId > 0) {
            return $roleId;
        }

        $pdo->exec(
            "INSERT INTO roles (company_id, name, slug, description, is_system)
             VALUES (NULL, 'Administrator', 'administrator', 'Full company administration role template', TRUE)"
        );
        $roleId = (int) $pdo->lastInsertId();

        $assignPermissions = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_id) SELECT :role_id, id FROM permissions');
        $assignPermissions->execute(['role_id' => $roleId]);

        return $roleId;
    }
}
