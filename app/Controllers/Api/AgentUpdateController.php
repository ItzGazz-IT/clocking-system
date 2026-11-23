<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;

final class AgentUpdateController extends Controller
{
    public function show(Request $request): Response
    {
        $version = (string) config('app.agent_version', '1.1.1');
        $downloadName = 'Time-Attendance-Agent-Setup.exe';
        $installerPath = BASE_PATH . '/public/downloads/' . $downloadName;

        return $this->json([
            'data' => [
                'version' => $version,
                'download_url' => app_url('/download/' . $downloadName . '?v=' . rawurlencode($version)),
                'sha256' => is_file($installerPath) ? hash_file('sha256', $installerPath) : null,
                'required' => false,
            ],
        ]);
    }
}
