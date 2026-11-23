<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\UserIdentity;
use App\Core\Database;

final class EmployeeAccessService
{
    public function canAccess(UserIdentity $user, int $employeeId): bool
    {
        if ($user->can('attendance.view.all')) {
            return $this->belongsToCompany($user->companyId, $employeeId);
        }
        if ($user->employeeId === $employeeId) {
            return true;
        }
        return false;
    }

    /** @return list<array<string, mixed>> */
    public function accessibleEmployees(UserIdentity $user): array
    {
        $sql = 'SELECT e.id, e.employee_number, e.first_name, e.last_name
                FROM employees e
                LEFT JOIN departments d ON d.id = e.department_id
                WHERE e.company_id = :company_id AND e.employment_status = "active"
                  AND e.deleted_at IS NULL';
        $parameters = ['company_id' => $user->companyId];
        if (!$user->can('attendance.view.all')) {
            if ($user->employeeId) {
                $sql .= ' AND e.id = :self_id';
                $parameters['self_id'] = $user->employeeId;
            } else {
                return [];
            }
        }
        $sql .= ' ORDER BY e.last_name, e.first_name';
        $statement = Database::connection()->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    private function belongsToCompany(int $companyId, int $employeeId): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT 1 FROM employees WHERE id = :id AND company_id = :company_id AND deleted_at IS NULL'
        );
        $statement->execute(['id' => $employeeId, 'company_id' => $companyId]);
        return (bool) $statement->fetchColumn();
    }
}
