<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuditService;
use App\Services\UserAccountService;
use PDOException;

final class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $accounts = new UserAccountService();
        return $this->view('users/index', [
            'user' => $user,
            'accounts' => $accounts->accounts($user->companyId),
            'employees' => $accounts->availableEmployees($user->companyId),
            'roles' => $accounts->roles(),
            'passwordResetRequests' => $accounts->passwordResetRequests($user->companyId),
            'errors' => Session::pull('_errors', []),
            'old' => Session::pull('_old_input', []),
            'flash' => Session::pull('_flash'),
            'temporaryPassword' => Session::pull('_temporary_password'),
            'temporaryUsername' => Session::pull('_temporary_username'),
        ]);
    }

    public function store(Request $request): Response
    {
        $errors = [];
        if (filter_var($request->input('employee_id'), FILTER_VALIDATE_INT) === false) {
            $errors['employee_id'] = 'Choose an employee.';
        }
        if (!preg_match('/^[A-Za-z0-9_.-]{2,80}$/', trim((string) $request->input('username')))) {
            $errors['username'] = 'Use 2-80 letters, numbers, dots, dashes or underscores.';
        }
        if (filter_var($request->input('role_id'), FILTER_VALIDATE_INT) === false) {
            $errors['role_id'] = 'Choose a role.';
        }
        if ($errors !== []) {
            Session::put('_errors', $errors);
            Session::put('_old_input', $request->all());
            return Response::redirect(app_url('/users'));
        }

        $user = Auth::user();
        try {
            $result = (new UserAccountService())->provision($user->companyId, $user->id, $request->all());
        } catch (PDOException) {
            Session::put('_errors', ['username' => 'That username is already registered.']);
            Session::put('_old_input', $request->all());
            return Response::redirect(app_url('/users'));
        } catch (HttpException $exception) {
            Session::flash('error', $exception->getMessage());
            return Response::redirect(app_url('/users'));
        }
        (new AuditService())->record(
            'user.provisioned',
            'user',
            $result['user_id'],
            $request,
            $user->id,
            null,
            ['employee_id' => (int) $request->input('employee_id'), 'username' => $request->input('username')]
        );
        Session::put('_temporary_password', $result['temporary_password']);
        Session::put('_temporary_username', strtolower(trim((string) $request->input('username'))));
        Session::flash('success', 'User account created. Copy the temporary credentials now.');
        return Response::redirect(app_url('/users'));
    }

    public function resetPassword(Request $request, string $id): Response
    {
        $user = Auth::user();
        try {
            $password = (new UserAccountService())->resetPassword($user->companyId, (int) $id);
        } catch (HttpException $exception) {
            Session::flash('error', $exception->getMessage());
            return Response::redirect(app_url('/users'));
        }
        \App\Core\Database::connection()
            ->prepare('UPDATE password_reset_requests SET status = "completed", reviewed_at = CURRENT_TIMESTAMP, reviewed_by_user_id = :reviewer WHERE user_id = :user_id AND status = "pending"')
            ->execute(['reviewer' => $user->id, 'user_id' => (int) $id]);
        (new AuditService())->record('user.password_reset', 'user', (int) $id, $request, $user->id);
        Session::put('_temporary_password', $password);
        Session::put('_temporary_username', (string) $request->input('username'));
        Session::flash('success', 'Password reset. Copy the temporary credentials now.');
        return Response::redirect(app_url('/users'));
    }

    public function toggle(Request $request, string $id): Response
    {
        $user = Auth::user();
        try {
            $active = (new UserAccountService())->toggleActive($user->companyId, (int) $id, $user->id);
        } catch (HttpException $exception) {
            Session::flash('error', $exception->getMessage());
            return Response::redirect(app_url('/users'));
        }
        (new AuditService())->record(
            $active ? 'user.enabled' : 'user.disabled',
            'user',
            (int) $id,
            $request,
            $user->id,
            null,
            ['is_active' => $active]
        );
        Session::flash('success', $active ? 'User enabled.' : 'User disabled.');
        return Response::redirect(app_url('/users'));
    }

    public function updateRole(Request $request, string $id): Response
    {
        $roleId = filter_var($request->input('role_id'), FILTER_VALIDATE_INT);
        if ($roleId === false) {
            Session::flash('error', 'Choose a valid role.');
            return Response::redirect(app_url('/users'));
        }
        $user = Auth::user();
        try {
            (new UserAccountService())->updateRole($user->companyId, (int) $id, (int) $roleId, $user->id);
        } catch (HttpException $exception) {
            Session::flash('error', $exception->getMessage());
            return Response::redirect(app_url('/users'));
        }
        (new AuditService())->record(
            'user.role_changed',
            'user',
            (int) $id,
            $request,
            $user->id,
            null,
            ['role_id' => (int) $roleId]
        );
        Session::flash('success', 'User role updated.');
        return Response::redirect(app_url('/users'));
    }
}
