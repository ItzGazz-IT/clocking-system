<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;

final class FaviconController extends Controller
{
    public function svg(Request $request): Response
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="15" fill="#086bd8"/><circle cx="32" cy="32" r="21" fill="#fff"/><circle cx="32" cy="32" r="17" fill="none" stroke="#dbeeff" stroke-width="2"/><path d="M32 20v13l9 6" fill="none" stroke="#071b3f" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/><circle cx="32" cy="32" r="3" fill="#19a9f5"/></svg>';

        return new Response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }

}
