<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class DeviceController extends Controller
{
    public function index(Request $request): Response
    {
        $db = Database::connection();
        $user = Auth::user();

        $devices = $db->prepare(
            'SELECT d.*, CONCAT(e.first_name, " ", e.last_name) AS employee_name
             FROM devices d
             LEFT JOIN employees e ON e.id = d.employee_id
             WHERE d.company_id = :company_id AND d.deleted_at IS NULL
             ORDER BY d.created_at DESC'
        );
        $devices->execute(['company_id' => $user->companyId]);

        $employees = $db->prepare(
            'SELECT id, first_name, last_name
             FROM employees
             WHERE company_id = :company_id AND deleted_at IS NULL
             ORDER BY first_name, last_name'
        );
        $employees->execute(['company_id' => $user->companyId]);

        return $this->view('devices/index', [
            'user' => $user,
            'devices' => $devices->fetchAll(),
            'employees' => $employees->fetchAll(),
            'flash' => Session::pull('_flash'),
            'newToken' => Session::pull('new_device_token'),
        ]);
    }

    public function store(Request $request): Response
    {
        $user = Auth::user();
        $identifier = bin2hex(random_bytes(8));
        $secret = bin2hex(random_bytes(32));
        $hash = password_hash($secret, PASSWORD_DEFAULT);

        $db = Database::connection();

        $stmt = $db->prepare(
            'INSERT INTO devices
             (company_id, employee_id, name, device_uuid, device_type, api_key_identifier, api_key_hash, hostname, operating_system, agent_version, is_active)
             VALUES
             (:company_id, :employee_id, :name, :device_uuid, "windows", :identifier, :hash, :hostname, :os, :agent_version, 1)'
        );

        $stmt->execute([
            'company_id' => $user->companyId,
            'employee_id' => (int) $request->input('employee_id'),
            'name' => trim((string) $request->input('name')),
            'device_uuid' => bin2hex(random_bytes(16)),
            'identifier' => $identifier,
            'hash' => $hash,
            'hostname' => trim((string) $request->input('hostname', '')),
            'os' => trim((string) $request->input('operating_system', 'Windows')),
            'agent_version' => config('app.agent_version', 'unknown'),
        ]);

        Session::put('new_device_token', $identifier . '.' . $secret);
        Session::flash('success', 'Device registered. Copy the token now. It will only be shown once.');

        return Response::redirect(app_url('/devices'));
    }
}
