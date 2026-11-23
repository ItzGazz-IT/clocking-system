<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class ForgotPasswordController extends Controller
{
    public function show(Request $request): Response
    {
        return $this->view('auth/forgot-password', [
            'errors' => Session::pull('_errors', []),
            'flash' => Session::pull('_flash'),
        ]);
    }

    public function store(Request $request): Response
    {
        $username = strtolower(trim((string) $request->input('username', '')));
        if (!preg_match('/^[A-Za-z0-9_.-]{2,80}$/', $username)) {
            Session::put('_errors', ['username' => 'Enter your username.']);
            Session::put('_old_input', ['username' => $username]);
            return Response::redirect(app_url('/forgot-password'));
        }

        $db = Database::connection();
        $user = $db->prepare(
            'SELECT id, company_id FROM users
             WHERE username = :username AND is_active = 1 AND deleted_at IS NULL
             LIMIT 1'
        );
        $user->execute(['username' => $username]);
        $userRow = $user->fetch() ?: null;

        $insert = $db->prepare(
            'INSERT INTO password_reset_requests
             (company_id, user_id, username, ip_address, user_agent)
             VALUES (:company_id, :user_id, :username, :ip_address, :user_agent)'
        );
        $insert->execute([
            'company_id' => $userRow['company_id'] ?? null,
            'user_id' => $userRow['id'] ?? null,
            'username' => $username,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        Session::forget('_old_input');
        Session::flash('success', 'Your password reset request has been sent to an administrator.');
        return Response::redirect(app_url('/forgot-password'));
    }
}
