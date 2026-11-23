<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuditService;
use PDO;

final class OvertimeApprovalController extends Controller
{
    public function index(Request $request): Response
    {
        $statement = Database::connection()->prepare(
            'SELECT fad.*, CONCAT(e.first_name," ",e.last_name) employee_name, e.employee_number
             FROM flexible_attendance_days fad JOIN employees e ON e.id=fad.employee_id
             WHERE fad.company_id=:company_id AND fad.overtime_minutes > 0
             ORDER BY FIELD(fad.overtime_status,"pending","approved","rejected"), fad.work_date DESC LIMIT 250'
        );
        $statement->execute(['company_id' => Auth::user()->companyId]);
        return $this->view('overtime/index', [
            'user' => Auth::user(), 'items' => $statement->fetchAll(PDO::FETCH_ASSOC),
            'flash' => Session::pull('_flash'),
        ]);
    }

    public function review(Request $request, string $id): Response
    {
        // Use neutral field values here. Some managed-hosting WAF rule sets treat
        // generic POST fields such as action=approved as a suspicious command.
        $decision = (string) $request->input(
            'review_choice',
            $request->input('decision', $request->input('action', ''))
        );
        $action = match ($decision) {
            'yes', 'accept', 'approved' => 'approved',
            'no', 'decline', 'rejected' => 'rejected',
            default => throw new HttpException(422, 'Choose approve or reject.'),
        };
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $find = $db->prepare('SELECT * FROM flexible_attendance_days WHERE id=:id AND company_id=:company_id FOR UPDATE');
            $find->execute(['id' => (int) $id, 'company_id' => Auth::user()->companyId]);
            $item = $find->fetch(PDO::FETCH_ASSOC);
            if (!$item) throw new HttpException(404, 'Overtime item not found.');
            $db->prepare(
                'UPDATE flexible_attendance_days SET overtime_status=:status, overtime_reviewed_by_user_id=:user_id,
                 overtime_reviewed_at=NOW(), overtime_review_notes=:notes WHERE id=:id'
            )->execute([
                'status' => $action, 'user_id' => Auth::user()->id,
                'notes' => substr(trim((string) $request->input('review_note', '')), 0, 500) ?: null, 'id' => $item['id'],
            ]);
            $db->prepare('UPDATE attendance SET calculated_overtime_minutes=:minutes WHERE id=:id')->execute([
                'minutes' => $action === 'approved' ? (int) $item['overtime_minutes'] : 0,
                'id' => $item['attendance_id'],
            ]);
            (new AuditService())->record('overtime.' . $action, 'flexible_attendance_day', (int) $item['id'], $request, Auth::user()->id, $item, ['status' => $action]);
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
        Session::flash('success', 'Overtime ' . $action . '.');
        return Response::redirect(app_url('/overtime-approvals'));
    }
}
