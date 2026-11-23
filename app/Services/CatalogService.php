<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;

final class CatalogService
{
    /** @return list<array<string, mixed>> */
    public function departments(int $companyId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT d.id, d.code, d.name, d.description, d.is_active, COUNT(e.id) AS employee_count
             FROM departments d
             LEFT JOIN employees e ON e.department_id = d.id AND e.deleted_at IS NULL
             WHERE d.company_id = :company_id AND d.deleted_at IS NULL
             GROUP BY d.id, d.code, d.name, d.description, d.is_active
             ORDER BY d.name'
        );
        $statement->execute(['company_id' => $companyId]);
        return $statement->fetchAll();
    }

    public function createDepartment(int $companyId, array $data): int
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO departments (company_id, code, name, description)
             VALUES (:company_id, :code, :name, :description)'
        );
        $statement->execute([
            'company_id' => $companyId,
            'code' => strtoupper(trim((string) $data['code'])),
            'name' => trim((string) $data['name']),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    /** @return array<string, mixed> */
    public function department(int $companyId, int $id): array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM departments WHERE id = :id AND company_id = :company_id AND deleted_at IS NULL'
        );
        $statement->execute(['id' => $id, 'company_id' => $companyId]);
        return $statement->fetch() ?: throw new RuntimeException('Department not found.');
    }

    public function updateDepartment(int $companyId, int $id, array $data): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE departments SET code = :code, name = :name, description = :description
             WHERE id = :id AND company_id = :company_id AND deleted_at IS NULL'
        );
        $statement->execute([
            'code' => strtoupper(trim((string) $data['code'])),
            'name' => trim((string) $data['name']),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'id' => $id,
            'company_id' => $companyId,
        ]);
        if ($statement->rowCount() === 0) {
            $this->department($companyId, $id);
        }
    }

    public function archiveDepartment(int $companyId, int $id): void
    {
        $count = Database::connection()->prepare(
            'SELECT COUNT(*) FROM employees
             WHERE department_id = :id AND company_id = :company_id
               AND employment_status = "active" AND deleted_at IS NULL'
        );
        $count->execute(['id' => $id, 'company_id' => $companyId]);
        if ((int) $count->fetchColumn() > 0) {
            throw new RuntimeException('Move or archive active employees before archiving this department.');
        }
        $statement = Database::connection()->prepare(
            'UPDATE departments SET is_active = 0, deleted_at = CURRENT_TIMESTAMP
             WHERE id = :id AND company_id = :company_id AND deleted_at IS NULL'
        );
        $statement->execute(['id' => $id, 'company_id' => $companyId]);
    }

    /** @return list<array<string, mixed>> */
    public function shifts(int $companyId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT s.*, COUNT(es.id) AS employee_count
             FROM shifts s
             LEFT JOIN employee_shifts es ON es.shift_id = s.id AND es.deleted_at IS NULL
             WHERE s.company_id = :company_id AND s.deleted_at IS NULL
             GROUP BY s.id ORDER BY s.name'
        );
        $statement->execute(['company_id' => $companyId]);
        return $statement->fetchAll();
    }

    public function createShift(int $companyId, array $data): int
    {
        $start = substr(trim((string) $data['start_time']), 0, 5);
        $end = substr(trim((string) $data['end_time']), 0, 5);
        $statement = Database::connection()->prepare(
            'INSERT INTO shifts
             (company_id, code, name, start_time, end_time, spans_midnight, paid_minutes,
              grace_period_minutes, expected_lunch_minutes, rules)
             VALUES
             (:company_id, :code, :name, :start_time, :end_time, :spans_midnight, :paid_minutes,
              :grace_period, :lunch_minutes, :rules)'
        );
        $statement->execute([
            'company_id' => $companyId,
            'code' => strtoupper(trim((string) $data['code'])),
            'name' => trim((string) $data['name']),
            'start_time' => $start,
            'end_time' => $end,
            'spans_midnight' => $end <= $start ? 1 : 0,
            'paid_minutes' => (int) $data['paid_minutes'],
            'grace_period' => (int) $data['grace_period_minutes'],
            'lunch_minutes' => (int) $data['expected_lunch_minutes'],
            'rules' => json_encode(['work_days' => [1, 2, 3, 4, 5]], JSON_THROW_ON_ERROR),
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    /** @return array<string, mixed> */
    public function shift(int $companyId, int $id): array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM shifts WHERE id = :id AND company_id = :company_id AND deleted_at IS NULL'
        );
        $statement->execute(['id' => $id, 'company_id' => $companyId]);
        return $statement->fetch() ?: throw new RuntimeException('Shift not found.');
    }

    public function updateShift(int $companyId, int $id, array $data): void
    {
        $start = substr(trim((string) $data['start_time']), 0, 5);
        $end = substr(trim((string) $data['end_time']), 0, 5);
        $statement = Database::connection()->prepare(
            'UPDATE shifts SET code = :code, name = :name, start_time = :start_time,
             end_time = :end_time, spans_midnight = :spans_midnight, paid_minutes = :paid_minutes,
             grace_period_minutes = :grace_period, expected_lunch_minutes = :lunch_minutes
             WHERE id = :id AND company_id = :company_id AND deleted_at IS NULL'
        );
        $statement->execute([
            'code' => strtoupper(trim((string) $data['code'])),
            'name' => trim((string) $data['name']),
            'start_time' => $start,
            'end_time' => $end,
            'spans_midnight' => $end <= $start ? 1 : 0,
            'paid_minutes' => (int) $data['paid_minutes'],
            'grace_period' => (int) $data['grace_period_minutes'],
            'lunch_minutes' => (int) $data['expected_lunch_minutes'],
            'id' => $id,
            'company_id' => $companyId,
        ]);
        if ($statement->rowCount() === 0) {
            $this->shift($companyId, $id);
        }

        $saved = $this->shift($companyId, $id);
        if (substr((string) $saved['start_time'], 0, 5) !== $start
            || substr((string) $saved['end_time'], 0, 5) !== $end) {
            throw new RuntimeException('The shift times could not be saved. Please try again.');
        }
    }

    public function archiveShift(int $companyId, int $id): void
    {
        $count = Database::connection()->prepare(
            'SELECT COUNT(*) FROM employee_shifts es JOIN employees e ON e.id = es.employee_id
             WHERE es.shift_id = :id AND e.company_id = :company_id AND e.employment_status = "active"
               AND e.deleted_at IS NULL AND es.deleted_at IS NULL
               AND (es.effective_to IS NULL OR es.effective_to >= CURRENT_DATE)'
        );
        $count->execute(['id' => $id, 'company_id' => $companyId]);
        if ((int) $count->fetchColumn() > 0) {
            throw new RuntimeException('Reassign active employees before archiving this shift.');
        }
        $statement = Database::connection()->prepare(
            'UPDATE shifts SET is_active = 0, deleted_at = CURRENT_TIMESTAMP
             WHERE id = :id AND company_id = :company_id AND deleted_at IS NULL'
        );
        $statement->execute(['id' => $id, 'company_id' => $companyId]);
    }

    /** @return list<array<string, mixed>> */
    public function employees(int $companyId, ?array $employeeIds = null): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $sql = 'SELECT e.id, e.employee_number, e.first_name, e.last_name, e.email, e.employment_status,
                    d.name AS department_name, s.name AS shift_name, e.attendance_mode
             FROM employees e
             LEFT JOIN departments d ON d.id = e.department_id
             LEFT JOIN employee_shifts es ON es.employee_id = e.id AND es.deleted_at IS NULL
                 AND es.effective_from <= CURRENT_DATE
                 AND (es.effective_to IS NULL OR es.effective_to >= CURRENT_DATE)
             LEFT JOIN shifts s ON s.id = es.shift_id
             WHERE e.company_id = :company_id AND e.deleted_at IS NULL';
        $parameters = ['company_id' => $companyId];
        if ($employeeIds !== null) {
            $placeholders = [];
            foreach (array_values($employeeIds) as $index => $employeeId) {
                $placeholder = 'employee_' . $index;
                $placeholders[] = ':' . $placeholder;
                $parameters[$placeholder] = (int) $employeeId;
            }
            $sql .= ' AND e.id IN (' . implode(', ', $placeholders) . ')';
        }
        $sql .= ' ORDER BY e.last_name, e.first_name';
        $statement = Database::connection()->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    public function createEmployee(int $companyId, array $data): int
    {
        $database = Database::connection();
        $database->beginTransaction();
        try {
            $this->assertOwned($companyId, 'departments', (int) $data['department_id']);
            $isFlexible = true;
            if (!$isFlexible) {
                $this->assertOwned($companyId, 'shifts', (int) $data['shift_id']);
            }
            $statement = $database->prepare(
                'INSERT INTO employees
                 (company_id, department_id, employee_number, first_name, last_name, email, phone,
                  hire_date, employment_status, employment_type, attendance_mode, activity_attendance_effective_from,
                  daily_target_minutes, idle_threshold_minutes, offline_timeout_minutes)
                 VALUES
                 (:company_id, :department_id, :employee_number, :first_name, :last_name, :email,
                  :phone, :hire_date, :status, :employment_type, :attendance_mode, :activity_effective_from,
                  :daily_target, :idle_threshold, :offline_timeout)'
            );
            $statement->execute([
                'company_id' => $companyId,
                'department_id' => (int) $data['department_id'],
                'employee_number' => strtoupper(trim((string) $data['employee_number'])),
                'first_name' => trim((string) $data['first_name']),
                'last_name' => trim((string) $data['last_name']),
                'email' => trim((string) ($data['email'] ?? '')) ?: null,
                'phone' => trim((string) ($data['phone'] ?? '')) ?: null,
                'hire_date' => $data['hire_date'] ?: null,
                'status' => 'active',
                'employment_type' => trim((string) ($data['employment_type'] ?? 'full_time')),
                'attendance_mode' => 'flexible',
                'activity_effective_from' => $data['activity_attendance_effective_from'] ?? date('Y-m-d'),
                'daily_target' => (int) ($data['daily_target_minutes'] ?? 480),
                'idle_threshold' => (int) ($data['idle_threshold_minutes'] ?? 30),
                'offline_timeout' => (int) ($data['offline_timeout_minutes'] ?? 15),
            ]);
            $employeeId = (int) $database->lastInsertId();
            if (!$isFlexible) {
                $assignment = $database->prepare(
                    'INSERT INTO employee_shifts (employee_id, shift_id, effective_from, days_of_week)
                     VALUES (:employee_id, :shift_id, :effective_from, :days)'
                );
                $assignment->execute([
                    'employee_id' => $employeeId,
                    'shift_id' => (int) $data['shift_id'],
                    'effective_from' => $data['hire_date'] ?: date('Y-m-d'),
                    'days' => json_encode([1, 2, 3, 4, 5], JSON_THROW_ON_ERROR),
                ]);
            }
            $database->commit();
            return $employeeId;
        } catch (\Throwable $exception) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function employee(int $companyId, int $id): array
    {
        $statement = Database::connection()->prepare(
            'SELECT e.*, es.shift_id FROM employees e
             LEFT JOIN employee_shifts es ON es.employee_id = e.id AND es.deleted_at IS NULL
               AND es.effective_from <= CURRENT_DATE
               AND (es.effective_to IS NULL OR es.effective_to >= CURRENT_DATE)
             WHERE e.id = :id AND e.company_id = :company_id AND e.deleted_at IS NULL
             ORDER BY es.effective_from DESC LIMIT 1'
        );
        $statement->execute(['id' => $id, 'company_id' => $companyId]);
        return $statement->fetch() ?: throw new RuntimeException('Employee not found.');
    }

    public function updateEmployee(int $companyId, int $id, array $data): void
    {
        $database = Database::connection();
        $database->beginTransaction();
        try {
            $this->employee($companyId, $id);
            $this->assertOwned($companyId, 'departments', (int) $data['department_id']);
            $isFlexible = true;
            if (!$isFlexible) {
                $this->assertOwned($companyId, 'shifts', (int) $data['shift_id']);
            }
            $statement = $database->prepare(
                'UPDATE employees SET department_id = :department_id,
                 employee_number = :employee_number, first_name = :first_name, last_name = :last_name,
                 email = :email, phone = :phone, hire_date = :hire_date, employment_type = :employment_type,
                 attendance_mode = :attendance_mode, activity_attendance_effective_from = :activity_effective_from,
                 daily_target_minutes = :daily_target, idle_threshold_minutes = :idle_threshold,
                 offline_timeout_minutes = :offline_timeout
                 WHERE id = :id AND company_id = :company_id AND deleted_at IS NULL'
            );
            $statement->execute([
                'department_id' => (int) $data['department_id'],
                'employee_number' => strtoupper(trim((string) $data['employee_number'])),
                'first_name' => trim((string) $data['first_name']),
                'last_name' => trim((string) $data['last_name']),
                'email' => trim((string) ($data['email'] ?? '')) ?: null,
                'phone' => trim((string) ($data['phone'] ?? '')) ?: null,
                'hire_date' => $data['hire_date'] ?: null,
                'employment_type' => trim((string) ($data['employment_type'] ?? 'full_time')),
                'attendance_mode' => 'flexible',
                'activity_effective_from' => $data['activity_attendance_effective_from'] ?? date('Y-m-d'),
                'daily_target' => (int) ($data['daily_target_minutes'] ?? 480),
                'idle_threshold' => (int) ($data['idle_threshold_minutes'] ?? 30),
                'offline_timeout' => (int) ($data['offline_timeout_minutes'] ?? 15),
                'id' => $id,
                'company_id' => $companyId,
            ]);
            $current = $database->prepare(
                'SELECT id, shift_id, effective_from FROM employee_shifts
                 WHERE employee_id = :employee_id AND deleted_at IS NULL
                   AND effective_from <= CURRENT_DATE
                   AND (effective_to IS NULL OR effective_to >= CURRENT_DATE)
                 ORDER BY effective_from DESC LIMIT 1 FOR UPDATE'
            );
            $current->execute(['employee_id' => $id]);
            $assignment = $current->fetch();
            if ($isFlexible && $assignment) {
                if ($assignment['effective_from'] === date('Y-m-d')) {
                    $database->prepare('UPDATE employee_shifts SET deleted_at = CURRENT_TIMESTAMP WHERE id = :id')
                        ->execute(['id' => $assignment['id']]);
                } else {
                    $database->prepare('UPDATE employee_shifts SET effective_to = DATE_SUB(CURRENT_DATE, INTERVAL 1 DAY) WHERE id = :id')
                        ->execute(['id' => $assignment['id']]);
                }
            } elseif (!$isFlexible && (!$assignment || (int) $assignment['shift_id'] !== (int) $data['shift_id'])) {
                if ($assignment) {
                    if ($assignment['effective_from'] === date('Y-m-d')) {
                        $database->prepare('UPDATE employee_shifts SET deleted_at = CURRENT_TIMESTAMP WHERE id = :id')
                            ->execute(['id' => $assignment['id']]);
                    } else {
                        $database->prepare('UPDATE employee_shifts SET effective_to = DATE_SUB(CURRENT_DATE, INTERVAL 1 DAY) WHERE id = :id')
                            ->execute(['id' => $assignment['id']]);
                    }
                }
                $database->prepare(
                    'INSERT INTO employee_shifts (employee_id, shift_id, effective_from, days_of_week)
                     VALUES (:employee_id, :shift_id, CURRENT_DATE, :days)'
                )->execute([
                    'employee_id' => $id,
                    'shift_id' => (int) $data['shift_id'],
                    'days' => json_encode([1, 2, 3, 4, 5], JSON_THROW_ON_ERROR),
                ]);
            }
            $database->commit();
        } catch (\Throwable $exception) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $exception;
        }
    }

    public function archiveEmployee(int $companyId, int $id): void
    {
        $database = Database::connection();
        $database->beginTransaction();
        try {
            $this->employee($companyId, $id);
            $lastAdmin = $database->prepare(
                'SELECT COUNT(*) FROM users target
                 JOIN user_roles target_role ON target_role.user_id = target.id
                 JOIN roles role ON role.id = target_role.role_id AND role.slug = "administrator"
                 WHERE target.employee_id = :employee_id AND target.company_id = :company_id
                   AND target.is_active = 1
                   AND (SELECT COUNT(DISTINCT admin.id) FROM users admin
                        JOIN user_roles ur ON ur.user_id = admin.id
                        JOIN roles ar ON ar.id = ur.role_id AND ar.slug = "administrator"
                        WHERE admin.company_id = :admin_company_id AND admin.is_active = 1
                          AND admin.deleted_at IS NULL) <= 1'
            );
            $lastAdmin->execute([
                'employee_id' => $id,
                'company_id' => $companyId,
                'admin_company_id' => $companyId,
            ]);
            if ((int) $lastAdmin->fetchColumn() > 0) {
                throw new RuntimeException('The employee linked to the final administrator cannot be archived.');
            }
            $database->prepare(
                'UPDATE users SET is_active = 0 WHERE employee_id = :employee_id AND company_id = :company_id'
            )->execute(['employee_id' => $id, 'company_id' => $companyId]);
            $database->prepare(
                'UPDATE employee_shifts SET deleted_at = CURRENT_TIMESTAMP
                 WHERE employee_id = :employee_id AND deleted_at IS NULL AND effective_from > CURRENT_DATE'
            )->execute(['employee_id' => $id]);
            $database->prepare(
                'UPDATE employee_shifts SET effective_to = CURRENT_DATE
                 WHERE employee_id = :employee_id AND deleted_at IS NULL AND effective_to IS NULL
                   AND effective_from <= CURRENT_DATE'
            )->execute(['employee_id' => $id]);
            $database->prepare(
                'UPDATE employees SET employment_status = "terminated", termination_date = CURRENT_DATE,
                 deleted_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND company_id = :company_id'
            )->execute(['id' => $id, 'company_id' => $companyId]);
            $database->commit();
        } catch (\Throwable $exception) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $exception;
        }
    }

    private function assertOwned(int $companyId, string $table, int $id): void
    {
        if (!in_array($table, ['departments', 'shifts'], true)) {
            throw new RuntimeException('Invalid ownership table.');
        }
        $statement = Database::connection()->prepare(
            "SELECT 1 FROM {$table} WHERE id = :id AND company_id = :company_id AND deleted_at IS NULL"
        );
        $statement->execute(['id' => $id, 'company_id' => $companyId]);
        if (!$statement->fetchColumn()) {
            throw new RuntimeException('The selected record does not belong to this company.');
        }
    }

}
