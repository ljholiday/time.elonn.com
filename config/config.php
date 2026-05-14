<?php

declare(strict_types=1);

return [
    'app' => [
        'environment' => time_string_config('APP_ENV', 'production'),
        'debug' => time_bool_config('APP_DEBUG', false),
        'url' => rtrim(time_string_config('APP_URL', 'https://time.elonn.com'), '/'),
    ],
    'database' => [
        'driver' => 'mysql',
        'host' => time_string_config('DB_HOST', '127.0.0.1'),
        'port' => time_int_config('DB_PORT', 3306),
        'name' => time_string_config('DB_DATABASE', time_string_config('DB_NAME', '')),
        'username' => time_string_config('DB_USERNAME', time_string_config('DB_USER', '')),
        'password' => time_string_config('DB_PASSWORD', time_string_config('DB_PASS', '')),
        'charset' => time_string_config('DB_CHARSET', 'utf8mb4'),
    ],
    'services' => [
        'api_base_url' => rtrim(time_string_config('ELONN_API_BASE_URL', 'https://api.elonn.com'), '/'),
    ],
];

function time_string_config(string $key, string $default = ''): string
{
    $value = $_SERVER[$key] ?? $_ENV[$key] ?? $default;
    return trim((string) $value);
}

function time_int_config(string $key, int $default): int
{
    $value = $_SERVER[$key] ?? $_ENV[$key] ?? $default;
    return max(1, (int) $value);
}

function time_bool_config(string $key, bool $default): bool
{
    $value = $_SERVER[$key] ?? $_ENV[$key] ?? $default;
    if (is_bool($value)) {
        return $value;
    }

    return filter_var($value, FILTER_VALIDATE_BOOL);
}
