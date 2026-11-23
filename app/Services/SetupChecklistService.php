<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class SetupChecklistService
{
    /** @return list<array{label:string, detail:string, complete:bool, href:string}> */
    public function items(int $companyId): array
    {
        return [
            [
                'label' => 'Company settings',
                'detail' => 'Confirm company name, phone, timezone and attendance defaults.',
                'complete' => $this->hasCompany($companyId),
                'href' => app_url('/settings'),
            ],
            [
                'label' => 'Departments',
                'detail' => 'Add the teams or locations employees belong to.',
                'complete' => $this->count('departments', $companyId) > 0,
                'href' => app_url('/departments'),
            ],
            [
                'label' => 'Employees',
                'detail' => 'Create employee records with employee numbers.',
                'complete' => $this->count('employees', $companyId) > 0,
                'href' => app_url('/employees'),
            ],
            [
                'label' => 'User accounts',
                'detail' => 'Give staff usernames and temporary passwords.',
                'complete' => $this->count('users', $companyId) > 1,
                'href' => app_url('/users'),
            ],
            [
                'label' => 'Windows devices',
                'detail' => 'Install and link the tray app on each staff PC.',
                'complete' => $this->count('devices', $companyId) > 0,
                'href' => app_url('/devices'),
            ],
        ];
    }

    private function hasCompany(int $companyId): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM companies WHERE id = :id AND deleted_at IS NULL'
        );
        $statement->execute(['id' => $companyId]);
        return (int) $statement->fetchColumn() === 1;
    }

    private function count(string $table, int $companyId): int
    {
        $statement = Database::connection()->prepare(
            sprintf('SELECT COUNT(*) FROM `%s` WHERE company_id = :company_id AND deleted_at IS NULL', $table)
        );
        $statement->execute(['company_id' => $companyId]);
        return (int) $statement->fetchColumn();
    }
}
