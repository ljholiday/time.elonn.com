<?php

declare(strict_types=1);

use Elonn\Time\ApiAuthClient;
use Elonn\Time\Database;
use Elonn\Time\Env;
use Elonn\Time\Response;
use Elonn\Time\Router;

define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'Elonn\\Time\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $path = BASE_PATH . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$envPath = BASE_PATH . '/config/.env';
$env = Env::load($envPath);
$apiBaseUrl = $env['ELONN_API_BASE_URL'] ?? 'http://api.elonn.local';
$router = new Router();

$router->get('/health', static function (): void {
    Response::json([
        'status' => 'ok',
        'service' => 'elonn_time',
    ]);
});

$router->get('/ready', static function () use ($envPath, $apiBaseUrl): void {
    $dependencies = [
        'database' => 'error',
        'api_auth' => 'error',
    ];

    try {
        timePdo($envPath)->query('SELECT 1');
        $dependencies['database'] = 'connected';
    } catch (Throwable) {
        $dependencies['database'] = 'error';
    }

    try {
        if ((new ApiAuthClient($apiBaseUrl))->ready()) {
            $dependencies['api_auth'] = 'connected';
        }
    } catch (Throwable) {
        $dependencies['api_auth'] = 'error';
    }

    $ready = $dependencies['database'] === 'connected'
        && $dependencies['api_auth'] === 'connected';

    Response::json([
        'status' => $ready ? 'ready' : 'not_ready',
        'service' => 'elonn_time',
        'dependencies' => $dependencies,
    ], $ready ? 200 : 500);
});

$router->get('/calendars', static function () use ($envPath, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $stmt = timePdo($envPath)->prepare(
        "SELECT id, name, color, timezone, status, created_at, updated_at
         FROM time_calendars
         WHERE identity_user_id = :identity_user_id
           AND status <> 'deleted'
         ORDER BY id"
    );
    $stmt->execute([':identity_user_id' => $identity['id']]);

    Response::json(['calendars' => array_map('calendarPayload', $stmt->fetchAll())]);
});

$router->post('/calendars', static function () use ($envPath, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $input = readJsonInput();
    $name = cleanString($input['name'] ?? null);
    if ($name === null) {
        Response::json(['error' => 'Calendar name is required.'], 400);
        return;
    }

    $color = cleanOptionalString($input['color'] ?? null);
    $timezone = cleanOptionalString($input['timezone'] ?? null);
    $now = now();

    $stmt = timePdo($envPath)->prepare(
        "INSERT INTO time_calendars (identity_user_id, name, color, timezone, status, created_at, updated_at)
         VALUES (:identity_user_id, :name, :color, :timezone, 'active', :created_at, NULL)"
    );
    $stmt->execute([
        ':identity_user_id' => $identity['id'],
        ':name' => $name,
        ':color' => $color,
        ':timezone' => $timezone,
        ':created_at' => $now,
    ]);

    $calendar = findCalendar(timePdo($envPath), $identity['id'], (int) timePdo($envPath)->lastInsertId());
    Response::json(['calendar' => calendarPayload($calendar)], 201);
});

$router->get('/calendars/{id}', static function (array $params) use ($envPath, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $calendar = findCalendar(timePdo($envPath), $identity['id'], positiveInt($params['id'] ?? null));
    if ($calendar === null) {
        Response::json(['error' => 'Calendar not found.'], 404);
        return;
    }

    Response::json(['calendar' => calendarPayload($calendar)]);
});

