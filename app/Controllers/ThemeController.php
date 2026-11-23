<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\ThemeService;

final class ThemeController extends Controller
{
    public function css(Request $request): Response
    {
        $user = Auth::user();

        return new Response(
            (new ThemeService())->cssRules($user->companyId),
            200,
            [
                'Content-Type' => 'text/css; charset=utf-8',
                'Cache-Control' => 'no-store, max-age=0',
            ]
        );
    }
}
