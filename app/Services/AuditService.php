<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;

final class AuditService
{
    /** @param array<string, mixed>|null $oldValue @param array<string, mixed>|null $newValue */
    public function record(
        string $action,
        string $entityType,
        ?int $entityId,
        Request $request,
        ?int $userId = null,
        ?array $oldValue = null,
        ?array $newValue = null,
        ?string $reason = null
    ): void {
        $companyId = null;
        if ($userId !== null) {
            $companyStatement = Database::connection()->prepare('SELECT company_id FROM users WHERE id = :id');
            $companyStatement->execute(['id' => $userId]);
            $companyId = $companyStatement->fetchColumn() ?: null;
        }

        $statement = Database::connection()->prepare(
            'INSERT INTO audit_logs
             (company_id, user_id, action, entity_type, entity_id, ip_address, computer_name, user_agent, old_value, new_value, reason)
             VALUES
             (:company_id, :user_id, :action, :entity_type, :entity_id, :ip_address, :computer_name, :user_agent,
              :old_value, :new_value, :reason)'
        );
        $statement->execute([
            'company_id' => $companyId,
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip_address' => $request->ip(),
            'computer_name' => substr((string) $request->input('computer_name', ''), 0, 255) ?: null,
            'user_agent' => $request->userAgent(),
            'old_value' => $oldValue === null ? null : json_encode($this->sanitize($oldValue), JSON_THROW_ON_ERROR),
            'new_value' => $newValue === null ? null : json_encode($this->sanitize($newValue), JSON_THROW_ON_ERROR),
            'reason' => $reason,
        ]);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function sanitize(array $data): array
    {
        $sensitive = [
            '_token', 'password', 'password_confirmation', 'current_password',
            'api_key', 'api_key_hash', 'token', 'token_hash', 'authorization',
        ];
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), $sensitive, true)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitize($value);
            } elseif (is_string($value) && strlen($value) > 4000) {
                $data[$key] = substr($value, 0, 4000) . '…';
            }
        }
        return $data;
    }
}
