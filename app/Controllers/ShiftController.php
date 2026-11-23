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

final class ShiftController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        return $this->view('shifts/index', [
            'user' => $user,
            'shifts' => (new CatalogService())->shifts($user->companyId),
            'errors' => Session::pull('_errors', []),
            'old' => Session::pull('_old_input', []),
            'flash' => Session::pull('_flash'),
        ]);
    }

    public function store(Request $request): Response
    {
        $errors = Validator::shift($request->all());
        if ($errors !== []) {
            Session::put('_errors', $errors);
            Session::put('_old_input', $request->all());
            return Response::redirect(app_url('/shifts'));
        }
        try {
            $id = (new CatalogService())->createShift(Auth::user()->companyId, $request->all());
        } catch (PDOException) {
            Session::put('_errors', ['code' => 'That shift code is already in use.']);
            Session::put('_old_input', $request->all());
            return Response::redirect(app_url('/shifts'));
        }
        (new AuditService())->record('shift.created', 'shift', $id, $request, Auth::user()->id, null, $request->all());
        Session::flash('success', 'Shift created.');
        return Response::redirect(app_url('/shifts'));
    }

    public function edit(Request $request, string $id): Response
    {
        try {
            $shift = (new CatalogService())->shift(Auth::user()->companyId, (int) $id);
        } catch (RuntimeException $exception) {
            Session::flash('error', $exception->getMessage());
            return Response::redirect(app_url('/shifts'));
        }
        return $this->view('shifts/edit', [
            'user' => Auth::user(),
            'shift' => $shift,
            'errors' => Session::pull('_errors', []),
            'old' => Session::pull('_old_input', []),
            'flash' => Session::pull('_flash'),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $errors = Validator::shift($request->all());
        if ($errors !== []) {
            Session::put('_errors', $errors);
            Session::put('_old_input', $request->all());
            Session::flash('error', 'The shift was not saved. Correct the highlighted fields and try again.');
            return Response::redirect(app_url('/shifts/' . $id . '/edit'));
        }
        try {
            $catalog = new CatalogService();
            $before = $catalog->shift(Auth::user()->companyId, (int) $id);
            $catalog->updateShift(Auth::user()->companyId, (int) $id, $request->all());
            $after = $catalog->shift(Auth::user()->companyId, (int) $id);
        } catch (PDOException) {
            Session::put('_errors', ['code' => 'That shift code is already in use.']);
            Session::put('_old_input', $request->all());
            Session::flash('error', 'The shift was not saved. Check the shift code and try again.');
            return Response::redirect(app_url('/shifts/' . $id . '/edit'));
        } catch (RuntimeException $exception) {
            Session::flash('error', $exception->getMessage());
            return Response::redirect(app_url('/shifts'));
        }
        (new AuditService())->record('shift.updated', 'shift', (int) $id, $request, Auth::user()->id, $before, $after);
        Session::flash('success', 'Shift updated to ' . substr((string) $after['start_time'], 0, 5) . '–' . substr((string) $after['end_time'], 0, 5) . '.');
        return Response::redirect(app_url('/shifts'));
    }

    public function archive(Request $request, string $id): Response
    {
        try {
            (new CatalogService())->archiveShift(Auth::user()->companyId, (int) $id);
            (new AuditService())->record('shift.archived', 'shift', (int) $id, $request, Auth::user()->id);
            Session::flash('success', 'Shift archived.');
        } catch (RuntimeException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        return Response::redirect(app_url('/shifts'));
    }
}
