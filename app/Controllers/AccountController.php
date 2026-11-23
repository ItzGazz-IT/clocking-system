<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Models\User;
use App\Services\AuditService;

final class AccountController extends Controller
{
    public function password(Request $request): Response
    {
        return $this->view('account/password', [
            'user' => Auth::user(),
            'errors' => Session::pull('_errors', []),
            'flash' => Session::pull('_flash'),
        ]);
    }

    public function updatePassword(Request $request): Response
    {
        $errors = Validator::passwordChange($request->all());
        $user = Auth::user() ?? throw new HttpException(401, 'Authentication is required.');
        $users = new User();
        $hash = $users->passwordHash($user->id);
        if (!$hash || !password_verify((string) $request->input('current_password'), $hash)) {
            $errors['current_password'] = 'The current password is incorrect.';
        }
        if ($errors !== []) {
            Session::put('_errors', $errors);
            return Response::redirect(app_url('/account/password'));
        }

        $users->updatePasswordHash(
            $user->id,
            password_hash(
                (string) $request->input('password'),
                Config::get('security.password.algorithm', PASSWORD_DEFAULT),
                (array) Config::get('security.password.options', [])
            )
        );
        Session::put('auth_version', $user->authVersion + 1);
        (new AuditService())->record('account.password_changed', 'user', $user->id, $request, $user->id);
        Session::flash('success', 'Your password has been changed.');
        return Response::redirect(app_url('/account/password'));
    }
}
