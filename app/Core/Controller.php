<?php

declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    /** @param array<string, mixed> $data */
    protected function view(string $template, array $data = [], int $status = 200): Response
    {
        return new Response(View::render($template, $data), $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** @param array<string, mixed> $data */
    protected function json(array $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }
}

