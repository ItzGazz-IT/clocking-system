<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuditService;
use App\Services\CompanySettingsService;

final class SettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        return $this->view('settings/index', [
            'user' => $user,
            'settings' => (new CompanySettingsService())->get($user->companyId),
            'errors' => Session::pull('_errors', []),
            'flash' => Session::pull('_flash'),
        ]);
    }

    public function update(Request $request): Response
    {
        $errors = [];
        if (mb_strlen(trim((string) $request->input('name'))) < 2) {
            $errors['name'] = 'Company name must contain at least 2 characters.';
        }
        $timezone = (string) $request->input('timezone');
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            $errors['timezone'] = 'Choose a valid timezone.';
        }
        $email = trim((string) $request->input('email'));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        foreach (['default_grace_minutes', 'default_lunch_minutes', 'overtime_after_minutes', 'absence_after_minutes', 'missing_clock_out_after_minutes'] as $field) {
            $value = filter_var($request->input($field), FILTER_VALIDATE_INT);
            if ($value === false || $value < 0 || $value > 1440) {
                $errors[$field] = 'Enter minutes between 0 and 1440.';
            }
        }
        if ($errors !== []) {
            Session::put('_errors', $errors);
            return Response::redirect(app_url('/settings'));
        }
        $user = Auth::user();
        $before = (new CompanySettingsService())->get($user->companyId);
        (new CompanySettingsService())->update($user->companyId, $user->id, $request->all());
        (new AuditService())->record(
            'settings.updated',
            'company',
            $user->companyId,
            $request,
            $user->id,
            $before,
            ['name' => $request->input('name'), 'timezone' => $timezone]
        );
        Session::flash('success', 'Company settings updated.');
        return Response::redirect(app_url('/settings'));
    }
}
