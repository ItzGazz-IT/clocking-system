<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\EmployeeActivityReportService;
use App\Services\EmployeeActivityPdfService;

final class EmployeeActivityReportController extends Controller
{
    public function show(Request $request, string $id): Response
    {
        $user = Auth::user();
        $report = $this->report($request, (int) $id);
        if ($report === null) {
            return $this->view('errors/http', ['status' => 404, 'message' => 'Employee not found.'], 404);
        }

        return $this->view('reports/employee-activity', ['user' => $user, 'report' => $report]);
    }

    public function pdf(Request $request, string $id): Response
    {
        $report = $this->report($request, (int) $id);
        if ($report === null) {
            return new Response('Employee not found.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $content = (new EmployeeActivityPdfService())->render($report);
        $name = preg_replace('/[^a-z0-9-]+/i', '-', strtolower((string) $report['employee']['employee_name'])) ?: 'employee';

        return new Response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="employee-activity-' . trim($name, '-') . '-' . $report['start'] . '-to-' . $report['end'] . '.pdf"',
        ]);
    }

    /** @return array<string,mixed>|null */
    private function report(Request $request, int $employeeId): ?array
    {
        $user = Auth::user();
        return (new EmployeeActivityReportService())->report(
            $user->companyId,
            $employeeId,
            $request->input('start_date') ? (string) $request->input('start_date') : null,
            $request->input('end_date') ? (string) $request->input('end_date') : null
        );
    }

}