$router->patch('/calendars/{id}', static function (array $params) use ($envPath, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $pdo = timePdo($envPath);
    $calendarId = positiveInt($params['id'] ?? null);
    $calendar = findCalendar($pdo, $identity['id'], $calendarId);
    if ($calendar === null) {
        Response::json(['error' => 'Calendar not found.'], 404);
        return;
    }

    $input = readJsonInput();
    $name = array_key_exists('name', $input) ? cleanString($input['name']) : (string) $calendar['name'];
    if ($name === null) {
        Response::json(['error' => 'Calendar name must not be empty.'], 400);
        return;
    }

    $color = array_key_exists('color', $input) ? cleanOptionalString($input['color']) : $calendar['color'];
    $timezone = array_key_exists('timezone', $input) ? cleanOptionalString($input['timezone']) : $calendar['timezone'];
    $status = array_key_exists('status', $input) ? cleanString($input['status']) : (string) $calendar['status'];
    if (!in_array($status, ['active', 'archived'], true)) {
        Response::json(['error' => 'Calendar status must be active or archived.'], 400);
        return;
    }

    $stmt = $pdo->prepare(
        "UPDATE time_calendars
         SET name = :name, color = :color, timezone = :timezone, status = :status, updated_at = :updated_at
         WHERE id = :id AND identity_user_id = :identity_user_id"
    );
    $stmt->execute([
        ':name' => $name,
        ':color' => $color,
        ':timezone' => $timezone,
        ':status' => $status,
        ':updated_at' => now(),
        ':id' => $calendarId,
        ':identity_user_id' => $identity['id'],
    ]);

    Response::json(['calendar' => calendarPayload(findCalendar($pdo, $identity['id'], $calendarId))]);
});

$router->delete('/calendars/{id}', static function (array $params) use ($envPath, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $pdo = timePdo($envPath);
    $calendarId = positiveInt($params['id'] ?? null);
    $calendar = findCalendar($pdo, $identity['id'], $calendarId);
    if ($calendar === null) {
        Response::json(['error' => 'Calendar not found.'], 404);
        return;
    }

    $stmt = $pdo->prepare(
        "UPDATE time_calendars
         SET status = 'deleted', updated_at = :updated_at
         WHERE id = :id AND identity_user_id = :identity_user_id"
    );
    $stmt->execute([
        ':updated_at' => now(),
        ':id' => $calendarId,
        ':identity_user_id' => $identity['id'],
    ]);

    Response::json(['status' => 'deleted']);
});

$router->get('/events', static function () use ($envPath, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $calendarId = positiveInt($_GET['calendar_id'] ?? null);
    $sql = "SELECT id, calendar_id, title, description, location, starts_at, ends_at, timezone, all_day, status, created_at, updated_at
            FROM time_events
            WHERE identity_user_id = :identity_user_id
              AND status <> 'deleted'";
    $params = [':identity_user_id' => $identity['id']];

    if ($calendarId !== null) {
        $sql .= ' AND calendar_id = :calendar_id';
        $params[':calendar_id'] = $calendarId;
    }

    $sql .= ' ORDER BY starts_at, id';
    $stmt = timePdo($envPath)->prepare($sql);
    $stmt->execute($params);

    Response::json(['events' => array_map('eventPayload', $stmt->fetchAll())]);
});

$router->post('/events', static function () use ($envPath, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $pdo = timePdo($envPath);
    $input = readJsonInput();
    $calendarId = positiveInt($input['calendar_id'] ?? null);
    $title = cleanString($input['title'] ?? null);
    $startsAt = normalizeDateTime($input['starts_at'] ?? null);
    $endsAt = normalizeDateTime($input['ends_at'] ?? null);

    if ($calendarId === null || findCalendar($pdo, $identity['id'], $calendarId) === null) {
        Response::json(['error' => 'Valid calendar_id is required.'], 400);
        return;
    }

    if ($title === null || $startsAt === null || $endsAt === null) {
        Response::json(['error' => 'Event title, starts_at, and ends_at are required.'], 400);
        return;
    }

    if ($endsAt <= $startsAt) {
        Response::json(['error' => 'Event ends_at must be after starts_at.'], 400);
        return;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO time_events
            (identity_user_id, calendar_id, title, description, location, starts_at, ends_at, timezone, all_day, status, created_at, updated_at)
         VALUES
            (:identity_user_id, :calendar_id, :title, :description, :location, :starts_at, :ends_at, :timezone, :all_day, 'active', :created_at, NULL)"
    );
    $stmt->execute([
        ':identity_user_id' => $identity['id'],
        ':calendar_id' => $calendarId,
        ':title' => $title,
        ':description' => cleanOptionalString($input['description'] ?? null),
        ':location' => cleanOptionalString($input['location'] ?? null),
        ':starts_at' => $startsAt,
        ':ends_at' => $endsAt,
        ':timezone' => cleanOptionalString($input['timezone'] ?? null),
        ':all_day' => truthy($input['all_day'] ?? false) ? 1 : 0,
        ':created_at' => now(),
    ]);

    $event = findEvent($pdo, $identity['id'], (int) $pdo->lastInsertId());
    Response::json(['event' => eventPayload($event)], 201);
});

