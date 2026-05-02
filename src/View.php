<?php

declare(strict_types=1);

namespace Elonn\Time;

final class View
{
    /**
     * @param array<string, mixed> $data
     */
    public static function render(string $template, array $data = [], int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        extract($data, EXTR_OVERWRITE);
        require dirname(__DIR__) . '/templates/' . ltrim($template, '/');
    }
}
