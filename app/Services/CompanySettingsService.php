<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Throwable;

final class CompanySettingsService
{
    /** @return array<string, mixed> */
    public function get(int $companyId): array
    {
        $company = Database::connection()->prepare(
            'SELECT id, name, timezone, email, phone
             FROM companies WHERE id = :id AND deleted_at IS NULL'
        );
        $company->execute(['id' => $companyId]);
        $result = $company->fetch() ?: [];

        $settings = Database::connection()->prepare(
            'SELECT setting_key, value FROM settings WHERE company_id = :company_id
             AND setting_key IN (
                "attendance.default_grace_minutes",
                "attendance.default_lunch_minutes",
                "attendance.overtime_after_minutes",
                "attendance.absence_after_minutes",
                "attendance.missing_clock_out_after_minutes"
             )'
        );
        $settings->execute(['company_id' => $companyId]);
        foreach ($settings->fetchAll() as $setting) {
            $result[$setting['setting_key']] = json_decode((string) $setting['value'], true);
        }
        return $result;
    }

    public function update(int $companyId, int $userId, array $data): void
    {
        $database = Database::connection();
        $database->beginTransaction();
        try {
            $company = $database->prepare(
                'UPDATE companies SET name = :name, timezone = :timezone,
                 email = :email, phone = :phone WHERE id = :id'
            );
            $company->execute([
                'name' => trim((string) $data['name']),
                'timezone' => (string) $data['timezone'],
                'email' => trim((string) ($data['email'] ?? '')) ?: null,
                'phone' => trim((string) ($data['phone'] ?? '')) ?: null,
                'id' => $companyId,
            ]);
            $statement = $database->prepare(
                'INSERT INTO settings (company_id, setting_key, value, value_type, updated_by_user_id)
                 VALUES (:company_id, :key, :value, "integer", :user_id)
                 ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by_user_id = VALUES(updated_by_user_id)'
            );
            foreach ([
                'attendance.default_grace_minutes' => 'default_grace_minutes',
                'attendance.default_lunch_minutes' => 'default_lunch_minutes',
                'attendance.overtime_after_minutes' => 'overtime_after_minutes',
                'attendance.absence_after_minutes' => 'absence_after_minutes',
                'attendance.missing_clock_out_after_minutes' => 'missing_clock_out_after_minutes',
            ] as $key => $field) {
                $statement->execute([
                    'company_id' => $companyId,
                    'key' => $key,
                    'value' => json_encode((int) $data[$field], JSON_THROW_ON_ERROR),
                    'user_id' => $userId,
                ]);
            }
            $database->commit();
        } catch (Throwable $exception) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $exception;
        }
    }
}