$router->get('/events/{id}', static function (array $params) use ($envPath, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $event = findEvent(timePdo($envPath), $identity['id'], positiveInt($params['id'] ?? null));
    if ($event === null) {
        Response::json(['error' => 'Event not found.'], 404);
        return;
    }

    Response::json(['event' => eventPayload($event)]);
});

$router->patch('/events/{id}', static function (array $params) use ($envPath, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $pdo = timePdo($envPath);
    $eventId = positiveInt($params['id'] ?? null);
    $event = findEvent($pdo, $identity['id'], $eventId);
    if ($event === null) {
        Response::json(['error' => 'Event not found.'], 404);
        return;
    }

    $input = readJsonInput();
    $calendarId = array_key_exists('calendar_id', $input) ? positiveInt($input['calendar_id']) : (int) $event['calendar_id'];
    if ($calendarId === null || findCalendar($pdo, $identity['id'], $calendarId) === null) {
        Response::json(['error' => 'Valid calendar_id is required.'], 400);
        return;
    }

    $title = array_key_exists('title', $input) ? cleanString($input['title']) : (string) $event['title'];
    $startsAt = array_key_exists('starts_at', $input) ? normalizeDateTime($input['starts_at']) : (string) $event['starts_at'];
    $endsAt = array_key_exists('ends_at', $input) ? normalizeDateTime($input['ends_at']) : (string) $event['ends_at'];
    if ($title === null || $startsAt === null || $endsAt === null) {
        Response::json(['error' => 'Event title, starts_at, and ends_at must not be empty.'], 400);
        return;
    }

    if ($endsAt <= $startsAt) {
        Response::json(['error' => 'Event ends_at must be after starts_at.'], 400);
        return;
    }

    $status = array_key_exists('status', $input) ? cleanString($input['status']) : (string) $event['status'];
    if (!in_array($status, ['active', 'cancelled'], true)) {
        Response::json(['error' => 'Event status must be active or cancelled.'], 400);
        return;
    }

    $stmt = $pdo->prepare(
        "UPDATE time_events
         SET calendar_id = :calendar_id,
             title = :title,
             description = :description,
             location = :location,
             starts_at = :starts_at,
             ends_at = :ends_at,
             timezone = :timezone,
             all_day = :all_day,
             status = :status,
             updated_at = :updated_at
         WHERE id = :id AND identity_user_id = :identity_user_id"
    );
    $stmt->execute([
        ':calendar_id' => $calendarId,
        ':title' => $title,
        ':description' => array_key_exists('description', $input) ? cleanOptionalString($input['description']) : $event['description'],
        ':location' => array_key_exists('location', $input) ? cleanOptionalString($input['location']) : $event['location'],
        ':starts_at' => $startsAt,
        ':ends_at' => $endsAt,
        ':timezone' => array_key_exists('timezone', $input) ? cleanOptionalString($input['timezone']) : $event['timezone'],
        ':all_day' => array_key_exists('all_day', $input) ? (truthy($input['all_day']) ? 1 : 0) : (int) $event['all_day'],
        ':status' => $status,
        ':updated_at' => now(),
        ':id' => $eventId,
        ':identity_user_id' => $identity['id'],
    ]);

    Response::json(['event' => eventPayload(findEvent($pdo, $identity['id'], $eventId))]);
});

$router->delete('/events/{id}', static function (array $params) use ($envPath, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $pdo = timePdo($envPath);
    $eventId = positiveInt($params['id'] ?? null);
    $event = findEvent($pdo, $identity['id'], $eventId);
    if ($event === null) {
        Response::json(['error' => 'Event not found.'], 404);
        return;
    }

    $stmt = $pdo->prepare(
        "UPDATE time_events
         SET status = 'deleted', updated_at = :updated_at
         WHERE id = :id AND identity_user_id = :identity_user_id"
    );
    $stmt->execute([
        ':updated_at' => now(),
        ':id' => $eventId,
        ':identity_user_id' => $identity['id'],
    ]);

    Response::json(['status' => 'deleted']);
});

