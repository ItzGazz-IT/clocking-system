<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;

final class WindowsActivityController extends Controller
{
    public function index(Request $request): Response
    {
        $employeeId = filter_var($request->input('employee_id'), FILTER_VALIDATE_INT);
        $query = $employeeId === false ? '' : '?employee_id=' . (int) $employeeId . '#evidence-log';

        return Response::redirect(app_url('/alerts' . $query));
    }
}
