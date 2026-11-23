<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use Throwable;

final class UserAccountService
{
    /** @return list<array<string, mixed>> */
    public function accounts(int $companyId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT u.id, u.employee_id, u.display_name, u.username, u.email, u.is_active,
                    u.must_change_password, u.last_login_at,
                    e.employee_number, GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ", ") AS role_names,
                    MAX(CASE WHEN r.is_system = 1 THEN r.id END) AS system_role_id
             FROM users u
             LEFT JOIN employees e ON e.id = u.employee_id
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id
             WHERE u.company_id = :company_id AND u.deleted_at IS NULL
             GROUP BY u.id, u.employee_id, u.display_name, u.username, u.email, u.is_active,
                      u.must_change_password, u.last_login_at, e.employee_number
             ORDER BY u.display_name'
        );
        $statement->execute(['company_id' => $companyId]);
        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function availableEmployees(int $companyId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT e.id, e.employee_number, e.first_name, e.last_name
             FROM employees e
             LEFT JOIN users u ON u.employee_id = e.id AND u.deleted_at IS NULL
             WHERE e.company_id = :company_id AND e.deleted_at IS NULL
               AND e.employment_status = "active" AND u.id IS NULL
             ORDER BY e.last_name, e.first_name'
        );
        $statement->execute(['company_id' => $companyId]);
        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function roles(): array
    {
        return Database::connection()->query(
            'SELECT id, name, slug FROM roles
             WHERE is_system = 1 AND deleted_at IS NULL
               AND slug IN ("employee", "administrator")
             ORDER BY FIELD(slug, "employee", "administrator")'
        )->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function passwordResetRequests(int $companyId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT prr.id, prr.username, prr.requested_at, u.id AS user_id, u.display_name
             FROM password_reset_requests prr
             LEFT JOIN users u ON u.id = prr.user_id
             WHERE prr.status = "pending"
               AND (prr.company_id = :company_id OR u.company_id = :user_company_id)
             ORDER BY prr.requested_at DESC
             LIMIT 20'
        );
        $statement->execute(['company_id' => $companyId, 'user_company_id' => $companyId]);
        return $statement->fetchAll();
    }

    /** @return array{user_id:int, temporary_password:string} */
    public function provision(int $companyId, int $actorId, array $data): array
    {
        $database = Database::connection();
        $database->beginTransaction();
        try {
            $employee = $database->prepare(
                'SELECT e.id, e.employee_number, e.first_name, e.last_name
                 FROM employees e LEFT JOIN users u ON u.employee_id = e.id AND u.deleted_at IS NULL
                 WHERE e.id = :id AND e.company_id = :company_id AND e.deleted_at IS NULL
                   AND e.employment_status = "active" AND u.id IS NULL
                 FOR UPDATE'
            );
            $employee->execute(['id' => (int) $data['employee_id'], 'company_id' => $companyId]);
            $employeeRow = $employee->fetch();
            if (!$employeeRow) {
                throw new HttpException(422, 'The employee is unavailable or already has an account.');
            }
            $role = $database->prepare(
                'SELECT id, slug FROM roles WHERE id = :id AND is_system = 1
                 AND slug IN ("administrator", "employee") AND deleted_at IS NULL'
            );
            $role->execute(['id' => (int) $data['role_id']]);
            $roleRow = $role->fetch();
            if (!$roleRow) {
                throw new HttpException(422, 'Choose a valid role.');
            }
            $password = $this->temporaryPassword();
            $username = strtolower(trim((string) $data['username']));
            $internalEmail = $username . '@local.attendance';
            $insert = $database->prepare(
                'INSERT INTO users
                 (company_id, employee_id, username, email, display_name, password_hash, is_active,
                  email_verified_at, password_changed_at, must_change_password)
                 VALUES
                 (:company_id, :employee_id, :username, :email, :display_name, :password_hash, 1,
                  CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 1)'
            );
            $insert->execute([
                'company_id' => $companyId,
                'employee_id' => $employeeRow['id'],
                'username' => $username,
                'email' => $internalEmail,
                'display_name' => trim($employeeRow['first_name'] . ' ' . $employeeRow['last_name']),
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            $userId = (int) $database->lastInsertId();
            $assignment = $database->prepare(
                'INSERT INTO user_roles (user_id, role_id, assigned_by)
                 VALUES (:user_id, :role_id, :actor_id)'
            );
            $assignment->execute(['user_id' => $userId, 'role_id' => $roleRow['id'], 'actor_id' => $actorId]);
            $database->commit();
            return ['user_id' => $userId, 'temporary_password' => $password];
        } catch (Throwable $exception) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $exception;
        }
    }

    public function resetPassword(int $companyId, int $userId): string
    {
        $password = $this->temporaryPassword();
        $statement = Database::connection()->prepare(
            'UPDATE users SET password_hash = :hash, password_changed_at = CURRENT_TIMESTAMP,
             must_change_password = 1, auth_version = auth_version + 1
             WHERE id = :id AND company_id = :company_id AND deleted_at IS NULL'
        );
        $statement->execute([
            'hash' => password_hash($password, PASSWORD_DEFAULT),
            'id' => $userId,
            'company_id' => $companyId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new HttpException(404, 'User account not found.');
        }
        return $password;
    }

    public function toggleActive(int $companyId, int $userId, int $actorId): bool
    {
        if ($userId === $actorId) {
            throw new HttpException(422, 'You cannot disable your own account.');
        }
        if ($this->isLastActiveAdministrator($companyId, $userId)) {
            throw new HttpException(422, 'The final active administrator cannot be disabled.');
        }
        $statement = Database::connection()->prepare(
            'UPDATE users SET is_active = NOT is_active
             WHERE id = :id AND company_id = :company_id AND deleted_at IS NULL'
        );
        $statement->execute(['id' => $userId, 'company_id' => $companyId]);
        if ($statement->rowCount() !== 1) {
            throw new HttpException(404, 'User account not found.');
        }
        $state = Database::connection()->prepare('SELECT is_active FROM users WHERE id = :id');
        $state->execute(['id' => $userId]);
        return (bool) $state->fetchColumn();
    }

    public function updateRole(int $companyId, int $userId, int $roleId, int $actorId): void
    {
        $database = Database::connection();
        $database->beginTransaction();
        try {
            $user = $database->prepare(
                'SELECT 1 FROM users WHERE id = :id AND company_id = :company_id AND deleted_at IS NULL FOR UPDATE'
            );
            $user->execute(['id' => $userId, 'company_id' => $companyId]);
            if (!$user->fetchColumn()) {
                throw new HttpException(404, 'User account not found.');
            }
            $role = $database->prepare(
                'SELECT 1 FROM roles WHERE id = :id AND is_system = 1
                 AND slug IN ("administrator", "employee") AND deleted_at IS NULL'
            );
            $role->execute(['id' => $roleId]);
            if (!$role->fetchColumn()) {
                throw new HttpException(422, 'Choose a valid role.');
            }
            $roleSlug = $database->prepare('SELECT slug FROM roles WHERE id = :id');
            $roleSlug->execute(['id' => $roleId]);
            if ($roleSlug->fetchColumn() !== 'administrator'
                && $this->isLastActiveAdministrator($companyId, $userId)) {
                throw new HttpException(422, 'The final active administrator must retain the Administrator role.');
            }
            $remove = $database->prepare(
                'DELETE ur FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                 WHERE ur.user_id = :user_id AND r.is_system = 1'
            );
            $remove->execute(['user_id' => $userId]);
            $assign = $database->prepare(
                'INSERT INTO user_roles (user_id, role_id, assigned_by)
                 VALUES (:user_id, :role_id, :actor_id)'
            );
            $assign->execute(['user_id' => $userId, 'role_id' => $roleId, 'actor_id' => $actorId]);
            $database->commit();
        } catch (Throwable $exception) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $exception;
        }
    }

    private function temporaryPassword(): string
    {
        $groups = [
            'ABCDEFGHJKLMNPQRSTUVWXYZ',
            'abcdefghijkmnopqrstuvwxyz',
            '23456789',
            '!@#$%*-_',
        ];
        $password = '';
        foreach ($groups as $group) {
            $password .= $group[random_int(0, strlen($group) - 1)];
        }
        $alphabet = implode('', $groups);
        while (strlen($password) < 18) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $characters = str_split($password);
        for ($index = count($characters) - 1; $index > 0; $index--) {
            $swap = random_int(0, $index);
            [$characters[$index], $characters[$swap]] = [$characters[$swap], $characters[$index]];
        }
        return implode('', $characters);
    }

    private function isLastActiveAdministrator(int $companyId, int $userId): bool
    {
        $isAdministrator = Database::connection()->prepare(
            'SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = :user_id AND r.slug = "administrator"'
        );
        $isAdministrator->execute(['user_id' => $userId]);
        if ((int) $isAdministrator->fetchColumn() === 0) {
            return false;
        }
        $count = Database::connection()->prepare(
            'SELECT COUNT(DISTINCT u.id) FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id
             WHERE u.company_id = :company_id AND u.is_active = 1 AND u.deleted_at IS NULL
               AND r.slug = "administrator"'
        );
        $count->execute(['company_id' => $companyId]);
        return (int) $count->fetchColumn() <= 1;
    }
}
