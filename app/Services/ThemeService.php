<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class ThemeService
{
    private const DEFAULTS = [
        'theme.primary_color' => '#0f8b6f',
        'theme.accent_color' => '#1aa7a1',
        'theme.sidebar_color' => '#10201c',
        'theme.text_color' => '#1d2a27',
    ];

    /** @return array<string, string> */
    public function colors(int $companyId): array
    {
        $colors = self::DEFAULTS;
        $statement = Database::connection()->prepare(
            'SELECT setting_key, value FROM settings
             WHERE company_id = :company_id
               AND setting_key IN ("theme.primary_color", "theme.accent_color", "theme.sidebar_color", "theme.text_color")'
        );
        $statement->execute(['company_id' => $companyId]);
        foreach ($statement->fetchAll() as $setting) {
            $value = json_decode((string) $setting['value'], true);
            if (is_string($value) && $this->isHex($value)) {
                $colors[$setting['setting_key']] = strtolower($value);
            }
        }

        return $colors;
    }

    public function cssVariables(int $companyId): string
    {
        return '<link rel="stylesheet" href="' . e(app_url('/theme.css')) . '">';
    }

    public function cssRules(int $companyId): string
    {
        $colors = $this->colors($companyId);
        $primary = $colors['theme.primary_color'];
        $accent = $colors['theme.accent_color'];
        $sidebar = $colors['theme.sidebar_color'];
        $text = $colors['theme.text_color'];

        return sprintf(
            ':root{--brand:%1$s;--brand-dark:%2$s;--brand-soft:%3$s;--cyan:%4$s;--navy-950:%5$s;--navy-900:%6$s;--navy-800:%7$s;--ink:%8$s;--heading:%9$s;}',
            $primary,
            $this->shade($primary, -18),
            $this->soft($primary),
            $accent,
            $sidebar,
            $this->shade($sidebar, 12),
            $this->shade($sidebar, 22),
            $text,
            $this->shade($text, -8)
        );
    }

    private function isHex(string $value): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
    }

    private function shade(string $hex, int $percent): string
    {
        [$r, $g, $b] = $this->rgb($hex);
        $adjust = static fn (int $channel): int => max(0, min(255, (int) round(
            $percent >= 0
                ? $channel + (255 - $channel) * ($percent / 100)
                : $channel * (1 + $percent / 100)
        )));

        return sprintf('#%02x%02x%02x', $adjust($r), $adjust($g), $adjust($b));
    }

    private function soft(string $hex): string
    {
        [$r, $g, $b] = $this->rgb($hex);
        return sprintf('#%02x%02x%02x',
            (int) round($r + (255 - $r) * .9),
            (int) round($g + (255 - $g) * .9),
            (int) round($b + (255 - $b) * .9)
        );
    }

    /** @return array{int,int,int} */
    private function rgb(string $hex): array
    {
        return [
            hexdec(substr($hex, 1, 2)),
            hexdec(substr($hex, 3, 2)),
            hexdec(substr($hex, 5, 2)),
        ];
    }
}
