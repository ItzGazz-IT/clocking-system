<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly string $content = '',
        private readonly int $status = 200,
        private readonly array $headers = [],
        private readonly ?string $filePath = null
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            (string) json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    /** @param array<string, string> $headers */
    public static function download(string $filePath, string $downloadName, string $contentType, array $headers = []): self
    {
        return new self('', 200, array_merge([
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="' . addcslashes($downloadName, '"\\') . '"',
            'Content-Length' => (string) filesize($filePath),
            'Cache-Control' => 'private, max-age=86400',
            'Accept-Ranges' => 'bytes',
        ], $headers), $filePath);
    }

    public function send(): never
    {
        http_response_code($this->status);
        $securityHeaders = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'",
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
        if (($_SERVER['HTTPS'] ?? 'off') !== 'off') {
            $securityHeaders['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        foreach ($securityHeaders as $name => $value) {
            header($name . ': ' . $value);
        }
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($this->filePath !== null) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            $handle = fopen($this->filePath, 'rb');
            if ($handle !== false) {
                while (!feof($handle)) {
                    echo fread($handle, 1048576);
                    flush();
                }
                fclose($handle);
            }
            exit;
        }
        echo $this->content;
        exit;
    }
}
