<?php

declare(strict_types=1);

use Elonn\Time\ApiAuthClient;
use Elonn\Time\Database;
use Elonn\Time\Response;
use Elonn\Time\Router;
use Elonn\Time\View;
use Dotenv\Dotenv;

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';

Dotenv::createImmutable(BASE_PATH)->safeLoad();
$config = require BASE_PATH . '/config/config.php';

redirectToHttps();

$apiBaseUrl = $config['services']['api_base_url'];
$router = new Router();

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (isDavPath($requestPath)) {
    handleDavRequest($apiBaseUrl);
    return;
}

$router->get('/health', static function (): void {
    Response::json([
        'status' => 'ok',
        'service' => 'elonn_time',
    ]);
});

$router->get('/ready', static function () use ($config, $apiBaseUrl): void {
    $dependencies = [
        'database' => 'error',
        'api_auth' => 'error',
    ];

    try {
        timePdo($config)->query('SELECT 1');
        $dependencies['database'] = 'connected';
    } catch (Throwable) {
        $dependencies['database'] = 'error';
    }

    try {
        if (apiAuthClient($apiBaseUrl)->ready()) {
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

$router->get('/', static function () use ($config, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $pdo = timePdo($config);
    renderApp('Dashboard', 'home.php', $identity, [
        'calendars' => listCalendars($pdo, $identity['id']),
        'events' => listEvents($pdo, $identity['id'], null),
    ]);
});

$router->get('/runtime/panel/time', static function () use ($config, $apiBaseUrl): void {
    allowRuntimeOrigin();

    $identity = runtimeIdentity($apiBaseUrl);
    if ($identity === null) {
        runtimePanel('Time', '<p class="runtime-time__empty">Authentication required.</p>', 401);
        return;
    }

    $pdo = timePdo($config);
    $calendars = listCalendars($pdo, $identity['id']);
    $events = array_slice(listEvents($pdo, $identity['id'], null), 0, 6);
    $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        || str_contains((string) ($_GET['format'] ?? ''), 'json');

    if ($wantsJson) {
        Response::json([
            'kind' => 'time',
            'view' => 'time',
            'title' => 'Time',
            'identity' => $identity,
            'calendars' => array_map('calendarPayload', $calendars),
            'events' => array_map('eventPayload', $events),
            'actions' => [
                'create_calendar' => '/world/calendars',
            ],
        ]);
        return;
    }

    ob_start();
    ?>
    <div class="runtime-time">
        <header class="runtime-time__header">
            <div>
                <p>Signed in as</p>
                <strong><?= html((string) ($identity['display_name'] ?: $identity['email'])) ?></strong>
            </div>
            <span><?= html((string) $identity['email']) ?></span>
        </header>

        <section class="runtime-time__section">
            <h2>Calendars</h2>
            <?php if ($calendars === []): ?>
                <p class="runtime-time__empty">No calendars yet.</p>
            <?php else: ?>
                <ul class="runtime-time__list">
                    <?php foreach ($calendars as $calendar): ?>
                        <li>
                            <strong><?= html((string) $calendar['name']) ?></strong>
                            <span><?= html((string) ($calendar['timezone'] ?: 'No timezone set')) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="runtime-time__section">
            <h2>Upcoming Events</h2>
            <?php if ($events === []): ?>
                <p class="runtime-time__empty">No events yet.</p>
            <?php else: ?>
                <ul class="runtime-time__list">
                    <?php foreach ($events as $event): ?>
                        <li>
                            <strong><?= html((string) $event['title']) ?></strong>
                            <span><?= html(formatRuntimeEventRange($event)) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <form class="runtime-time__form" data-time-create-calendar>
            <h2>Create Calendar</h2>
            <label>
                <span>Name</span>
                <input name="name" required maxlength="255" autocomplete="off">
            </label>
            <label>
                <span>Timezone</span>
                <input name="timezone" placeholder="America/Los_Angeles" maxlength="64" autocomplete="off">
            </label>
            <button type="submit">Create</button>
            <p data-time-form-status></p>
        </form>
    </div>
    <?php

    runtimePanel('Time', (string) ob_get_clean());
});

$router->post('/runtime/calendars', static function () use ($config, $apiBaseUrl): void {
    allowRuntimeOrigin();

    $identity = runtimeIdentity($apiBaseUrl);
    if ($identity === null) {
        Response::json(['error' => 'Authentication required.'], 401);
        return;
    }

    $input = requestInput();
    $name = cleanString($input['name'] ?? null);
    if ($name === null) {
        Response::json(['error' => 'Calendar name is required.'], 400);
        return;
    }

    $pdo = timePdo($config);
    $stmt = $pdo->prepare(
        "INSERT INTO time_calendars (identity_user_id, name, color, timezone, status, created_at, updated_at)
         VALUES (:identity_user_id, :name, NULL, :timezone, 'active', :created_at, NULL)"
    );
    $stmt->execute([
        ':identity_user_id' => $identity['id'],
        ':name' => $name,
        ':timezone' => cleanOptionalString($input['timezone'] ?? null),
        ':created_at' => now(),
    ]);

    Response::json([
        'calendar' => calendarPayload(findCalendar($pdo, $identity['id'], (int) $pdo->lastInsertId())),
    ], 201);
});

$router->get('/calendars', static function () use ($config, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $calendars = listCalendars(timePdo($config), $identity['id']);

    if (isBrowserRequest()) {
        renderApp('Calendars', 'calendars/index.php', $identity, ['calendars' => $calendars]);
        return;
    }

    Response::json(['calendars' => array_map('calendarPayload', $calendars)]);
});

$router->get('/calendars/new', static function () use ($apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    renderApp('New calendar', 'calendars/new.php', $identity, [
        'error' => null,
        'old' => [],
    ]);
});

$router->post('/calendars', static function () use ($config, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $input = requestInput();
    $name = cleanString($input['name'] ?? null);
    if ($name === null) {
        if (isBrowserRequest()) {
            renderApp('New calendar', 'calendars/new.php', $identity, [
                'error' => 'Calendar name is required.',
                'old' => formOld($input, ['name', 'color', 'timezone']),
            ], 400);
            return;
        }

        Response::json(['error' => 'Calendar name is required.'], 400);
        return;
    }

    $color = cleanOptionalString($input['color'] ?? null);
    $timezone = cleanOptionalString($input['timezone'] ?? null);
    $now = now();

    $stmt = timePdo($config)->prepare(
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

    $calendar = findCalendar(timePdo($config), $identity['id'], (int) timePdo($config)->lastInsertId());

    if (isBrowserRequest()) {
        redirect('/calendars');
        return;
    }

    Response::json(['calendar' => calendarPayload($calendar)], 201);
});

$router->get('/calendars/{id}', static function (array $params) use ($config, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $calendar = findCalendar(timePdo($config), $identity['id'], positiveInt($params['id'] ?? null));
    if ($calendar === null) {
        Response::json(['error' => 'Calendar not found.'], 404);
        return;
    }

    Response::json(['calendar' => calendarPayload($calendar)]);
});

$router->patch('/calendars/{id}', static function (array $params) use ($config, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $pdo = timePdo($config);
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

$router->delete('/calendars/{id}', static function (array $params) use ($config, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $pdo = timePdo($config);
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

$router->get('/events', static function () use ($config, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $calendarId = positiveInt($_GET['calendar_id'] ?? null);
    $events = listEvents(timePdo($config), $identity['id'], $calendarId);

    if (isBrowserRequest()) {
        renderApp('Events', 'events/index.php', $identity, ['events' => $events]);
        return;
    }

    Response::json(['events' => array_map('eventPayload', $events)]);
});

$router->get('/events/new', static function () use ($config, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    renderApp('New event', 'events/new.php', $identity, [
        'error' => null,
        'old' => [],
        'calendars' => listCalendars(timePdo($config), $identity['id']),
    ]);
});

$router->post('/events', static function () use ($config, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $pdo = timePdo($config);
    $input = requestInput();
    $calendarId = positiveInt($input['calendar_id'] ?? null);
    $title = cleanString($input['title'] ?? null);
    $startsAt = normalizeDateTime($input['starts_at'] ?? null);
    $endsAt = normalizeDateTime($input['ends_at'] ?? null);

    if ($calendarId === null || findCalendar($pdo, $identity['id'], $calendarId) === null) {
        if (isBrowserRequest()) {
            renderApp('New event', 'events/new.php', $identity, [
                'error' => 'Valid calendar is required.',
                'old' => formOld($input, ['calendar_id', 'title', 'starts_at', 'ends_at', 'location', 'description']),
                'calendars' => listCalendars($pdo, $identity['id']),
            ], 400);
            return;
        }

        Response::json(['error' => 'Valid calendar_id is required.'], 400);
        return;
    }

    if ($title === null || $startsAt === null || $endsAt === null) {
        if (isBrowserRequest()) {
            renderApp('New event', 'events/new.php', $identity, [
                'error' => 'Title, starts, and ends are required.',
                'old' => formOld($input, ['calendar_id', 'title', 'starts_at', 'ends_at', 'location', 'description']),
                'calendars' => listCalendars($pdo, $identity['id']),
            ], 400);
            return;
        }

        Response::json(['error' => 'Event title, starts_at, and ends_at are required.'], 400);
        return;
    }

    if ($endsAt <= $startsAt) {
        if (isBrowserRequest()) {
            renderApp('New event', 'events/new.php', $identity, [
                'error' => 'Event end must be after start.',
                'old' => formOld($input, ['calendar_id', 'title', 'starts_at', 'ends_at', 'location', 'description']),
                'calendars' => listCalendars($pdo, $identity['id']),
            ], 400);
            return;
        }

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

    if (isBrowserRequest()) {
        redirect('/events');
        return;
    }

    Response::json(['event' => eventPayload($event)], 201);
});

$router->get('/events/{id}', static function (array $params) use ($config, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $event = findEvent(timePdo($config), $identity['id'], positiveInt($params['id'] ?? null));
    if ($event === null) {
        Response::json(['error' => 'Event not found.'], 404);
        return;
    }

    Response::json(['event' => eventPayload($event)]);
});

$router->patch('/events/{id}', static function (array $params) use ($config, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $pdo = timePdo($config);
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

$router->delete('/events/{id}', static function (array $params) use ($config, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $pdo = timePdo($config);
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
    $requestPath
);

/**
 * @param array{database: array{driver:string, host:string, port:int, name:string, username:string, password:string, charset:string}} $config
 */
function timePdo(array $config): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = Database::connect($config['database'])->pdo();
    return $pdo;
}

/**
 * @return array{id: string, email: string, display_name: string|null}|null
 */
function requireIdentity(string $apiBaseUrl): ?array
{
    $token = bearerToken() ?? cookieToken();
    if ($token === null) {
        if (isBrowserRequest()) {
            redirect(accountLoginUrl());
            return null;
        }

        Response::json(['error' => 'Bearer token required.'], 401);
        return null;
    }

    try {
        $identity = apiAuthClient($apiBaseUrl)->identityForToken($token);
    } catch (Throwable) {
        $identity = null;
    }

    if ($identity === null) {
        if (isBrowserRequest()) {
            redirect(accountLoginUrl());
            return null;
        }

        Response::json(['error' => 'Invalid or expired token.'], 401);
        return null;
    }

    return $identity;
}

/**
 * @return array{id: string, email: string, display_name: string|null}|null
 */
function runtimeIdentity(string $apiBaseUrl): ?array
{
    $token = bearerToken() ?? cookieToken();
    if ($token === null) {
        return null;
    }

    try {
        return apiAuthClient($apiBaseUrl)->identityForToken($token);
    } catch (Throwable) {
        return null;
    }
}

function isDavPath(string $path): bool
{
    $path = '/' . trim($path, '/');
    return $path === '/dav' || str_starts_with($path, '/dav/');
}

function handleDavRequest(string $apiBaseUrl): void
{
    $credentials = basicAuthCredentials();
    if ($credentials === null) {
        davUnauthorized();
        return;
    }

    try {
        $identity = apiAuthClient($apiBaseUrl)->identityForDavCredentials(
            $credentials['username'],
            $credentials['password']
        );
    } catch (Throwable) {
        $identity = null;
    }

    if ($identity === null) {
        davUnauthorized();
        return;
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    header('DAV: 1, 3, calendar-access');
    header('MS-Author-Via: DAV');

    if ($method === 'OPTIONS') {
        http_response_code(204);
        header('Allow: OPTIONS, PROPFIND');
        return;
    }

    if ($method === 'PROPFIND') {
        http_response_code(501);
        header('Content-Type: application/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<d:error xmlns:d="DAV:"><d:responsedescription>CalDAV collections are not implemented yet.</d:responsedescription></d:error>';
        return;
    }

    http_response_code(501);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => 'CalDAV collections are not implemented yet.',
        'identity_user_id' => $identity['id'],
    ], JSON_UNESCAPED_SLASHES);
}

/**
 * @return array{username: string, password: string}|null
 */
function basicAuthCredentials(): ?array
{
    $username = $_SERVER['PHP_AUTH_USER'] ?? null;
    $password = $_SERVER['PHP_AUTH_PW'] ?? null;

    if (!is_string($username) || !is_string($password)) {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';
        if (is_string($header) && stripos($header, 'Basic ') === 0) {
            $decoded = base64_decode(substr($header, 6), true);
            if (is_string($decoded) && str_contains($decoded, ':')) {
                [$username, $password] = explode(':', $decoded, 2);
            }
        }
    }

    if (!is_string($username) || !is_string($password) || trim($username) === '' || $password === '') {
        return null;
    }

    return [
        'username' => trim($username),
        'password' => $password,
    ];
}

function davUnauthorized(): void
{
    http_response_code(401);
    header('WWW-Authenticate: Basic realm="Elonn Time DAV"');
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<d:error xmlns:d="DAV:"><d:responsedescription>Authentication required.</d:responsedescription></d:error>';
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

function cookieToken(): ?string
{
    $token = $_COOKIE['elonn_api_token'] ?? null;
    if (!is_string($token)) {
        return null;
    }

    $token = trim($token);
    return $token === '' ? null : $token;
}

function isBrowserRequest(): bool
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (is_string($contentType) && (
        str_contains($contentType, 'application/x-www-form-urlencoded')
        || str_contains($contentType, 'multipart/form-data')
    )) {
        return true;
    }

    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return is_string($accept) && str_contains($accept, 'text/html');
}

/**
 * @return array<string, mixed>
 */
function requestInput(): array
{
    if (isBrowserRequest()) {
        return $_POST;
    }

    return readJsonInput();
}

/**
 * @param array<string, mixed> $input
 * @param array<int, string> $keys
 * @return array<string, string>
 */
function formOld(array $input, array $keys): array
{
    $old = [];
    foreach ($keys as $key) {
        $value = $input[$key] ?? '';
        $old[$key] = is_string($value) ? $value : '';
    }

    return $old;
}

/**
 * @param array{id: string, email: string, display_name: string|null} $identity
 * @param array<string, mixed> $data
 */
function renderApp(string $title, string $contentTemplate, array $identity, array $data = [], int $status = 200): void
{
    View::render('layout.php', [
        'title' => $title,
        'contentTemplate' => $contentTemplate,
        'identity' => $identity,
        'data' => $data,
    ], $status);
}

function redirect(string $path): void
{
    http_response_code(303);
    header('Location: ' . $path);
}

function runtimePanel(string $title, string $body, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');

    ?>
    <section class="runtime-panel-fragment" aria-label="<?= html($title) ?>">
        <?= $body ?>
    </section>
    <?php
}

/**
 * @param array<string, mixed> $event
 */
function formatRuntimeEventRange(array $event): string
{
    try {
        $startsAt = new DateTimeImmutable((string) $event['starts_at']);
        $endsAt = new DateTimeImmutable((string) $event['ends_at']);
    } catch (Throwable) {
        return (string) $event['starts_at'];
    }

    return $startsAt->format('M j, g:i A') . ' - ' . $endsAt->format('g:i A');
}

function html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function allowRuntimeOrigin(): void
{
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $allowed = [
        'https://web.elonn.local',
        'https://web.elonn.com',
        'https://world.elonn.local',
        'https://world.elonn.com',
    ];

    if (in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: true');
    }
}

function redirectToHttps(): void
{
    if (currentScheme() === 'https') {
        return;
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (!is_string($host) || $host === '') {
        return;
    }

    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    http_response_code(308);
    header('Location: https://' . $host . (is_string($uri) ? $uri : '/'));
    exit;
}

function accountLoginUrl(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $loginUrl = str_contains((string) $host, 'elonn.local')
        ? currentScheme() . '://elonn.local/account/login'
        : 'https://elonn.com/account/login';

    return $loginUrl . '?return_to=' . rawurlencode(currentUrl());
}

function currentScheme(): string
{
    return ($_SERVER['HTTPS'] ?? '') === 'on'
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        ? 'https'
        : 'http';
}

function currentUrl(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'time.elonn.local';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    return currentScheme() . '://' . $host . $uri;
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
 * @return array<int, array<string, mixed>>
 */
function listCalendars(PDO $pdo, string $identityUserId): array
{
    $stmt = $pdo->prepare(
        "SELECT id, name, color, timezone, status, created_at, updated_at
         FROM time_calendars
         WHERE identity_user_id = :identity_user_id
           AND status <> 'deleted'
         ORDER BY id"
    );
    $stmt->execute([':identity_user_id' => $identityUserId]);

    return $stmt->fetchAll();
}

/**
 * @return array<int, array<string, mixed>>
 */
function listEvents(PDO $pdo, string $identityUserId, ?int $calendarId): array
{
    $sql = "SELECT id, calendar_id, title, description, location, starts_at, ends_at, timezone, all_day, status, created_at, updated_at
            FROM time_events
            WHERE identity_user_id = :identity_user_id
              AND status <> 'deleted'";
    $params = [':identity_user_id' => $identityUserId];

    if ($calendarId !== null) {
        $sql .= ' AND calendar_id = :calendar_id';
        $params[':calendar_id'] = $calendarId;
    }

    $sql .= ' ORDER BY starts_at, id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
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

function apiAuthClient(string $apiBaseUrl): ApiAuthClient
{
    static $clients = [];
    $apiBaseUrl = rtrim($apiBaseUrl, '/');

    if (!isset($clients[$apiBaseUrl])) {
        $clients[$apiBaseUrl] = new ApiAuthClient($apiBaseUrl);
    }

    return $clients[$apiBaseUrl];
}
