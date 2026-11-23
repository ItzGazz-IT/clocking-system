<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Services\AuditService;
use App\Services\CatalogService;
use PDOException;
use RuntimeException;

final class DepartmentController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        return $this->view('departments/index', [
            'user' => $user,
            'departments' => (new CatalogService())->departments($user->companyId),
            'errors' => Session::pull('_errors', []),
            'old' => Session::pull('_old_input', []),
            'flash' => Session::pull('_flash'),
        ]);
    }

    public function store(Request $request): Response
    {
        $errors = Validator::department($request->all());
        if ($errors !== []) {
            Session::put('_errors', $errors);
            Session::put('_old_input', $request->all());
            return Response::redirect(app_url('/departments'));
        }
        try {
            $id = (new CatalogService())->createDepartment(Auth::user()->companyId, $request->all());
        } catch (PDOException) {
            Session::put('_errors', ['code' => 'That department code is already in use.']);
            Session::put('_old_input', $request->all());
            return Response::redirect(app_url('/departments'));
        }
        (new AuditService())->record('department.created', 'department', $id, $request, Auth::user()->id, null, $request->all());
        Session::flash('success', 'Department created.');
        return Response::redirect(app_url('/departments'));
    }

    public function edit(Request $request, string $id): Response
    {
        $user = Auth::user();
        $catalog = new CatalogService();
        try {
            $department = $catalog->department($user->companyId, (int) $id);
        } catch (RuntimeException $exception) {
            Session::flash('error', $exception->getMessage());
            return Response::redirect(app_url('/departments'));
        }
        return $this->view('departments/edit', [
            'user' => $user,
            'department' => $department,
            'errors' => Session::pull('_errors', []),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $errors = Validator::department($request->all());
        if ($errors !== []) {
            Session::put('_errors', $errors);
            return Response::redirect(app_url('/departments/' . $id . '/edit'));
        }
        try {
            (new CatalogService())->updateDepartment(Auth::user()->companyId, (int) $id, $request->all());
        } catch (PDOException) {
            Session::put('_errors', ['code' => 'That department code is already in use.']);
            return Response::redirect(app_url('/departments/' . $id . '/edit'));
        } catch (RuntimeException $exception) {
            Session::flash('error', $exception->getMessage());
            return Response::redirect(app_url('/departments'));
        }
        (new AuditService())->record('department.updated', 'department', (int) $id, $request, Auth::user()->id, null, $request->all());
        Session::flash('success', 'Department updated.');
        return Response::redirect(app_url('/departments'));
    }

    public function archive(Request $request, string $id): Response
    {
        try {
            (new CatalogService())->archiveDepartment(Auth::user()->companyId, (int) $id);
            (new AuditService())->record('department.archived', 'department', (int) $id, $request, Auth::user()->id);
            Session::flash('success', 'Department archived.');
        } catch (RuntimeException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        return Response::redirect(app_url('/departments'));
    }
}
