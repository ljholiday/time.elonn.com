<?php

declare(strict_types=1);

use Elonn\Time\ApiAuthClient;
use Elonn\Time\CalendarObject;
use Elonn\Time\CalendarStore;
use Elonn\Time\Dav\AuthBackend as DavAuthBackend;
use Elonn\Time\Dav\CalendarBackend as DavCalendarBackend;
use Elonn\Time\Dav\PrincipalBackend as DavPrincipalBackend;
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
    handleDavRequest($config, $apiBaseUrl, davBaseUri($requestPath));
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
    } catch (Throwable $e) {
        error_log('[time] /ready DB check failed: ' . $e->getMessage());
        $dependencies['database'] = 'error';
    }

    try {
        if (apiAuthClient($apiBaseUrl)->ready()) {
            $dependencies['api_auth'] = 'connected';
        }
    } catch (Throwable $e) {
        error_log('[time] /ready API auth check failed: ' . $e->getMessage());
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

$router->post('/integrations/social/events', static function () use ($config): void {
    if (!requireSocialIngestToken($config)) {
        Response::json(['error' => 'Forbidden.'], 403);
        return;
    }

    $input = requestInput();
    $identityUserIds = socialIngestRecipientIds($input['recipient_ids'] ?? null);
    $identityUserId = cleanString($input['identity_user_id'] ?? null);
    $eventInput = is_array($input['event'] ?? null) ? $input['event'] : null;
    if ($eventInput === null) {
        Response::json(['error' => 'event is required.'], 400);
        return;
    }

    if ($identityUserIds === [] && $identityUserId !== null) {
        $identityUserIds = [$identityUserId];
    }

    if ($identityUserIds === []) {
        Response::json(['error' => 'recipient_ids or identity_user_id is required.'], 400);
        return;
    }

    try {
        $pdo = timePdo($config);
        $results = [];
        foreach ($identityUserIds as $recipientIdentityUserId) {
            $calendar = ensureSocialImportCalendar($pdo, $config, $recipientIdentityUserId);
            $event = upsertSocialEvent($pdo, $config, $calendar, $recipientIdentityUserId, $eventInput);
            $results[] = [
                'identity_user_id' => $recipientIdentityUserId,
                'calendar' => calendarPayload($calendar),
                'event' => eventPayload($event),
            ];
        }

        Response::json(['results' => $results], 201);
    } catch (Throwable $throwable) {
        error_log('[time] social event ingest failed: ' . $throwable->getMessage());
        Response::json(['error' => 'Unable to ingest social event.'], 500);
    }
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
    $view = strtolower(trim((string) ($_GET['view'] ?? 'day')));
    $view = in_array($view, ['day', 'week', 'month', 'agenda', 'tasks'], true) ? $view : 'day';
    $anchorDate = validDate((string) ($_GET['date'] ?? '')) ?? gmdate('Y-m-d');
    $timezone = validTimezone((string) ($_GET['timezone'] ?? '')) ?? 'UTC';
    $workspace = (new CalendarStore($pdo))->workspace((string) $identity['id'], $view, $anchorDate, $timezone);
    $calendars = $workspace['calendars'];
    $events = $workspace['appointments'];
    $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        || str_contains((string) ($_GET['format'] ?? ''), 'json');

    if ($wantsJson) {
        Response::json($workspace + [
            'title' => 'Time',
            'summary' => 'Appointments and tasks from Time.',
            'identity' => $identity,
            'nav' => [
                ['id' => 'day',       'label' => 'Day',       'action' => '/world/panels/time?view=day&date=' . rawurlencode($anchorDate)],
                ['id' => 'week',      'label' => 'Week',      'action' => '/world/panels/time?view=week&date=' . rawurlencode($anchorDate)],
                ['id' => 'month',     'label' => 'Month',     'action' => '/world/panels/time?view=month&date=' . rawurlencode($anchorDate)],
                ['id' => 'agenda',    'label' => 'Agenda',    'action' => '/world/panels/time?view=agenda&date=' . rawurlencode($anchorDate)],
                ['id' => 'tasks',     'label' => 'Tasks',     'action' => '/world/panels/time?view=tasks&date=' . rawurlencode($anchorDate)],
                ['id' => 'calendars', 'label' => 'Calendars', 'action' => '/world/calendars'],
            ],
            'actions' => [
                'create_calendar' => '/world/calendars',
                'create_object' => '/world/time/objects',
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

$router->get('/objects', static function () use ($config, $apiBaseUrl): void {
    allowRuntimeOrigin();

    $identity = runtimeIdentity($apiBaseUrl);
    if ($identity === null) {
        Response::json(['error' => 'Authentication required.'], 401);
        return;
    }

    Response::json([
        'objects' => (new CalendarStore(timePdo($config)))->objectSources((string) $identity['id']),
    ]);
});

$router->post('/runtime/objects', static function () use ($config, $apiBaseUrl): void {
    allowRuntimeOrigin();
    $identity = runtimeIdentity($apiBaseUrl);
    if ($identity === null) {
        Response::json(['error' => 'Authentication required.'], 401);
        return;
    }
    try {
        $object = (new CalendarStore(timePdo($config)))->create((string) $identity['id'], requestInput());
        Response::json(['object' => $object], 201);
    } catch (InvalidArgumentException $error) {
        Response::json(['error' => $error->getMessage()], 422);
    } catch (Throwable $error) {
        error_log('[time] unable to create calendar object: ' . $error->getMessage());
        Response::json(['error' => 'Unable to create calendar object.'], 500);
    }
});

$router->patch('/runtime/objects/{id}', static function (array $params) use ($config, $apiBaseUrl): void {
    allowRuntimeOrigin();
    $identity = runtimeIdentity($apiBaseUrl);
    if ($identity === null) {
        Response::json(['error' => 'Authentication required.'], 401);
        return;
    }
    try {
        $object = (new CalendarStore(timePdo($config)))->update(
            (string) $identity['id'],
            positiveInt($params['id'] ?? null) ?? 0,
            requestInput()
        );
        Response::json(['object' => $object]);
    } catch (DomainException $error) {
        Response::json(['error' => $error->getMessage()], 409);
    } catch (InvalidArgumentException $error) {
        Response::json(['error' => $error->getMessage()], 422);
    } catch (Throwable $error) {
        error_log('[time] unable to update calendar object: ' . $error->getMessage());
        Response::json(['error' => 'Unable to update calendar object.'], 500);
    }
});

$router->delete('/runtime/objects/{id}', static function (array $params) use ($config, $apiBaseUrl): void {
    allowRuntimeOrigin();
    $identity = runtimeIdentity($apiBaseUrl);
    if ($identity === null) {
        Response::json(['error' => 'Authentication required.'], 401);
        return;
    }
    try {
        (new CalendarStore(timePdo($config)))->delete(
            (string) $identity['id'],
            positiveInt($params['id'] ?? null) ?? 0
        );
        Response::json(['status' => 'deleted']);
    } catch (DomainException $error) {
        Response::json(['error' => $error->getMessage()], 409);
    }
});

$router->get('/runtime/status', static function () use ($config, $apiBaseUrl): void {
    allowRuntimeOrigin();

    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $pdo = timePdo($config);
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM time_events
         WHERE identity_user_id = :identity_user_id
           AND starts_at > NOW()
           AND starts_at <= DATE_ADD(NOW(), INTERVAL 7 DAY)
           AND status = 'active'"
    );
    $stmt->execute(['identity_user_id' => $identity['id']]);
    $upcomingCount = (int) $stmt->fetchColumn();

    Response::json([
        'upcoming_events' => $upcomingCount,
    ]);
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
        "INSERT INTO time_calendars (identity_user_id, uri, name, description, color, timezone, components, sync_token, status, created_at, updated_at)
         VALUES (:identity_user_id, :uri, :name, :description, :color, :timezone, 'VEVENT,VTODO', 1, 'active', :created_at, NULL)"
    );
    $stmt->execute([
        ':identity_user_id' => $identity['id'],
        ':uri' => calendarUri($name),
        ':name' => $name,
        ':description' => cleanOptionalString($input['description'] ?? null),
        ':color' => cleanOptionalString($input['color'] ?? null),
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
        "INSERT INTO time_calendars (identity_user_id, uri, name, color, timezone, components, sync_token, status, created_at, updated_at)
         VALUES (:identity_user_id, :uri, :name, :color, :timezone, 'VEVENT,VTODO', 1, 'active', :created_at, NULL)"
    );
    $stmt->execute([
        ':identity_user_id' => $identity['id'],
        ':uri' => calendarUri($name),
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

    $description = array_key_exists('description', $input) ? cleanOptionalString($input['description']) : $calendar['description'];
    $color = array_key_exists('color', $input) ? cleanOptionalString($input['color']) : $calendar['color'];
    $timezone = array_key_exists('timezone', $input) ? cleanOptionalString($input['timezone']) : $calendar['timezone'];
    $status = array_key_exists('status', $input) ? cleanString($input['status']) : (string) $calendar['status'];
    if (!in_array($status, ['active', 'archived'], true)) {
        Response::json(['error' => 'Calendar status must be active or archived.'], 400);
        return;
    }

    $stmt = $pdo->prepare(
        "UPDATE time_calendars
         SET name = :name, description = :description, color = :color, timezone = :timezone, status = :status, updated_at = :updated_at
         WHERE id = :id AND identity_user_id = :identity_user_id"
    );
    $stmt->execute([
        ':name' => $name,
        ':description' => $description,
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
    if (($calendar['source_service'] ?? null) === 'social') {
        Response::json(['error' => 'The Social mirror calendar cannot be deleted from Time.'], 409);
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
    if ($event !== null) {
        syncLegacyEventObject($pdo, $event);
    }

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

    $updated = findEvent($pdo, $identity['id'], $eventId);
    if ($updated !== null) {
        syncLegacyEventObject($pdo, $updated);
    }
    Response::json(['event' => eventPayload($updated)]);
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
    $event['status'] = 'deleted';
    syncLegacyEventObject($pdo, $event);

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
    } catch (Throwable $e) {
        error_log('[time] identity token verification failed: ' . $e->getMessage());
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
    } catch (Throwable $e) {
        error_log('[time] runtime identity check failed: ' . $e->getMessage());
        return null;
    }
}

function isDavPath(string $path): bool
{
    $path = '/' . trim($path, '/');
    return $path === '/caldav'
        || str_starts_with($path, '/caldav/')
        || $path === '/dav'
        || str_starts_with($path, '/dav/');
}

function davBaseUri(string $path): string
{
    $path = '/' . trim($path, '/');
    return $path === '/caldav' || str_starts_with($path, '/caldav/')
        ? '/caldav/'
        : '/dav/';
}

function handleDavRequest(array $config, string $apiBaseUrl, string $baseUri): void
{
    if (!class_exists('DOMDocument')) {
        http_response_code(503);
        header('Content-Type: application/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<d:error xmlns:d="DAV:"><d:responsedescription>CalDAV requires the PHP DOM extension.</d:responsedescription></d:error>';
        return;
    }

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
    } catch (Throwable $e) {
        error_log('[time] DAV identity check failed: ' . $e->getMessage());
        $identity = null;
    }

    if ($identity === null) {
        davUnauthorized();
        return;
    }

    $principalUri = 'principals/' . rawurlencode((string) $identity['id']);
    $principalBackend = new DavPrincipalBackend($identity);
    $calendarBackend = new DavCalendarBackend(timePdo($config), (string) $identity['id']);
    $server = new Sabre\DAV\Server([
        new Sabre\DAVACL\PrincipalCollection($principalBackend),
        new Sabre\CalDAV\CalendarRoot($principalBackend, $calendarBackend),
    ]);
    $server->setBaseUri($baseUri);
    $server->addPlugin(new Sabre\DAV\Auth\Plugin(
        new DavAuthBackend($credentials['username'], $principalUri),
        'Elonn Time DAV'
    ));
    $server->addPlugin(new Sabre\DAVACL\Plugin());
    $server->addPlugin(new Sabre\CalDAV\Plugin());
    $server->addPlugin(new Sabre\DAV\Sync\Plugin());
    $server->exec();
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

function validDate(string $value): ?string
{
    $value = trim($value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
}

function validTimezone(string $value): ?string
{
    $value = trim($value);
    return $value !== '' && in_array($value, DateTimeZone::listIdentifiers(), true) ? $value : null;
}

function calendarUri(string $name): string
{
    $base = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));
    return ($base !== '' ? $base : 'calendar') . '-' . substr(bin2hex(random_bytes(6)), 0, 12);
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
        "SELECT id, uri, name, description, color, timezone, components, status, source_service, source_object_type, source_object_id, source_url, created_at, updated_at
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
    $sql = "SELECT id, calendar_id, title, description, location, starts_at, ends_at, timezone, all_day, status, source_service, source_object_type, source_object_id, source_url, created_at, updated_at
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
 * @return array<int, array<string, mixed>>
 */
function listEventsByView(PDO $pdo, string $identityUserId, string $view): array
{
    $all = listEvents($pdo, $identityUserId, null);
    $now = time();

    switch ($view) {
        case 'today':
            $start = strtotime('today');
            $end = strtotime('tomorrow') - 1;
            return array_values(array_filter($all, static function (array $e) use ($start, $end): bool {
                $t = strtotime((string) $e['starts_at']);
                return $t !== false && $t >= $start && $t <= $end;
            }));
        case 'week':
            $end = strtotime('+7 days', $now);
            return array_values(array_filter($all, static function (array $e) use ($now, $end): bool {
                $t = strtotime((string) $e['starts_at']);
                return $t !== false && $t >= $now && $t <= $end;
            }));
        case 'month':
            $end = strtotime('+30 days', $now);
            return array_values(array_filter($all, static function (array $e) use ($now, $end): bool {
                $t = strtotime((string) $e['starts_at']);
                return $t !== false && $t >= $now && $t <= $end;
            }));
        case 'upcoming':
            return array_values(array_filter($all, static function (array $e) use ($now): bool {
                $t = strtotime((string) $e['starts_at']);
                return $t !== false && $t >= $now;
            }));
        default:
            return array_slice($all, 0, 6);
    }
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
        "SELECT id, name, color, timezone, status, source_service, source_object_type, source_object_id, source_url, created_at, updated_at
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
        "SELECT id, identity_user_id, calendar_id, title, description, location, starts_at, ends_at, timezone, all_day, status, source_service, source_object_type, source_object_id, source_url, created_at, updated_at
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
        'uri' => (string) $calendar['uri'],
        'name' => (string) $calendar['name'],
        'description' => $calendar['description'] === null ? null : (string) $calendar['description'],
        'color' => $calendar['color'] === null ? null : (string) $calendar['color'],
        'timezone' => $calendar['timezone'] === null ? null : (string) $calendar['timezone'],
        'components' => (string) $calendar['components'],
        'status' => (string) $calendar['status'],
        'source' => calendarSourcePayload($calendar),
        'editable_fields' => ['name', 'description', 'color', 'timezone'],
        'deletable' => ($calendar['source_service'] ?? null) !== 'social',
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
        'starts_at' => $event['starts_at'] === null ? null : (string) $event['starts_at'],
        'ends_at' => $event['ends_at'] === null ? null : (string) $event['ends_at'],
        'timezone' => $event['timezone'] === null ? null : (string) $event['timezone'],
        'all_day' => (bool) $event['all_day'],
        'status' => (string) $event['status'],
        'source' => eventSourcePayload($event),
        'created_at' => (string) $event['created_at'],
        'updated_at' => $event['updated_at'] === null ? null : (string) $event['updated_at'],
    ];
}

/**
 * @param array<string, mixed> $calendar
 * @return array<string, mixed>|null
 */
function calendarSourcePayload(array $calendar): ?array
{
    if (($calendar['source_service'] ?? null) === null && ($calendar['source_object_type'] ?? null) === null && ($calendar['source_object_id'] ?? null) === null) {
        return null;
    }

    return [
        'service' => $calendar['source_service'] === null ? null : (string) $calendar['source_service'],
        'object_type' => $calendar['source_object_type'] === null ? null : (string) $calendar['source_object_type'],
        'object_id' => $calendar['source_object_id'] === null ? null : (string) $calendar['source_object_id'],
        'url' => $calendar['source_url'] === null ? null : (string) $calendar['source_url'],
    ];
}

/**
 * @param array<string, mixed> $event
 * @return array<string, mixed>|null
 */
function eventSourcePayload(array $event): ?array
{
    if (($event['source_service'] ?? null) === null && ($event['source_object_type'] ?? null) === null && ($event['source_object_id'] ?? null) === null) {
        return null;
    }

    return [
        'service' => $event['source_service'] === null ? null : (string) $event['source_service'],
        'object_type' => $event['source_object_type'] === null ? null : (string) $event['source_object_type'],
        'object_id' => $event['source_object_id'] === null ? null : (string) $event['source_object_id'],
        'url' => $event['source_url'] === null ? null : (string) $event['source_url'],
    ];
}

function requireSocialIngestToken(array $config): bool
{
    $configured = trim((string) ($config['services']['social_ingest_token'] ?? ''));
    if ($configured === '') {
        error_log('[time] social ingest token not configured');
        return false;
    }

    $header = $_SERVER['HTTP_X_ELONN_SOCIAL_INGEST_TOKEN'] ?? '';
    return is_string($header) && hash_equals($configured, trim($header));
}

/**
 * @return array<int, string>
 */
function socialIngestRecipientIds(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $recipients = [];
    foreach ($value as $recipient) {
        $identityUserId = cleanString($recipient);
        if ($identityUserId !== null) {
            $recipients[$identityUserId] = $identityUserId;
        }
    }

    return array_values($recipients);
}

/**
 * @return array<string, mixed>
 */
function ensureSocialImportCalendar(PDO $pdo, array $config, string $identityUserId): array
{
    $socialBaseUrl = rtrim((string) ($config['services']['social_base_url'] ?? 'https://social.elonn.com'), '/');
    $stmt = $pdo->prepare(
        "SELECT id, name, color, timezone, status, source_service, source_object_type, source_object_id, source_url, created_at, updated_at
         FROM time_calendars
         WHERE identity_user_id = :identity_user_id
           AND source_service = 'social'
           AND source_object_type = 'event_feed'
           AND source_object_id = 'default'
         LIMIT 1"
    );
    $stmt->execute([':identity_user_id' => $identityUserId]);
    $calendar = $stmt->fetch();
    if (is_array($calendar)) {
        if ((string) ($calendar['source_url'] ?? '') !== $socialBaseUrl . '/social/events') {
            $pdo->prepare(
                "UPDATE time_calendars
                 SET source_url = :source_url, updated_at = :updated_at
                 WHERE id = :id AND identity_user_id = :identity_user_id"
            )->execute([
                ':source_url' => $socialBaseUrl . '/social/events',
                ':updated_at' => now(),
                ':id' => (int) $calendar['id'],
                ':identity_user_id' => $identityUserId,
            ]);
            $calendar['source_url'] = $socialBaseUrl . '/social/events';
            $calendar['updated_at'] = now();
        }
        return $calendar;
    }

    $now = now();
    $insert = $pdo->prepare(
        "INSERT INTO time_calendars
            (identity_user_id, uri, name, color, timezone, components, sync_token, status, source_service, source_object_type, source_object_id, source_url, created_at, updated_at)
         VALUES
            (:identity_user_id, 'social-events', :name, :color, :timezone, 'VEVENT,VTODO', 1, 'active', 'social', 'event_feed', 'default', :source_url, :created_at, NULL)
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
    );
    $insert->execute([
        ':identity_user_id' => $identityUserId,
        ':name' => 'Social events',
        ':color' => '#7c9cff',
        ':timezone' => null,
        ':source_url' => $socialBaseUrl . '/social/events',
        ':created_at' => $now,
    ]);

    return findCalendar($pdo, $identityUserId, (int) $pdo->lastInsertId()) ?? [];
}

/**
 * @param array<string, mixed> $calendar
 * @param array<string, mixed> $eventInput
 * @return array<string, mixed>
 */
function upsertSocialEvent(PDO $pdo, array $config, array $calendar, string $identityUserId, array $eventInput): array
{
    $sourceObjectId = cleanString($eventInput['source_object_id'] ?? null);
    if ($sourceObjectId === null && array_key_exists('id', $eventInput) && (is_string($eventInput['id']) || is_int($eventInput['id']))) {
        $sourceObjectId = trim((string) $eventInput['id']);
    }
    $title = cleanString($eventInput['title'] ?? null);
    if ($sourceObjectId === null || $title === null) {
        throw new InvalidArgumentException('Social event id and title are required.');
    }

    $status = cleanString($eventInput['status'] ?? null) ?? 'active';
    if (!in_array($status, ['active', 'cancelled', 'deleted'], true)) {
        $status = 'active';
    }

    $allDay = truthy($eventInput['all_day'] ?? false);
    $startsAt = normalizeDateTime($eventInput['starts_at'] ?? null);
    $endsAt = normalizeDateTime($eventInput['ends_at'] ?? null);
    $timezone = cleanOptionalString($eventInput['timezone'] ?? null);
    $location = cleanOptionalString($eventInput['location'] ?? null);
    $description = cleanOptionalString($eventInput['description'] ?? $eventInput['summary'] ?? null);
    $sourceUrl = cleanOptionalString($eventInput['source_url'] ?? null) ?? ((string) ($config['services']['social_base_url'] ?? 'https://social.elonn.com')) . '/social/events/' . $sourceObjectId;

    $stmt = $pdo->prepare(
        "INSERT INTO time_events
            (identity_user_id, calendar_id, title, description, location, starts_at, ends_at, timezone, all_day, status, source_service, source_object_type, source_object_id, source_url, created_at, updated_at)
         VALUES
            (:identity_user_id, :calendar_id, :title, :description, :location, :starts_at, :ends_at, :timezone, :all_day, :status, 'social', 'event', :source_object_id, :source_url, :created_at, NULL)
         ON DUPLICATE KEY UPDATE
            id = LAST_INSERT_ID(id),
            calendar_id = VALUES(calendar_id),
            title = VALUES(title),
            description = VALUES(description),
            location = VALUES(location),
            starts_at = VALUES(starts_at),
            ends_at = VALUES(ends_at),
            timezone = VALUES(timezone),
            all_day = VALUES(all_day),
            status = VALUES(status),
            source_url = VALUES(source_url),
            updated_at = VALUES(created_at)"
    );
    $stmt->execute([
        ':identity_user_id' => $identityUserId,
        ':calendar_id' => (int) $calendar['id'],
        ':title' => $title,
        ':description' => $description,
        ':location' => $location,
        ':starts_at' => $startsAt,
        ':ends_at' => $endsAt,
        ':timezone' => $timezone,
        ':all_day' => $allDay ? 1 : 0,
        ':status' => $status,
        ':source_object_id' => $sourceObjectId,
        ':source_url' => $sourceUrl,
        ':created_at' => now(),
    ]);

    $event = findEventAnyStatus($pdo, $identityUserId, (int) $pdo->lastInsertId()) ?? [];
    if ($event !== []) {
        syncLegacyEventObject($pdo, $event);
    }
    return $event;
}

/**
 * Keeps compatibility writes and Social ingestion on the canonical object store.
 *
 * @param array<string, mixed> $event
 */
function syncLegacyEventObject(PDO $pdo, array $event): void
{
    $identityUserId = (string) $event['identity_user_id'];
    $sourceService = $event['source_service'] === null ? null : (string) $event['source_service'];
    $sourceObjectType = $event['source_object_type'] === null ? null : (string) $event['source_object_type'];
    $sourceObjectId = $event['source_object_id'] === null ? null : (string) $event['source_object_id'];
    $existing = null;
    if ($sourceService !== null && $sourceObjectId !== null) {
        $stmt = $pdo->prepare(
            'SELECT * FROM time_calendar_objects
             WHERE identity_user_id = :identity_user_id
               AND source_service = :source_service
               AND source_object_type = :source_object_type
               AND source_object_id = :source_object_id
             LIMIT 1'
        );
        $stmt->execute([
            'identity_user_id' => $identityUserId,
            'source_service' => $sourceService,
            'source_object_type' => $sourceObjectType,
            'source_object_id' => $sourceObjectId,
        ]);
        $row = $stmt->fetch();
        $existing = is_array($row) ? $row : null;
    } else {
        $stmt = $pdo->prepare(
            'SELECT * FROM time_calendar_objects
             WHERE identity_user_id = :identity_user_id AND uid = :uid LIMIT 1'
        );
        $stmt->execute([
            'identity_user_id' => $identityUserId,
            'uid' => 'time-event-' . (string) $event['id'] . '@elonn',
        ]);
        $row = $stmt->fetch();
        $existing = is_array($row) ? $row : null;
    }

    if ((string) $event['status'] === 'deleted') {
        if ($existing !== null) {
            $calendarId = (int) $existing['calendar_id'];
            $uri = (string) $existing['uri'];
            $now = now();
            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    'DELETE FROM time_calendar_objects WHERE id = :id AND identity_user_id = :identity_user_id'
                )->execute(['id' => (int) $existing['id'], 'identity_user_id' => $identityUserId]);
                $pdo->prepare(
                    'UPDATE time_calendars SET sync_token = sync_token + 1, updated_at = :updated_at WHERE id = :id'
                )->execute(['updated_at' => $now, 'id' => $calendarId]);
                $tokenStmt = $pdo->prepare('SELECT sync_token FROM time_calendars WHERE id = :id');
                $tokenStmt->execute(['id' => $calendarId]);
                $pdo->prepare(
                    "INSERT INTO time_calendar_changes (calendar_id, sync_token, uri, operation, created_at)
                     VALUES (:calendar_id, :sync_token, :uri, 'deleted', :created_at)"
                )->execute([
                    'calendar_id' => $calendarId,
                    'sync_token' => (int) $tokenStmt->fetchColumn(),
                    'uri' => $uri,
                    'created_at' => $now,
                ]);
                $pdo->commit();
            } catch (Throwable $throwable) {
                $pdo->rollBack();
                throw $throwable;
            }
        }
        return;
    }

    $uid = $sourceService === 'social'
        ? 'social-event-' . $sourceObjectId . '@elonn'
        : 'time-event-' . (string) $event['id'] . '@elonn';
    $calendarId = $existing === null ? (int) $event['calendar_id'] : (int) $existing['calendar_id'];
    $fields = [
        'uid' => $uid,
        'component_type' => 'VEVENT',
        'title' => (string) $event['title'],
        'description' => $event['description'],
        'location' => $event['location'],
        'starts_at' => $event['starts_at'],
        'ends_at' => $event['ends_at'],
        'timezone' => $event['timezone'],
        'all_day' => (bool) $event['all_day'],
        'status' => (string) $event['status'],
        'alarm_trigger' => $existing['alarm_trigger'] ?? null,
    ];
    $calendarData = CalendarObject::build($fields, $uid);
    $parsed = CalendarObject::parse($calendarData);
    $uri = $existing === null
        ? ($sourceService === 'social' ? 'social-event-' . $sourceObjectId . '.ics' : 'event-' . (string) $event['id'] . '.ics')
        : (string) $existing['uri'];
    $now = now();

    if ($existing === null) {
        $stmt = $pdo->prepare(
            'INSERT INTO time_calendar_objects
                (identity_user_id, calendar_id, uri, uid, component_type, calendar_data, etag, size_bytes,
                 title, description, location, starts_at, ends_at, due_at, completed_at, timezone, all_day,
                 status, priority, recurrence_rule, alarm_trigger, first_occurrence, last_occurrence,
                 source_service, source_object_type, source_object_id, source_url, created_at)
             VALUES
                (:identity_user_id, :calendar_id, :uri, :uid, :component_type, :calendar_data, :etag, :size_bytes,
                 :title, :description, :location, :starts_at, :ends_at, :due_at, :completed_at, :timezone, :all_day,
                 :status, :priority, :recurrence_rule, :alarm_trigger, :first_occurrence, :last_occurrence,
                 :source_service, :source_object_type, :source_object_id, :source_url, :created_at)'
        );
        $stmt->execute($parsed + [
            'identity_user_id' => $identityUserId,
            'calendar_id' => $calendarId,
            'uri' => $uri,
            'source_service' => $sourceService,
            'source_object_type' => $sourceObjectType,
            'source_object_id' => $sourceObjectId,
            'source_url' => $event['source_url'],
            'created_at' => $now,
        ]);
    } else {
        $assignments = [];
        foreach (array_keys($parsed) as $field) {
            $assignments[] = $field . ' = :' . $field;
        }
        $stmt = $pdo->prepare(
            'UPDATE time_calendar_objects SET ' . implode(', ', $assignments) . ',
                    source_url = :source_url, updated_at = :updated_at
             WHERE id = :id AND identity_user_id = :identity_user_id'
        );
        $stmt->execute($parsed + [
            'source_url' => $event['source_url'],
            'updated_at' => $now,
            'id' => (int) $existing['id'],
            'identity_user_id' => $identityUserId,
        ]);
    }

    $pdo->prepare(
        'UPDATE time_calendars SET sync_token = sync_token + 1, updated_at = :updated_at WHERE id = :id'
    )->execute(['updated_at' => $now, 'id' => $calendarId]);
    $tokenStmt = $pdo->prepare('SELECT sync_token FROM time_calendars WHERE id = :id');
    $tokenStmt->execute(['id' => $calendarId]);
    $pdo->prepare(
        'INSERT INTO time_calendar_changes (calendar_id, sync_token, uri, operation, created_at)
         VALUES (:calendar_id, :sync_token, :uri, :operation, :created_at)'
    )->execute([
        'calendar_id' => $calendarId,
        'sync_token' => (int) $tokenStmt->fetchColumn(),
        'uri' => $uri,
        'operation' => $existing === null ? 'created' : 'updated',
        'created_at' => $now,
    ]);
}

/**
 * @return array<string, mixed>|null
 */
function findEventAnyStatus(PDO $pdo, string $identityUserId, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, identity_user_id, calendar_id, title, description, location, starts_at, ends_at, timezone,
                all_day, status, source_service, source_object_type, source_object_id, source_url, created_at, updated_at
         FROM time_events WHERE id = :id AND identity_user_id = :identity_user_id LIMIT 1'
    );
    $stmt->execute(['id' => $id, 'identity_user_id' => $identityUserId]);
    $event = $stmt->fetch();
    return is_array($event) ? $event : null;
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
