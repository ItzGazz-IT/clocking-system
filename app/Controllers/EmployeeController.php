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
use App\Services\EmployeeAccessService;
use PDOException;
use RuntimeException;

final class EmployeeController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $catalog = new CatalogService();
        $accessibleIds = $user->can('attendance.view.all')
            ? null
            : array_column((new EmployeeAccessService())->accessibleEmployees($user), 'id');
        return $this->view('employees/index', [
            'user' => $user,
            'employees' => $catalog->employees($user->companyId, $accessibleIds),
            'departments' => $catalog->departments($user->companyId),
            'shifts' => $catalog->shifts($user->companyId),
            'errors' => Session::pull('_errors', []),
            'old' => Session::pull('_old_input', []),
            'flash' => Session::pull('_flash'),
        ]);
    }

    public function store(Request $request): Response
    {
        $errors = Validator::employee($request->all());
        if ($errors !== []) {
            Session::put('_errors', $errors);
            Session::put('_old_input', $request->all());
            return Response::redirect(app_url('/employees'));
        }
        try {
            $id = (new CatalogService())->createEmployee(Auth::user()->companyId, $request->all());
        } catch (PDOException) {
            Session::put('_errors', ['employee_number' => 'That employee number is already in use.']);
            Session::put('_old_input', $request->all());
            return Response::redirect(app_url('/employees'));
        }
        (new AuditService())->record('employee.created', 'employee', $id, $request, Auth::user()->id, null, $request->all());
        Session::flash('success', 'Employee created.');
        return Response::redirect(app_url('/employees'));
    }

    public function edit(Request $request, string $id): Response
    {
        $user = Auth::user();
        $catalog = new CatalogService();
        try {
            $employee = $catalog->employee($user->companyId, (int) $id);
        } catch (RuntimeException $exception) {
            Session::flash('error', $exception->getMessage());
            return Response::redirect(app_url('/employees'));
        }
        return $this->view('employees/edit', [
            'user' => $user,
            'employee' => $employee,
            'departments' => $catalog->departments($user->companyId),
            'shifts' => $catalog->shifts($user->companyId),
            'errors' => Session::pull('_errors', []),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $errors = Validator::employee($request->all());
        if ($errors !== []) {
            Session::put('_errors', $errors);
            return Response::redirect(app_url('/employees/' . $id . '/edit'));
        }
        try {
            (new CatalogService())->updateEmployee(Auth::user()->companyId, (int) $id, $request->all());
        } catch (PDOException) {
            Session::put('_errors', ['employee_number' => 'That employee number is already in use.']);
            return Response::redirect(app_url('/employees/' . $id . '/edit'));
        } catch (RuntimeException $exception) {
            Session::flash('error', $exception->getMessage());
            return Response::redirect(app_url('/employees'));
        }
        (new AuditService())->record('employee.updated', 'employee', (int) $id, $request, Auth::user()->id, null, $request->all());
        Session::flash('success', 'Employee updated.');
        return Response::redirect(app_url('/employees'));
    }

    public function archive(Request $request, string $id): Response
    {
        try {
            (new CatalogService())->archiveEmployee(Auth::user()->companyId, (int) $id);
            (new AuditService())->record('employee.archived', 'employee', (int) $id, $request, Auth::user()->id);
            Session::flash('success', 'Employee and linked login archived.');
        } catch (RuntimeException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        return Response::redirect(app_url('/employees'));
    }
}
