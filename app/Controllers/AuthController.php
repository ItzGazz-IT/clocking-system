<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Services\AuditService;
use App\Services\AuthenticationService;

final class AuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        if (DatabaseSetupController::needsDatabase()) {
            return Response::redirect(app_url('/setup/database'));
        }

        return $this->view('auth/login', [
            'errors' => Session::pull('_errors', []),
            'flash' => Session::pull('_flash'),
            'setupOpen' => $this->setupIsOpen(),
        ]);
    }

    public function login(Request $request): Response
    {
        if (DatabaseSetupController::needsDatabase()) {
            return Response::redirect(app_url('/setup/database'));
        }

        $input = $request->all();
        $errors = Validator::login($input);
        if ($errors !== []) {
            Session::put('_errors', $errors);
            Session::put('_old_input', ['username' => (string) ($input['username'] ?? '')]);
            return Response::redirect((string) config('app.url') . '/login');
        }

        $authenticated = (new AuthenticationService())->attempt(
            (string) $input['username'],
            (string) $input['password'],
            $request->ip()
        );

        if (!$authenticated) {
            (new AuditService())->record(
                'auth.login_failed',
                'user',
                null,
                $request,
                null,
                null,
                ['username' => strtolower((string) $input['username'])],
                'Invalid credentials or rate limit'
            );
            Session::put('_errors', ['credentials' => 'The credentials are invalid or login is temporarily restricted.']);
            return Response::redirect((string) config('app.url') . '/login');
        }

        Session::forget('_errors');
        Session::forget('_old_input');
        (new AuditService())->record('auth.login', 'user', Auth::user()?->id, $request, Auth::user()?->id);
        return Response::redirect((string) config('app.url') . '/dashboard');
    }

    public function logout(Request $request): Response
    {
        $userId = Auth::user()?->id;
        (new AuditService())->record('auth.logout', 'user', $userId, $request, $userId);
        Auth::logout();
        return Response::redirect((string) config('app.url') . '/login');
    }

    private function setupIsOpen(): bool
    {
        $pdo = Database::connection();
        $companyCount = (int) $pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn();
        $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

        return $companyCount === 0 && $userCount === 0;
    }
}
