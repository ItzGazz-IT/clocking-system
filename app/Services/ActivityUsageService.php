<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use DateTimeImmutable;
use DateTimeZone;

final class ActivityUsageService
{
    /** @return array<string, mixed> */
    public function record(int $companyId, int $deviceId, ?int $employeeId, array $data): array
    {
        $key = (string) ($data['idempotency_key'] ?? '');
        if (!preg_match('/^[0-9a-f-]{36}$/i', $key)) {
            throw new HttpException(422, 'A valid idempotency_key UUID is required.');
        }

        $usageType = strtolower(trim((string) ($data['usage_type'] ?? 'app')));
        if (!in_array($usageType, ['app', 'website'], true)) {
            throw new HttpException(422, 'usage_type must be app or website.');
        }

        $appName = substr(trim((string) ($data['app_name'] ?? '')), 0, 120);
        if ($appName === '') {
            throw new HttpException(422, 'app_name is required.');
        }

        $durationSeconds = max(0, (int) ($data['duration_seconds'] ?? 0));
        if ($durationSeconds <= 0 || $durationSeconds > 3600) {
            throw new HttpException(422, 'duration_seconds must be between 1 and 3600.');
        }

        $startedAt = $this->parseTimestamp((string) ($data['started_at'] ?? ''), 'started_at');
        $endedAt = $this->parseTimestamp((string) ($data['ended_at'] ?? ''), 'ended_at');
        if ($endedAt <= $startedAt) {
            throw new HttpException(422, 'ended_at must be after started_at.');
        }

        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $statement = Database::connection()->prepare(
            'INSERT INTO activity_usage_events
             (company_id, employee_id, device_id, usage_type, app_name, window_title, website_domain,
              started_at, ended_at, duration_seconds, idempotency_key, metadata)
             VALUES
             (:company_id, :employee_id, :device_id, :usage_type, :app_name, :window_title, :website_domain,
              :started_at, :ended_at, :duration_seconds, :idempotency_key, :metadata)'
        );

        try {
            $statement->execute([
                'company_id' => $companyId,
                'employee_id' => $employeeId,
                'device_id' => $deviceId,
                'usage_type' => $usageType,
                'app_name' => $appName,
                'window_title' => $this->nullableText($data['window_title'] ?? null, 255),
                'website_domain' => $this->normalizeDomain($data['website_domain'] ?? null),
                'started_at' => $startedAt->format('Y-m-d H:i:s.u'),
                'ended_at' => $endedAt->format('Y-m-d H:i:s.u'),
                'duration_seconds' => $durationSeconds,
                'idempotency_key' => $key,
                'metadata' => json_encode($metadata ?: new \stdClass(), JSON_THROW_ON_ERROR),
            ]);
            $this->touchDevice($deviceId, $metadata);
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }
            return ['idempotent' => true, 'idempotency_key' => $key];
        }

        return [
            'idempotent' => false,
            'event_id' => (int) Database::connection()->lastInsertId(),
        ];
    }

    private function parseTimestamp(string $value, string $field): DateTimeImmutable
    {
        if (trim($value) === '') {
            throw new HttpException(422, $field . ' must be a valid ISO-8601 timestamp.');
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Throwable) {
            throw new HttpException(422, $field . ' must be a valid ISO-8601 timestamp.');
        }
    }

    private function nullableText(mixed $value, int $maxLength): ?string
    {
        $text = substr(trim((string) $value), 0, $maxLength);
        return $text === '' ? null : $text;
    }

    private function normalizeDomain(mixed $value): ?string
    {
        $domain = strtolower(trim((string) $value));
        $domain = preg_replace('/^https?:\/\//', '', $domain) ?? $domain;
        $domain = explode('/', $domain)[0] ?? $domain;
        $domain = substr($domain, 0, 190);

        return $domain === '' ? null : $domain;
    }

    /** @param array<string, mixed> $metadata */
    private function touchDevice(int $deviceId, array $metadata): void
    {
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
            return;
        }

        Database::connection()->prepare(
            'UPDATE devices SET last_seen_at = CURRENT_TIMESTAMP WHERE id = :device_id'
        )->execute(['device_id' => $deviceId]);
    }
}
