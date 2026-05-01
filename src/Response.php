<?php

declare(strict_types=1);

namespace Elonn\Time;

final class Response
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }
}
