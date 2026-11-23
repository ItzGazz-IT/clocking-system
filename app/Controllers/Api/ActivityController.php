<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityUsageService;

final class ActivityController extends Controller
{
    public function store(Request $request): Response
    {
        $deviceId = $request->attribute('device_id');
        $companyId = $request->attribute('device_company_id');
        if (!$deviceId || !$companyId) {
            throw new HttpException(403, 'Activity events require device authentication.');
        }

        $result = (new ActivityUsageService())->record(
            (int) $companyId,
            (int) $deviceId,
            $request->attribute('device_employee_id'),
            $request->all()
        );

        return $this->json(['data' => $result], $result['idempotent'] ? 200 : 201);
    }
}
