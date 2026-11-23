<?php

declare(strict_types=1);

namespace App\Auth;

final class UserIdentity
{
    /** @param list<string> $roles @param list<string> $permissions */
    public function __construct(
        public readonly int $id,
        public readonly int $companyId,
        public readonly ?int $employeeId,
        public readonly string $name,
        public readonly string $username,
        public readonly string $email,
        public readonly array $roles,
        public readonly array $permissions,
        public readonly bool $mustChangePassword,
        public readonly int $authVersion
    ) {
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}
