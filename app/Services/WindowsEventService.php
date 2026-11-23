<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class WindowsEventService
{
    /** @return array<string, mixed> */
    public function record(int $deviceId, ?int $employeeId, array $data): array
    {
        $code = strtolower(trim((string) ($data['event'] ?? '')));
        $type = Database::connection()->prepare('SELECT id FROM windows_event_types WHERE code = :code');
        $type->execute(['code' => $code]);
        $typeId = $type->fetchColumn();
        if (!$typeId) {
            throw new HttpException(422, 'Unknown Windows event type.');
        }
        $key = (string) ($data['idempotency_key'] ?? '');
        if (!preg_match('/^[0-9a-f-]{36}$/i', $key)) {
            throw new HttpException(422, 'A valid idempotency_key UUID is required.');
        }
        $occurredAt = (string) ($data['occurred_at'] ?? '');
        try {
            $occurred = $occurredAt === ''
                ? new DateTimeImmutable('now', new DateTimeZone('UTC'))
                : (new DateTimeImmutable($occurredAt))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Throwable) {
            throw new HttpException(422, 'occurred_at must be a valid ISO-8601 timestamp.');
        }

        // Validate device integrity: check for timestamp drift and simultaneous devices
        $this->validateWindowsEventIntegrity($deviceId, $employeeId, $occurred);

        $statement = Database::connection()->prepare(
            'INSERT INTO windows_events
             (device_id, employee_id, event_type_id, occurred_at, idempotency_key, idle_seconds, metadata)
             VALUES (:device_id, :employee_id, :event_type_id, :occurred_at, :key, :idle_seconds, :metadata)'
        );
        try {
            $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
            $agentVersion = isset($metadata['version'])
                ? substr(trim((string) $metadata['version']), 0, 40)
                : '';
            if ($agentVersion !== '') {
                Database::connection()->prepare(
                    'UPDATE devices
                     SET agent_version = :agent_version, last_seen_at = CURRENT_TIMESTAMP
                     WHERE id = :device_id'
                )->execute([
                    'agent_version' => $agentVersion,
                    'device_id' => $deviceId,
                ]);
            }

            $statement->execute([
                'device_id' => $deviceId,
                'employee_id' => $employeeId,
                'event_type_id' => $typeId,
                'occurred_at' => $occurred->format('Y-m-d H:i:s.u'),
                'key' => $key,
                'idle_seconds' => isset($data['idle_seconds']) ? max(0, (int) $data['idle_seconds']) : null,
                'metadata' => json_encode($metadata ?: new \stdClass(), JSON_THROW_ON_ERROR),
            ]);
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }
            // The original event may have committed before its projection failed.
            // Rebuild on retry instead of acknowledging a permanently stale day.
            if ($employeeId !== null) (new FlexibleAttendanceService())->processEvent($employeeId, $occurred);
            return ['idempotent' => true, 'idempotency_key' => $key];
        }
        $eventId = (int) Database::connection()->lastInsertId();
        if ($employeeId !== null) {
            (new FlexibleAttendanceService())->processEvent($employeeId, $occurred);
        }
        if ($employeeId !== null && in_array($code, ['agent_exit', 'agent_uninstall'], true)) {
            $device = $this->deviceContext($deviceId);
            if ($device !== null) {
                (new AnomalyDetectionService())->createAgentLifecycleAlert(
                    (int) $device['company_id'],
                    $employeeId,
                    $deviceId,
                    $code,
                    $eventId,
                    $metadata
                );
            }
        }

        return [
            'idempotent' => false,
            'event_id' => $eventId,
            'event' => $code,
            'occurred_at' => $occurred->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed>|null */
    private function deviceContext(int $deviceId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT company_id FROM devices WHERE id = :device_id AND deleted_at IS NULL'
        );
        $statement->execute(['device_id' => $deviceId]);
        $device = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($device) ? $device : null;
    }

    /**
     * Validate device integrity: detect timestamp drift and simultaneous devices
     */
    private function validateWindowsEventIntegrity(int $deviceId, ?int $employeeId, DateTimeImmutable $occurred): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $driftMinutes = $now->getTimestamp() - $occurred->getTimestamp();
        $driftMinutes = abs((int) floor($driftMinutes / 60));

        // The Windows agent keeps a durable offline queue for network outages.
        // Accept up to 14 days of delayed telemetry so recovered records can
        // rebuild flexible attendance using their original timestamps.
        if ($driftMinutes > 20160 || $driftMinutes < -60) {
            throw new HttpException(
                422,
                'Event timestamp is significantly out of sync. Device time sync is required. (Drift: ' . $driftMinutes . ' minutes)'
            );
        }

        // If employee is assigned, check for simultaneous activity on other devices
        if ($employeeId !== null) {
            $simCheck = Database::connection()->prepare(
                'SELECT COUNT(DISTINCT device_id) as device_count
                 FROM windows_events
                 WHERE employee_id = :employee_id
                 AND device_id != :device_id
                 AND received_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)'
            );
            $simCheck->execute(['employee_id' => $employeeId, 'device_id' => $deviceId]);
            $result = $simCheck->fetch(\PDO::FETCH_ASSOC);
            
            if ($result && (int) $result['device_count'] > 0) {
                throw new HttpException(
                    422,
                    'Simultaneous events from multiple devices. Only one device should be active per employee.'
                );
            }
        }
    }
}
