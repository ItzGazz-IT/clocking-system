<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

final class DownloadController extends Controller
{
    public function clockingAgent(Request $request): Response
    {
        $version = preg_replace('/[^0-9A-Za-z.\-_]/', '', (string) config('app.agent_version', '1.1.1')) ?: '1.1.1';
        $filePath = BASE_PATH . '/public/downloads/Time-Attendance-Agent-Setup.exe';
        $downloadName = 'Time-Attendance-Agent-Setup.exe';

        if (!is_file($filePath)) {
            throw new HttpException(404, 'The Windows installer is not available yet.');
        }

        return Response::redirect(app_url('/download/' . $downloadName . '?v=' . rawurlencode($version)));
    }
}
