<?php

declare(strict_types=1);

namespace App\Models;

use App\Auth\UserIdentity;
use App\Core\Model;
use PDO;

final class User extends Model
{
    /** @return array<string, mixed>|null */
    public function findActiveByUsername(string $username): ?array
    {
        $username = strtolower(trim($username));
        $statement = $this->database->prepare(
             'SELECT id, employee_id, username, email, password_hash, display_name
             FROM users
             WHERE (username = :username OR email = :email) AND is_active = 1 AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute(['username' => $username, 'email' => $username]);
        $user = $statement->fetch();
        return $user === false ? null : $user;
    }

    public function identityById(int $userId): ?UserIdentity
    {
        $statement = $this->database->prepare(
            'SELECT id, company_id, employee_id, username, email, display_name, must_change_password, auth_version
             FROM users
             WHERE id = :id AND is_active = 1 AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch();
        if ($user === false) {
            return null;
        }

        $roleStatement = $this->database->prepare(
            'SELECT DISTINCT r.slug
             FROM roles r
             INNER JOIN user_roles ur ON ur.role_id = r.id
             WHERE ur.user_id = :user_id AND r.deleted_at IS NULL'
        );
        $roleStatement->execute(['user_id' => $userId]);

        $permissionStatement = $this->database->prepare(
            'SELECT DISTINCT p.slug
             FROM permissions p
             INNER JOIN role_permissions rp ON rp.permission_id = p.id
             INNER JOIN user_roles ur ON ur.role_id = rp.role_id
             WHERE ur.user_id = :user_id'
        );
        $permissionStatement->execute(['user_id' => $userId]);

        return new UserIdentity(
            (int) $user['id'],
            (int) $user['company_id'],
            $user['employee_id'] === null ? null : (int) $user['employee_id'],
            (string) $user['display_name'],
            (string) ($user['username'] ?? ''),
            (string) $user['email'],
            $roleStatement->fetchAll(PDO::FETCH_COLUMN),
            $permissionStatement->fetchAll(PDO::FETCH_COLUMN),
            (bool) $user['must_change_password'],
            (int) $user['auth_version']
        );
    }

    public function updateLastLogin(int $userId): void
    {
        $statement = $this->database->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id');
        $statement->execute(['id' => $userId]);
    }

    public function updatePasswordHash(int $userId, string $passwordHash): void
    {
        $statement = $this->database->prepare(
            'UPDATE users
             SET password_hash = :password_hash, password_changed_at = CURRENT_TIMESTAMP,
                 must_change_password = 0, auth_version = auth_version + 1
             WHERE id = :id'
        );
        $statement->execute(['password_hash' => $passwordHash, 'id' => $userId]);
    }

    public function passwordHash(int $userId): ?string
    {
        $statement = $this->database->prepare(
            'SELECT password_hash FROM users WHERE id = :id AND is_active = 1 AND deleted_at IS NULL'
        );
        $statement->execute(['id' => $userId]);
        $hash = $statement->fetchColumn();
        return $hash === false ? null : (string) $hash;
    }
}
