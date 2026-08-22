<?php

declare(strict_types=1);

$environment = time_string_config('APP_ENV', 'production');

return [
    'app' => [
        'environment' => $environment,
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
        'social_base_url' => time_service_base_url('ELONN_SOCIAL_BASE_URL', $environment, 'https://social.elonn.local', 'https://social.elonn.com'),
        'social_ingest_token' => time_string_config('ELONN_SOCIAL_INGEST_TOKEN', ''),
    ],
    'service_auth' => [
        'mind.elonn' => time_string_config('ELONN_MIND_TIME_TOKEN'),
        'admin.elonn' => time_string_config('ELONN_ADMIN_TIME_TOKEN'),
        'conductor_keys_url' => rtrim(
            time_string_config(
                'CONDUCTOR_SERVICE_KEYS_URL',
                time_peer_url('conductor', $environment) . '/.well-known/elonn-service-keys.json'
            ),
            '/'
        ),
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

function time_service_base_url(string $key, string $environment, string $localDefault, string $productionDefault): string
{
    $default = in_array(strtolower($environment), ['local', 'development', 'dev', 'testing'], true)
        ? $localDefault
        : $productionDefault;

    return rtrim(time_string_config($key, $default), '/');
}

/**
 * Derives a compliant peer's address purely from its name and this Service's own environment
 * classification -- never a separately hand-typed URL literal to keep in sync per Service.
 */
function time_peer_url(string $peerName, string $environment): string
{
    $local = in_array(strtolower($environment), ['local', 'development', 'dev', 'testing'], true);

    return 'https://' . $peerName . '.elonn.' . ($local ? 'local' : 'com');
}