$router->dispatch(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'
);

function timePdo(string $envPath): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = Database::fromEnv($envPath)->pdo();
    return $pdo;
}

/**
 * @return array{id: string, email: string}|null
 */
function requireIdentity(string $apiBaseUrl): ?array
{
    $token = bearerToken();
    if ($token === null) {
        Response::json(['error' => 'Bearer token required.'], 401);
        return null;
    }

    try {
        $identity = (new ApiAuthClient($apiBaseUrl))->identityForToken($token);
    } catch (Throwable) {
        $identity = null;
    }

    if ($identity === null) {
        Response::json(['error' => 'Invalid or expired token.'], 401);
        return null;
    }

    return $identity;
}

function bearerToken(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if (!is_string($header) || stripos($header, 'Bearer ') !== 0) {
        return null;
    }

    $token = trim(substr($header, 7));
    return $token === '' ? null : $token;
}

/**
 * @return array<string, mixed>
 */
function readJsonInput(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function cleanString(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);
    return $value === '' ? null : $value;
}

function cleanOptionalString(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }

    return cleanString($value);
}

function positiveInt(mixed $value): ?int
{
    if (is_int($value) && $value > 0) {
        return $value;
    }

    if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
        return (int) $value;
    }

    return null;
}

function normalizeDateTime(mixed $value): ?string
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    try {
        return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}

function truthy(mixed $value): bool
{
    return $value === true || $value === 1 || $value === '1' || $value === 'true';
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * @return array<string, mixed>|null
 */
function findCalendar(PDO $pdo, string $identityUserId, ?int $id): ?array
{
    if ($id === null) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT id, name, color, timezone, status, created_at, updated_at
         FROM time_calendars
         WHERE id = :id AND identity_user_id = :identity_user_id AND status <> 'deleted'
         LIMIT 1"
    );
    $stmt->execute([
        ':id' => $id,
        ':identity_user_id' => $identityUserId,
    ]);

    $calendar = $stmt->fetch();
    return is_array($calendar) ? $calendar : null;
}

/**
 * @return array<string, mixed>|null
 */
function findEvent(PDO $pdo, string $identityUserId, ?int $id): ?array
{
    if ($id === null) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT id, calendar_id, title, description, location, starts_at, ends_at, timezone, all_day, status, created_at, updated_at
         FROM time_events
         WHERE id = :id AND identity_user_id = :identity_user_id AND status <> 'deleted'
         LIMIT 1"
    );
    $stmt->execute([
        ':id' => $id,
        ':identity_user_id' => $identityUserId,
    ]);

    $event = $stmt->fetch();
    return is_array($event) ? $event : null;
}

/**
 * @param array<string, mixed>|null $calendar
 * @return array<string, mixed>
 */
function calendarPayload(?array $calendar): array
{
    if ($calendar === null) {
        return [];
    }

    return [
        'id' => (int) $calendar['id'],
        'name' => (string) $calendar['name'],
        'color' => $calendar['color'] === null ? null : (string) $calendar['color'],
        'timezone' => $calendar['timezone'] === null ? null : (string) $calendar['timezone'],
        'status' => (string) $calendar['status'],
        'created_at' => (string) $calendar['created_at'],
        'updated_at' => $calendar['updated_at'] === null ? null : (string) $calendar['updated_at'],
    ];
}

/**
 * @param array<string, mixed>|null $event
 * @return array<string, mixed>
 */
function eventPayload(?array $event): array
{
    if ($event === null) {
        return [];
    }

    return [
        'id' => (int) $event['id'],
        'calendar_id' => (int) $event['calendar_id'],
        'title' => (string) $event['title'],
        'description' => $event['description'] === null ? null : (string) $event['description'],
        'location' => $event['location'] === null ? null : (string) $event['location'],
        'starts_at' => (string) $event['starts_at'],
        'ends_at' => (string) $event['ends_at'],
        'timezone' => $event['timezone'] === null ? null : (string) $event['timezone'],
        'all_day' => (bool) $event['all_day'],
        'status' => (string) $event['status'],
        'created_at' => (string) $event['created_at'],
        'updated_at' => $event['updated_at'] === null ? null : (string) $event['updated_at'],
    ];
}
