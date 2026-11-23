<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Auth\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuthenticationService;

final class DeviceEnrollmentController extends Controller
{
    public function enroll(Request $request): Response
    {
        $username = trim((string) $request->input('username', $request->input('email', '')));
        $password = (string) $request->input('password', '');
        if ($username === '' || $password === '') {
            throw new HttpException(422, 'Username and password are required.');
        }

        $authenticated = (new AuthenticationService())->attempt($username, $password, $request->ip());
        $user = Auth::user();
        if (!$authenticated || !$user) {
            throw new HttpException(401, 'The credentials are invalid or login is temporarily restricted.');
        }

        if ($user->mustChangePassword) {
            Auth::logout();
            throw new HttpException(403, 'Change your password in the web app before linking this device.');
        }

        if (!$user->employeeId) {
            Auth::logout();
            throw new HttpException(422, 'This user is not linked to an employee.');
        }

        $identifier = bin2hex(random_bytes(8));
        $secret = bin2hex(random_bytes(32));
        $token = $identifier . '.' . $secret;
        $hostname = substr(trim((string) $request->input('hostname', '')), 0, 255);
        $operatingSystem = substr(trim((string) $request->input('operating_system', 'Windows')), 0, 160);
        $agentVersion = substr(trim((string) $request->input('agent_version', config('app.agent_version', 'unknown'))), 0, 40);
        $deviceUuid = $this->deviceUuid($request);

        $db = Database::connection();
        $existing = $db->prepare(
            'SELECT id FROM devices
             WHERE deleted_at IS NULL
               AND (
                    device_uuid = :device_uuid
                    OR (company_id = :company_id AND employee_id = :employee_id AND hostname = :hostname)
               )
             LIMIT 1'
        );
        $existing->execute([
            'device_uuid' => $deviceUuid,
            'company_id' => $user->companyId,
            'employee_id' => $user->employeeId,
            'hostname' => $hostname,
        ]);
        $deviceId = $existing->fetchColumn();

        if ($deviceId) {
            $update = $db->prepare(
                'UPDATE devices
                 SET company_id = :company_id, employee_id = :employee_id,
                     name = :name, device_uuid = :device_uuid, device_type = "windows",
                     api_key_identifier = :identifier, api_key_hash = :hash,
                     hostname = :hostname, operating_system = :os, agent_version = :agent_version,
                     is_active = 1, last_seen_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $update->execute([
                'id' => $deviceId,
                'company_id' => $user->companyId,
                'employee_id' => $user->employeeId,
                'name' => $this->deviceName($hostname, $user->name),
                'device_uuid' => $deviceUuid,
                'identifier' => $identifier,
                'hash' => password_hash($secret, PASSWORD_DEFAULT),
                'hostname' => $hostname,
                'os' => $operatingSystem,
                'agent_version' => $agentVersion,
            ]);
        } else {
            $insert = $db->prepare(
                'INSERT INTO devices
                 (company_id, employee_id, name, device_uuid, device_type, api_key_identifier,
                  api_key_hash, hostname, operating_system, agent_version, is_active, last_seen_at)
                 VALUES
                 (:company_id, :employee_id, :name, :device_uuid, "windows", :identifier,
                  :hash, :hostname, :os, :agent_version, 1, CURRENT_TIMESTAMP)'
            );
            $insert->execute([
                'company_id' => $user->companyId,
                'employee_id' => $user->employeeId,
                'name' => $this->deviceName($hostname, $user->name),
                'device_uuid' => $deviceUuid,
                'identifier' => $identifier,
                'hash' => password_hash($secret, PASSWORD_DEFAULT),
                'hostname' => $hostname,
                'os' => $operatingSystem,
                'agent_version' => $agentVersion,
            ]);
        }

        Auth::logout();

        return $this->json([
            'data' => [
                'token' => 'Bearer ' . $token,
                'hostname' => $hostname,
            ],
        ], 201);
    }

    private function deviceUuid(Request $request): string
    {
        $value = trim((string) $request->input('device_uuid', ''));
        if (preg_match('/^[0-9a-f-]{32,36}$/i', $value) === 1) {
            return $value;
        }

        return bin2hex(random_bytes(16));
    }

    private function deviceName(string $hostname, string $employeeName): string
    {
        $hostname = $hostname === '' ? 'Windows PC' : $hostname;
        return substr($employeeName . ' - ' . $hostname, 0, 160);
    }
}
