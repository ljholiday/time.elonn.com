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
use Elonn\Time\ServiceDescriptor;
use Elonn\Time\View;
use Dotenv\Dotenv;

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';

Dotenv::createImmutable(BASE_PATH)->safeLoad();
$config = require BASE_PATH . '/config/config.php';

redirectToHttps($config['app']['url']);

$apiBaseUrl = $config['services']['api_base_url'];
$router = new Router();

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (isDavPath($requestPath)) {
    handleDavRequest($config, $apiBaseUrl, davBaseUri($requestPath));
    return;
}

$wellKnownDav = wellKnownCalDavRedirect($requestPath);
if ($wellKnownDav !== null) {
    header('Location: ' . rtrim($config['app']['url'], '/') . $wellKnownDav, true, 301);
    return;
}

$router->get('/health', static function (): void {
    Response::json([
        'status' => 'ok',
        'service' => 'elonn_time',
    ]);
});

$router->get('/descriptor', static function (): void {
    Response::json(ServiceDescriptor::payload());
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

$router->get('/metrics', static function () use ($config): void {
    $startedAt = microtime(true);
    if ((timeServiceCaller($config, 'GET', '/metrics', '')['service'] ?? '') !== 'admin.elonn') {
        timeMetricsAuthFailed();
        return;
    }

    $database = 'error';
    try {
        timePdo($config)->query('SELECT 1');
        $database = 'connected';
    } catch (Throwable $throwable) {
        error_log('[time] /metrics DB check failed: ' . $throwable->getMessage());
    }

    Response::json([
        'contract_version' => '1.0',
        'service' => 'time.elonn',
        'status' => $database === 'connected' ? 'ok' : 'degraded',
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'response_time_ms' => round((microtime(true) - $startedAt) * 1000, 2),
        'custom_metrics' => [
            'database' => $database,
        ],
    ]);
});

/*
 * canonical/administrative-interface.json: Time's own administrative status and capability
 * baseline, for Admin's observation only. configuration.mutable, diagnostics, and
 * control.{restart,drain} are honestly reported as unavailable -- Time implements none of them
 * yet.
 */
$router->get('/administrative-interface', static function () use ($config): void {
    if ((timeServiceCaller($config, 'GET', '/administrative-interface', '')['service'] ?? '') !== 'admin.elonn') {
        timeMetricsAuthFailed();
        return;
    }

    $checks = [];
    $healthy = true;
    try {
        timePdo($config)->query('SELECT 1');
        $checks[] = ['name' => 'database', 'state' => 'healthy', 'detail' => 'Connection and SELECT 1 succeeded.'];
    } catch (Throwable $throwable) {
        $healthy = false;
        $checks[] = ['name' => 'database', 'state' => 'unhealthy', 'detail' => $throwable->getMessage()];
    }

    Response::json([
        'component' => 'time.elonn',
        'component_version' => '7',
        'deployment_id' => '',
        'status' => $healthy ? 'running' : 'degraded',
        'health' => [
            'state' => $healthy ? 'healthy' : 'unhealthy',
            'checks' => $checks,
        ],
        'configuration' => [
            'inspectable' => [
                'database.name' => (string) ($config['database']['name'] ?? ''),
                'services.api_base_url' => (string) ($config['services']['api_base_url'] ?? ''),
                'services.social_base_url' => (string) ($config['services']['social_base_url'] ?? ''),
            ],
            'mutable' => [],
        ],
        'maintenance' => ['state' => 'normal', 'reason' => ''],
        'diagnostics' => ['available' => []],
        'control' => [
            'restart' => ['state' => 'unavailable', 'reason' => 'Time does not implement an administrative restart operation.'],
            'drain' => ['state' => 'unavailable', 'reason' => 'Time does not implement an administrative drain operation.'],
        ],
        'observed_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'metadata' => (object) [],
    ]);
});

$router->post('/time/call', static function () use ($config): void {
    $rawBody = (string) file_get_contents('php://input');
    $caller = timeServiceCaller($config, 'POST', '/time/call', $rawBody);
    if ($caller === null) {
        timeServiceDatasetError('time.service_auth_failed', 'auth', 'Time service authentication failed.', 401);
        return;
    }

    $memberId = $caller['member_id'];
    if ($memberId === '') {
        timeServiceDatasetError('time.member_required', 'auth', 'Time search requires authenticated member identity.', 400, $caller['service']);
        return;
    }

    $input = json_decode($rawBody, true);
    $input = is_array($input) ? $input : [];
    $content = is_array($input['content'] ?? null) ? $input['content'] : [];
    $operation = (string) ($content['operation'] ?? '');
    $limit = max(1, min(25, (int) ($content['limit'] ?? 10)));
    $pdo = timePdo($config);

    if ($operation === 'time.search') {
        $text = trim((string) ($content['text'] ?? ''));
        if ($text === '') {
            timeServiceDatasetError('time.invalid_search_call', 'invalid_call', 'Time search requires text.', 422, $caller['service']);
            return;
        }
        Response::json(timeServiceDataset(timeSearchObjects($pdo, $memberId, $text, $limit), 'time.search', $caller['service'], $text));
        return;
    }

    if ($operation === 'time.list') {
        Response::json(timeServiceDataset(timeRecentObjects($pdo, $memberId, $limit), 'time.list', $caller['service'], ''));
        return;
    }

    if ($operation === 'time.open') {
        $numericId = timeCalendarObjectIdFrom($content['object_id'] ?? null);
        if ($numericId === null) {
            timeServiceDatasetError('time.object_not_found', 'not_found', 'Time object was not found.', 404, $caller['service']);
            return;
        }
        $row = timeFetchObject($pdo, $memberId, $numericId);
        if ($row === null) {
            timeServiceDatasetError('time.object_not_found', 'not_found', 'Time object was not found.', 404, $caller['service']);
            return;
        }
        Response::json(timeOpenDataset($row, 'time.open', $caller['service']));
        return;
    }

    if ($operation === 'time.calendars') {
        $store = new CalendarStore($pdo);
        Response::json(timeCalendarsDataset($store->calendars($memberId), $caller['service']));
        return;
    }

    if ($operation === 'time.calendar.update' || $operation === 'time.calendar.delete') {
        $calendarId = timeCalendarIdFrom($content['object_id'] ?? null);
        if ($calendarId === null) {
            timeServiceDatasetError('time.object_not_found', 'not_found', 'Time calendar was not found.', 404, $caller['service']);
            return;
        }
        $store = new CalendarStore($pdo);

        if ($operation === 'time.calendar.delete') {
            try {
                $store->deleteCalendar($memberId, $calendarId);
            } catch (\DomainException $domainException) {
                timeServiceDatasetError('time.forbidden_mutation', 'forbidden', $domainException->getMessage(), 403, $caller['service']);
                return;
            }
            Response::json(timeCalendarsDataset($store->calendars($memberId), $caller['service']));
            return;
        }

        $timezoneName = cleanOptionalString($content['timezone'] ?? null);
        if ($timezoneName !== null && validTimezone($timezoneName) === null) {
            timeServiceDatasetError('time.invalid_calendar_call', 'invalid_call', 'timezone is not valid.', 422, $caller['service']);
            return;
        }
        $fields = [];
        if (array_key_exists('name', $content)) {
            $fields['name'] = (string) $content['name'];
        }
        foreach (['description', 'color'] as $key) {
            if (array_key_exists($key, $content)) {
                $fields[$key] = cleanOptionalString($content[$key]);
            }
        }
        if ($timezoneName !== null) {
            $fields['timezone'] = $timezoneName;
        }
        try {
            $store->updateCalendar($memberId, $calendarId, $fields);
        } catch (\RuntimeException $runtimeException) {
            timeServiceDatasetError('time.object_not_found', 'not_found', $runtimeException->getMessage(), 404, $caller['service']);
            return;
        } catch (\InvalidArgumentException $invalidArgumentException) {
            timeServiceDatasetError('time.invalid_calendar_call', 'invalid_call', $invalidArgumentException->getMessage(), 422, $caller['service']);
            return;
        }
        Response::json(timeCalendarsDataset($store->calendars($memberId), $caller['service']));
        return;
    }

    if ($operation === 'time.agenda') {
        $timezoneName = cleanOptionalString($content['timezone'] ?? null) ?? 'UTC';
        if (validTimezone($timezoneName) === null) {
            timeServiceDatasetError('time.invalid_event_call', 'invalid_call', 'timezone is not valid.', 422, $caller['service']);
            return;
        }
        $start = timeParseDateTime($content['start'] ?? null, $timezoneName);
        $end = timeParseDateTime($content['end'] ?? null, $timezoneName);
        if ($start === null || $end === null || $end <= $start) {
            timeServiceDatasetError('time.invalid_event_call', 'invalid_call', 'start and end must be valid, and end must be after start.', 422, $caller['service']);
            return;
        }
        Response::json(timeServiceDataset(timeAgendaObjects($pdo, $memberId, $start, $end), 'time.agenda', $caller['service'], ''));
        return;
    }

    if ($operation === 'time.tasks') {
        $status = cleanOptionalString($content['status'] ?? null) ?? 'open';
        if (!in_array($status, ['open', 'completed', 'all'], true)) {
            timeServiceDatasetError('time.invalid_task_call', 'invalid_call', 'status must be open, completed, or all.', 422, $caller['service']);
            return;
        }
        $dueBefore = timeParseDateTime($content['due_before'] ?? null, null);
        $dueAfter = timeParseDateTime($content['due_after'] ?? null, null);
        Response::json(timeServiceDataset(timeTaskObjects($pdo, $memberId, $status, $dueBefore, $dueAfter), 'time.tasks', $caller['service'], ''));
        return;
    }

    if ($operation === 'time.event.create') {
        // Resolve the target calendar first: its own declared timezone is the documented
        // default (help.timezone) when the Call doesn't name one explicitly, so the calendar
        // must be known before that default can be applied.
        $store = new CalendarStore($pdo);
        $calendarId = $store->resolveCalendarId($memberId, cleanOptionalString($content['calendar'] ?? null));
        $defaultTimezone = $store->calendarTimezone($memberId, $calendarId) ?? 'UTC';
        [$fields, $error] = timeEventFieldsFromContent($content, true, $defaultTimezone);
        if ($error !== null) {
            timeServiceDatasetError('time.invalid_event_call', 'invalid_call', $error, 422, $caller['service']);
            return;
        }
        $intervalError = timeValidateEventInterval($fields, null, $defaultTimezone);
        if ($intervalError !== null) {
            timeServiceDatasetError('time.invalid_event_call', 'invalid_call', $intervalError, 422, $caller['service']);
            return;
        }
        $fields['calendar_id'] = $calendarId;
        try {
            $created = $store->create($memberId, $fields);
        } catch (\Throwable $throwable) {
            timeServiceDatasetError('time.validation_failed', 'invalid_call', $throwable->getMessage(), 422, $caller['service']);
            return;
        }
        $row = timeFetchObject($pdo, $memberId, (int) $created['id']);
        Response::json(timeOpenDataset($row ?? [], 'time.event.create', $caller['service']));
        return;
    }

    if ($operation === 'time.event.update' || $operation === 'time.event.delete') {
        $numericId = timeCalendarObjectIdFrom($content['object_id'] ?? null);
        if ($numericId === null) {
            timeServiceDatasetError('time.object_not_found', 'not_found', 'Time object was not found.', 404, $caller['service']);
            return;
        }
        $store = new CalendarStore($pdo);
        $existing = $store->find($memberId, $numericId);
        if ($existing === null) {
            timeServiceDatasetError('time.object_not_found', 'not_found', 'Time object was not found.', 404, $caller['service']);
            return;
        }

        if ($operation === 'time.event.delete') {
            try {
                $store->delete($memberId, $numericId);
            } catch (\DomainException $domainException) {
                timeServiceDatasetError('time.forbidden_mutation', 'forbidden', $domainException->getMessage(), 403, $caller['service']);
                return;
            }
            Response::json(timeServiceDataset([], 'time.event.delete', $caller['service'], ''));
            return;
        }

        // Editing an existing event interprets any offset-less starts_at/ends_at in the
        // event's own current zone, not a freshly-resolved calendar default -- changing which
        // calendar an event lives in should not silently retime it.
        $defaultTimezone = validTimezone((string) ($existing['timezone'] ?? '')) ?? 'UTC';
        [$fields, $error] = timeEventFieldsFromContent($content, false, $defaultTimezone);
        if ($error !== null) {
            timeServiceDatasetError('time.invalid_event_call', 'invalid_call', $error, 422, $caller['service']);
            return;
        }
        if (array_key_exists('starts_at', $fields) && !array_key_exists('ends_at', $fields)
            && $existing['starts_at'] !== null && $existing['ends_at'] !== null) {
            // $existing's instants are always ISO 8601 with an explicit UTC offset (CalendarStore::find()),
            // so they're unambiguous; $fields['starts_at'] is a bare wall-clock string paired with
            // $fields['timezone'] (set above whenever starts_at is), so it must be parsed with that same
            // zone, not left to PHP's ambient default.
            $durationZone = new DateTimeZone((string) ($fields['timezone'] ?? $defaultTimezone));
            $oldStart = new DateTimeImmutable((string) $existing['starts_at']);
            $oldEnd = new DateTimeImmutable((string) $existing['ends_at']);
            $newStart = new DateTimeImmutable($fields['starts_at'], $durationZone);
            $fields['ends_at'] = $newStart->add($oldStart->diff($oldEnd))->format('Y-m-d H:i:s');
        }
        $intervalError = timeValidateEventInterval($fields, $existing, $defaultTimezone);
        if ($intervalError !== null) {
            timeServiceDatasetError('time.invalid_event_call', 'invalid_call', $intervalError, 422, $caller['service']);
            return;
        }
        // A blank or omitted calendar preserves the event's current calendar membership --
        // CalendarStore::update()'s merge already does this as long as $fields doesn't set
        // calendar_id at all. A named calendar must already exist: an unknown name is a
        // mistake the caller should be told about, not silently swapped for another calendar.
        $calendarName = cleanOptionalString($content['calendar'] ?? null);
        if ($calendarName !== null) {
            $calendarId = $store->findCalendarIdByName($memberId, $calendarName);
            if ($calendarId === null) {
                timeServiceDatasetError('time.invalid_event_call', 'invalid_call', 'No calendar named "' . $calendarName . '" exists.', 422, $caller['service']);
                return;
            }
            $fields['calendar_id'] = $calendarId;
        }
        try {
            $store->update($memberId, $numericId, $fields);
        } catch (\DomainException $domainException) {
            timeServiceDatasetError('time.forbidden_mutation', 'forbidden', $domainException->getMessage(), 403, $caller['service']);
            return;
        } catch (\Throwable $throwable) {
            timeServiceDatasetError('time.validation_failed', 'invalid_call', $throwable->getMessage(), 422, $caller['service']);
            return;
        }
        $row = timeFetchObject($pdo, $memberId, $numericId);
        Response::json(timeOpenDataset($row ?? [], 'time.event.update', $caller['service']));
        return;
    }

    if ($operation === 'time.task.create') {
        $store = new CalendarStore($pdo);
        $calendarId = $store->resolveCalendarId($memberId, cleanOptionalString($content['calendar'] ?? null));
        $defaultTimezone = $store->calendarTimezone($memberId, $calendarId) ?? 'UTC';
        [$fields, $error] = timeTaskFieldsFromContent($content, true, $defaultTimezone);
        if ($error !== null) {
            timeServiceDatasetError('time.invalid_task_call', 'invalid_call', $error, 422, $caller['service']);
            return;
        }
        $fields['component_type'] = 'VTODO';
        $fields['calendar_id'] = $calendarId;
        try {
            $created = $store->create($memberId, $fields);
        } catch (\Throwable $throwable) {
            timeServiceDatasetError('time.validation_failed', 'invalid_call', $throwable->getMessage(), 422, $caller['service']);
            return;
        }
        $row = timeFetchObject($pdo, $memberId, (int) $created['id']);
        Response::json(timeOpenDataset($row ?? [], 'time.task.create', $caller['service']));
        return;
    }

    if (in_array($operation, ['time.task.update', 'time.task.complete', 'time.task.reopen', 'time.task.delete'], true)) {
        $numericId = timeCalendarObjectIdFrom($content['object_id'] ?? null);
        if ($numericId === null) {
            timeServiceDatasetError('time.object_not_found', 'not_found', 'Time object was not found.', 404, $caller['service']);
            return;
        }
        $store = new CalendarStore($pdo);
        $existing = $store->find($memberId, $numericId);
        if ($existing === null || $existing['component_type'] !== 'VTODO') {
            timeServiceDatasetError('time.object_not_found', 'not_found', 'Time object was not found.', 404, $caller['service']);
            return;
        }

        try {
            if ($operation === 'time.task.delete') {
                $store->delete($memberId, $numericId);
                Response::json(timeServiceDataset([], 'time.task.delete', $caller['service'], ''));
                return;
            }
            if ($operation === 'time.task.complete') {
                $store->complete($memberId, $numericId);
            } elseif ($operation === 'time.task.reopen') {
                $store->reopen($memberId, $numericId);
            } else {
                $defaultTimezone = validTimezone((string) ($existing['timezone'] ?? '')) ?? 'UTC';
                [$fields, $error] = timeTaskFieldsFromContent($content, false, $defaultTimezone);
                if ($error !== null) {
                    timeServiceDatasetError('time.invalid_task_call', 'invalid_call', $error, 422, $caller['service']);
                    return;
                }
                // See the matching comment on time.event.update: blank/omitted preserves the
                // task's current calendar; an unknown name is rejected, never substituted.
                $calendarName = cleanOptionalString($content['calendar'] ?? null);
                if ($calendarName !== null) {
                    $calendarId = $store->findCalendarIdByName($memberId, $calendarName);
                    if ($calendarId === null) {
                        timeServiceDatasetError('time.invalid_task_call', 'invalid_call', 'No calendar named "' . $calendarName . '" exists.', 422, $caller['service']);
                        return;
                    }
                    $fields['calendar_id'] = $calendarId;
                }
                $store->update($memberId, $numericId, $fields);
            }
        } catch (\DomainException $domainException) {
            timeServiceDatasetError('time.forbidden_mutation', 'forbidden', $domainException->getMessage(), 403, $caller['service']);
            return;
        } catch (\Throwable $throwable) {
            timeServiceDatasetError('time.validation_failed', 'invalid_call', $throwable->getMessage(), 422, $caller['service']);
            return;
        }
        $row = timeFetchObject($pdo, $memberId, $numericId);
        Response::json(timeOpenDataset($row ?? [], $operation, $caller['service']));
        return;
    }

    timeServiceDatasetError('time.unsupported_operation', 'invalid_call', 'Time operation is not supported.', 422, $caller['service']);
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
    $events = listEvents($pdo, $identity['id'], null);
    $taskWorkspace = (new CalendarStore($pdo))->workspace($identity['id'], 'tasks', (new DateTimeImmutable('today'))->format('Y-m-d'), plannerTimezone(null));
    renderApp('Dashboard', 'home.php', $identity, [
        'calendars' => listCalendars($pdo, $identity['id']),
        'events' => $events,
        'upcoming_events' => upcomingEvents($events, 5),
        'upcoming_event_count' => count(upcomingEvents($events, PHP_INT_MAX)),
        'tasks' => is_array($taskWorkspace['tasks'] ?? null) ? $taskWorkspace['tasks'] : [],
    ]);
});

$router->get('/planner', static function () use ($config, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $view = plannerView($_GET['view'] ?? null);
    $anchorDate = plannerAnchorDate($_GET['date'] ?? null);
    $timezone = plannerTimezone($_GET['timezone'] ?? null);
    $workspace = (new CalendarStore(timePdo($config)))->workspace($identity['id'], $view, $anchorDate, $timezone);

    if (isBrowserRequest()) {
        renderApp('Planner', 'planner.php', $identity, [
            'workspace' => $workspace,
        ]);
        return;
    }

    Response::json(['workspace' => $workspace]);
});

$router->get('/tasks', static function () use ($config, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $workspace = (new CalendarStore(timePdo($config)))->workspace(
        $identity['id'],
        'tasks',
        plannerAnchorDate($_GET['date'] ?? null),
        plannerTimezone($_GET['timezone'] ?? null)
    );

    if (isBrowserRequest()) {
        renderApp('Tasks', 'tasks/index.php', $identity, [
            'workspace' => $workspace,
        ]);
        return;
    }

    Response::json(['tasks' => $workspace['tasks'] ?? []]);
});

$router->get('/tasks/new', static function () use ($config, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    renderApp('New task', 'tasks/new.php', $identity, [
        'error' => null,
        'old' => [],
        'calendars' => (new CalendarStore(timePdo($config)))->calendars($identity['id']),
    ]);
});

$router->post('/tasks', static function () use ($config, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $store = new CalendarStore(timePdo($config));
    $input = requestInput();
    $fields = taskFieldsFromInput($input);
    $calendars = $store->calendars($identity['id']);
    $error = taskValidationError($fields, $calendars);

    if ($error !== null) {
        if (isBrowserRequest()) {
            renderApp('New task', 'tasks/new.php', $identity, [
                'error' => $error,
                'old' => formOld($input, ['calendar_id', 'title', 'due_at', 'description', 'location', 'timezone', 'priority', 'status', 'all_day']),
                'calendars' => $calendars,
            ], 400);
            return;
        }

        Response::json(['error' => $error], 400);
        return;
    }

    try {
        $task = $store->create($identity['id'], $fields);
    } catch (Throwable $throwable) {
        if (isBrowserRequest()) {
            renderApp('New task', 'tasks/new.php', $identity, [
                'error' => $throwable->getMessage(),
                'old' => formOld($input, ['calendar_id', 'title', 'due_at', 'description', 'location', 'timezone', 'priority', 'status', 'all_day']),
                'calendars' => $calendars,
            ], 400);
            return;
        }

        Response::json(['error' => $throwable->getMessage()], 400);
        return;
    }

    if (isBrowserRequest()) {
        redirect('/tasks');
        return;
    }

    Response::json(['task' => $task], 201);
});

$router->get('/tasks/{id}/edit', static function (array $params) use ($config, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $store = new CalendarStore(timePdo($config));
    $task = $store->find($identity['id'], positiveInt($params['id'] ?? null) ?? 0);
    if ($task === null || ($task['component_type'] ?? '') !== 'VTODO') {
        renderApp('Task not found', 'tasks/edit.php', $identity, [
            'error' => 'Task not found.',
            'task' => null,
            'old' => [],
            'calendars' => $store->calendars($identity['id']),
        ], 404);
        return;
    }

    renderApp('Edit task', 'tasks/edit.php', $identity, [
        'error' => null,
        'task' => $task,
        'old' => taskFormOld($task),
        'calendars' => $store->calendars($identity['id']),
    ]);
});

$router->post('/tasks/{id}/edit', static function (array $params) use ($config, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $store = new CalendarStore(timePdo($config));
    $taskId = positiveInt($params['id'] ?? null) ?? 0;
    $task = $store->find($identity['id'], $taskId);
    $calendars = $store->calendars($identity['id']);
    if ($task === null || ($task['component_type'] ?? '') !== 'VTODO') {
        renderApp('Task not found', 'tasks/edit.php', $identity, [
            'error' => 'Task not found.',
            'task' => null,
            'old' => [],
            'calendars' => $calendars,
        ], 404);
        return;
    }

    $input = requestInput();
    $fields = taskFieldsFromInput($input, $task);
    $error = taskValidationError($fields, $calendars);
    $old = formOld($input, ['calendar_id', 'title', 'due_at', 'description', 'location', 'timezone', 'priority', 'status', 'all_day']);

    if ($error !== null) {
        renderApp('Edit task', 'tasks/edit.php', $identity, [
            'error' => $error,
            'task' => $task,
            'old' => $old,
            'calendars' => $calendars,
        ], 400);
        return;
    }

    try {
        $store->update($identity['id'], $taskId, $fields);
    } catch (Throwable $throwable) {
        renderApp('Edit task', 'tasks/edit.php', $identity, [
            'error' => $throwable->getMessage(),
            'task' => $task,
            'old' => $old,
            'calendars' => $calendars,
        ], 400);
        return;
    }

    redirect('/tasks');
});

$router->post('/tasks/{id}/complete', static function (array $params) use ($config, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $store = new CalendarStore(timePdo($config));
    $taskId = positiveInt($params['id'] ?? null) ?? 0;
    $task = $store->find($identity['id'], $taskId);
    if ($task === null || ($task['component_type'] ?? '') !== 'VTODO') {
        Response::json(['error' => 'Task not found.'], 404);
        return;
    }

    $completed = ($task['completed_at'] ?? null) !== null || ($task['status'] ?? '') === 'completed';
    $store->update($identity['id'], $taskId, [
        'status' => $completed ? 'needs-action' : 'completed',
        'completed_at' => $completed ? null : now(),
    ]);

    redirect('/tasks');
});

$router->post('/tasks/{id}/delete', static function (array $params) use ($config, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $store = new CalendarStore(timePdo($config));
    $taskId = positiveInt($params['id'] ?? null) ?? 0;
    $task = $store->find($identity['id'], $taskId);
    if ($task === null || ($task['component_type'] ?? '') !== 'VTODO') {
        Response::json(['error' => 'Task not found.'], 404);
        return;
    }

    $store->delete($identity['id'], $taskId);
    redirect('/tasks');
});

$router->get('/appointments/{id}/edit', static function (array $params) use ($config, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $store = new CalendarStore(timePdo($config));
    $appointment = $store->find($identity['id'], positiveInt($params['id'] ?? null) ?? 0);
    if ($appointment === null || ($appointment['component_type'] ?? '') !== 'VEVENT') {
        renderApp('Event not found', 'appointments/edit.php', $identity, [
            'error' => 'Event not found.',
            'appointment' => null,
            'old' => [],
            'calendars' => $store->calendars($identity['id']),
        ], 404);
        return;
    }
    if (is_array($appointment['source'] ?? null)) {
        renderApp('Edit event', 'appointments/edit.php', $identity, [
            'error' => 'This event mirrors ' . (string) ($appointment['source']['service'] ?? 'another service') . ' and is read-only in Time.',
            'appointment' => $appointment,
            'old' => appointmentFormOld($appointment),
            'calendars' => $store->calendars($identity['id']),
        ], 409);
        return;
    }

    renderApp('Edit event', 'appointments/edit.php', $identity, [
        'error' => null,
        'appointment' => $appointment,
        'old' => appointmentFormOld($appointment),
        'calendars' => $store->calendars($identity['id']),
    ]);
});

$router->post('/appointments/{id}/edit', static function (array $params) use ($config, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $store = new CalendarStore(timePdo($config));
    $appointmentId = positiveInt($params['id'] ?? null) ?? 0;
    $appointment = $store->find($identity['id'], $appointmentId);
    $calendars = $store->calendars($identity['id']);
    if ($appointment === null || ($appointment['component_type'] ?? '') !== 'VEVENT') {
        renderApp('Event not found', 'appointments/edit.php', $identity, [
            'error' => 'Event not found.',
            'appointment' => null,
            'old' => [],
            'calendars' => $calendars,
        ], 404);
        return;
    }
    if (is_array($appointment['source'] ?? null)) {
        renderApp('Edit event', 'appointments/edit.php', $identity, [
            'error' => 'This event mirrors ' . (string) ($appointment['source']['service'] ?? 'another service') . ' and is read-only in Time.',
            'appointment' => $appointment,
            'old' => appointmentFormOld($appointment),
            'calendars' => $calendars,
        ], 409);
        return;
    }

    $input = requestInput();
    $fields = appointmentFieldsFromInput($input, $appointment);
    $error = appointmentValidationError($fields, $calendars);
    $old = formOld($input, ['calendar_id', 'title', 'starts_at', 'ends_at', 'location', 'description', 'timezone', 'status', 'all_day']);

    if ($error !== null) {
        renderApp('Edit event', 'appointments/edit.php', $identity, [
            'error' => $error,
            'appointment' => $appointment,
            'old' => $old,
            'calendars' => $calendars,
        ], 400);
        return;
    }

    try {
        $store->update($identity['id'], $appointmentId, $fields);
    } catch (Throwable $throwable) {
        renderApp('Edit event', 'appointments/edit.php', $identity, [
            'error' => $throwable->getMessage(),
            'appointment' => $appointment,
            'old' => $old,
            'calendars' => $calendars,
        ], 400);
        return;
    }

    redirect('/planner');
});

$router->post('/appointments/{id}/delete', static function (array $params) use ($config, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $store = new CalendarStore(timePdo($config));
    $appointmentId = positiveInt($params['id'] ?? null) ?? 0;
    $appointment = $store->find($identity['id'], $appointmentId);
    if ($appointment === null || ($appointment['component_type'] ?? '') !== 'VEVENT') {
        Response::json(['error' => 'Event not found.'], 404);
        return;
    }
    if (is_array($appointment['source'] ?? null)) {
        renderApp('Edit event', 'appointments/edit.php', $identity, [
            'error' => 'This event mirrors ' . (string) ($appointment['source']['service'] ?? 'another service') . ' and cannot be deleted from Time.',
            'appointment' => $appointment,
            'old' => appointmentFormOld($appointment),
            'calendars' => $store->calendars($identity['id']),
        ], 409);
        return;
    }

    $store->delete($identity['id'], $appointmentId);
    redirect('/planner');
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

$router->get('/calendars/{id}/edit', static function (array $params) use ($config, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $calendar = findCalendar(timePdo($config), $identity['id'], positiveInt($params['id'] ?? null));
    if ($calendar === null) {
        renderApp('Calendar not found', 'calendars/edit.php', $identity, [
            'error' => 'Calendar not found.',
            'calendar' => null,
            'old' => [],
        ], 404);
        return;
    }

    renderApp('Edit calendar', 'calendars/edit.php', $identity, [
        'error' => null,
        'calendar' => $calendar,
        'old' => calendarFormOld($calendar),
    ]);
});

$router->post('/calendars/{id}/edit', static function (array $params) use ($config, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $pdo = timePdo($config);
    $calendarId = positiveInt($params['id'] ?? null);
    $calendar = findCalendar($pdo, $identity['id'], $calendarId);
    if ($calendar === null) {
        renderApp('Calendar not found', 'calendars/edit.php', $identity, [
            'error' => 'Calendar not found.',
            'calendar' => null,
            'old' => [],
        ], 404);
        return;
    }

    $input = requestInput();
    $name = cleanString($input['name'] ?? null);
    $old = formOld($input, ['name', 'description', 'color', 'timezone']);

    if ($name === null) {
        renderApp('Edit calendar', 'calendars/edit.php', $identity, [
            'error' => 'Calendar name is required.',
            'calendar' => $calendar,
            'old' => $old,
        ], 400);
        return;
    }

    updateCalendar($pdo, $identity['id'], $calendarId, [
        'name' => $name,
        'description' => cleanOptionalString($input['description'] ?? null),
        'color' => cleanOptionalString($input['color'] ?? null),
        'timezone' => cleanOptionalString($input['timezone'] ?? null),
        'status' => (string) $calendar['status'],
    ]);

    redirect('/calendars');
});

$router->post('/calendars/{id}/archive', static function (array $params) use ($config, $apiBaseUrl): void {
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

    updateCalendar($pdo, $identity['id'], $calendarId, [
        'name' => (string) $calendar['name'],
        'description' => $calendar['description'],
        'color' => $calendar['color'],
        'timezone' => $calendar['timezone'],
        'status' => 'archived',
    ]);

    redirect('/calendars');
});

$router->post('/calendars/{id}/unarchive', static function (array $params) use ($config, $apiBaseUrl): void {
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

    updateCalendar($pdo, $identity['id'], $calendarId, [
        'name' => (string) $calendar['name'],
        'description' => $calendar['description'],
        'color' => $calendar['color'],
        'timezone' => $calendar['timezone'],
        'status' => 'active',
    ]);

    redirect('/calendars');
});

$router->post('/calendars/{id}/delete', static function (array $params) use ($config, $apiBaseUrl): void {
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
        renderApp('Edit calendar', 'calendars/edit.php', $identity, [
            'error' => 'The Social mirror calendar cannot be deleted from Time.',
            'calendar' => $calendar,
            'old' => calendarFormOld($calendar),
        ], 409);
        return;
    }

    deleteCalendar($pdo, $identity['id'], $calendarId);
    redirect('/calendars');
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

    updateCalendar($pdo, $identity['id'], $calendarId, [
        'name' => $name,
        'description' => $description,
        'color' => $color,
        'timezone' => $timezone,
        'status' => $status,
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

    deleteCalendar($pdo, $identity['id'], $calendarId);

    Response::json(['status' => 'deleted']);
});

$router->get('/events', static function () use ($config, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $calendarId = positiveInt($_GET['calendar_id'] ?? null);
    $pdo = timePdo($config);
    $events = listEvents($pdo, $identity['id'], $calendarId);

    if (isBrowserRequest()) {
        renderApp('Events', 'events/index.php', $identity, [
            'events' => $events,
            'calendars' => listCalendars($pdo, $identity['id']),
            'selected_calendar_id' => $calendarId,
        ]);
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

$router->get('/events/{id}/edit', static function (array $params) use ($config, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $pdo = timePdo($config);
    $event = findEvent($pdo, $identity['id'], positiveInt($params['id'] ?? null));
    if ($event === null) {
        renderApp('Event not found', 'events/edit.php', $identity, [
            'error' => 'Event not found.',
            'event' => null,
            'old' => [],
            'calendars' => listCalendars($pdo, $identity['id']),
        ], 404);
        return;
    }

    renderApp('Edit event', 'events/edit.php', $identity, [
        'error' => null,
        'event' => $event,
        'old' => eventFormOld($event),
        'calendars' => listCalendars($pdo, $identity['id']),
    ]);
});

$router->post('/events/{id}/edit', static function (array $params) use ($config, $apiBaseUrl): void {
    $identity = requireIdentity($apiBaseUrl);
    if ($identity === null) {
        return;
    }

    $pdo = timePdo($config);
    $eventId = positiveInt($params['id'] ?? null);
    $event = findEvent($pdo, $identity['id'], $eventId);
    $calendars = listCalendars($pdo, $identity['id']);
    if ($event === null) {
        renderApp('Event not found', 'events/edit.php', $identity, [
            'error' => 'Event not found.',
            'event' => null,
            'old' => [],
            'calendars' => $calendars,
        ], 404);
        return;
    }
    if (($event['source_service'] ?? null) === 'social') {
        renderApp('Edit event', 'events/edit.php', $identity, [
            'error' => 'Social event mirrors are read-only in Time. Edit the Social event at its source.',
            'event' => $event,
            'old' => eventFormOld($event),
            'calendars' => $calendars,
        ], 409);
        return;
    }

    $input = requestInput();
    $calendarId = positiveInt($input['calendar_id'] ?? null);
    $title = cleanString($input['title'] ?? null);
    $startsAt = normalizeDateTime($input['starts_at'] ?? null);
    $endsAt = normalizeDateTime($input['ends_at'] ?? null);
    $status = cleanString($input['status'] ?? null) ?? (string) $event['status'];
    $old = formOld($input, ['calendar_id', 'title', 'starts_at', 'ends_at', 'location', 'description', 'timezone', 'status', 'all_day']);

    $error = null;
    if ($calendarId === null || findCalendar($pdo, $identity['id'], $calendarId) === null) {
        $error = 'Valid calendar is required.';
    } elseif ($title === null || $startsAt === null || $endsAt === null) {
        $error = 'Title, starts, and ends are required.';
    } elseif ($endsAt <= $startsAt) {
        $error = 'Event end must be after start.';
    } elseif (!in_array($status, ['active', 'cancelled'], true)) {
        $error = 'Event status must be active or cancelled.';
    }

    if ($error !== null) {
        renderApp('Edit event', 'events/edit.php', $identity, [
            'error' => $error,
            'event' => $event,
            'old' => $old,
            'calendars' => $calendars,
        ], 400);
        return;
    }

    updateEvent($pdo, $identity['id'], $eventId, [
        'calendar_id' => $calendarId,
        'title' => $title,
        'description' => cleanOptionalString($input['description'] ?? null),
        'location' => cleanOptionalString($input['location'] ?? null),
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'timezone' => cleanOptionalString($input['timezone'] ?? null),
        'all_day' => truthy($input['all_day'] ?? false) ? 1 : 0,
        'status' => $status,
    ]);

    redirect('/events');
});

$router->post('/events/{id}/delete', static function (array $params) use ($config, $apiBaseUrl): void {
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
    if (($event['source_service'] ?? null) === 'social') {
        renderApp('Edit event', 'events/edit.php', $identity, [
            'error' => 'Social event mirrors cannot be deleted from Time.',
            'event' => $event,
            'old' => eventFormOld($event),
            'calendars' => listCalendars($pdo, $identity['id']),
        ], 409);
        return;
    }

    deleteEvent($pdo, $identity['id'], $eventId, $event);
    redirect('/events');
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

    updateEvent($pdo, $identity['id'], $eventId, [
        'calendar_id' => $calendarId,
        'title' => $title,
        'description' => array_key_exists('description', $input) ? cleanOptionalString($input['description']) : $event['description'],
        'location' => array_key_exists('location', $input) ? cleanOptionalString($input['location']) : $event['location'],
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'timezone' => array_key_exists('timezone', $input) ? cleanOptionalString($input['timezone']) : $event['timezone'],
        'all_day' => array_key_exists('all_day', $input) ? (truthy($input['all_day']) ? 1 : 0) : (int) $event['all_day'],
        'status' => $status,
    ]);

    $updated = findEvent($pdo, $identity['id'], $eventId);
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

    deleteEvent($pdo, $identity['id'], $eventId, $event);

    Response::json(['status' => 'deleted']);
});

$router->dispatch(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    $requestPath
);

/**
 * @param array<string, mixed> $config
 * @return array{service:string,member_id:string}|null
 */
function timeServiceCaller(array $config, string $method = 'GET', string $path = '/', string $body = ''): ?array
{
    $serviceAuth = (array) ($config['service_auth'] ?? []);
    $verifier = new \Elonn\Time\SignedRequestVerifier((string) ($serviceAuth['conductor_keys_url'] ?? ''));
    $tokens = $serviceAuth;
    unset($tokens['conductor_keys_url']);

    return (new \Elonn\Time\ServiceAuthenticator($tokens, $verifier))->authenticate($method, $path, $body);
}

/** @return array<int, array<string, mixed>> */
function timeSearchObjects(PDO $pdo, string $memberId, string $text, int $limit): array
{
    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($text)) . '%';
    $stmt = $pdo->prepare(
        'SELECT o.id, o.identity_user_id, o.calendar_id, o.uri, o.uid, o.component_type, o.title, o.description, o.location,
                o.starts_at, o.ends_at, o.due_at, o.completed_at, o.timezone, o.all_day, o.status, o.priority,
                o.recurrence_rule, o.attendees, o.source_service, o.source_object_type, o.source_object_id, o.source_url, o.created_at, o.updated_at,
                c.name AS calendar_name,
                CASE
                    WHEN LOWER(o.title) = :exact THEN 1.0
                    WHEN LOWER(o.title) LIKE :title_prefix THEN 0.86
                    WHEN LOWER(o.title) LIKE :title_like THEN 0.74
                    WHEN LOWER(COALESCE(o.description, "")) LIKE :description_like THEN 0.62
                    WHEN LOWER(COALESCE(o.location, "")) LIKE :location_like THEN 0.58
                    ELSE 0.0
                END AS search_confidence
         FROM time_calendar_objects o
         INNER JOIN time_calendars c ON c.id = o.calendar_id
         WHERE o.identity_user_id = :member_id
           AND o.status <> "deleted"
           AND c.status = "active"
           AND (
                LOWER(o.title) LIKE :title_filter
                OR LOWER(COALESCE(o.description, "")) LIKE :description_filter
                OR LOWER(COALESCE(o.location, "")) LIKE :location_filter
           )
         ORDER BY search_confidence DESC, COALESCE(o.starts_at, o.due_at, o.updated_at, o.created_at) DESC, o.id DESC
         LIMIT ' . max(1, min(25, $limit))
    );
    $stmt->execute([
        'member_id' => $memberId,
        'exact' => mb_strtolower($text),
        'title_prefix' => mb_strtolower($text) . '%',
        'title_like' => $like,
        'description_like' => $like,
        'location_like' => $like,
        'title_filter' => $like,
        'description_filter' => $like,
        'location_filter' => $like,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array<int, array<string, mixed>> */
function timeRecentObjects(PDO $pdo, string $memberId, int $limit): array
{
    $stmt = $pdo->prepare(
        'SELECT o.id, o.identity_user_id, o.calendar_id, o.uri, o.uid, o.component_type, o.title, o.description, o.location,
                o.starts_at, o.ends_at, o.due_at, o.completed_at, o.timezone, o.all_day, o.status, o.priority,
                o.recurrence_rule, o.attendees, o.source_service, o.source_object_type, o.source_object_id, o.source_url, o.created_at, o.updated_at,
                c.name AS calendar_name,
                0.5 AS search_confidence
         FROM time_calendar_objects o
         INNER JOIN time_calendars c ON c.id = o.calendar_id
         WHERE o.identity_user_id = :member_id
           AND o.status <> "deleted"
           AND c.status = "active"
         ORDER BY COALESCE(o.starts_at, o.due_at, o.updated_at, o.created_at) DESC, o.id DESC
         LIMIT ' . max(1, min(25, $limit))
    );
    $stmt->execute(['member_id' => $memberId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array<string, mixed>|null */
function timeFetchObject(PDO $pdo, string $memberId, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT o.id, o.identity_user_id, o.calendar_id, o.uri, o.uid, o.component_type, o.title, o.description, o.location,
                o.starts_at, o.ends_at, o.due_at, o.completed_at, o.timezone, o.all_day, o.status, o.priority,
                o.recurrence_rule, o.attendees, o.source_service, o.source_object_type, o.source_object_id, o.source_url, o.created_at, o.updated_at,
                c.name AS calendar_name,
                1.0 AS search_confidence
         FROM time_calendar_objects o
         INNER JOIN time_calendars c ON c.id = o.calendar_id
         WHERE o.id = :id AND o.identity_user_id = :member_id AND o.status <> "deleted" AND c.status = "active"'
    );
    $stmt->execute(['id' => $id, 'member_id' => $memberId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : $row;
}

/** A Dataset action's object_id is always "time.calendar_object:<numeric id>" -- parse that back out. */
function timeCalendarObjectIdFrom(mixed $objectId): ?int
{
    $parts = explode(':', trim((string) $objectId), 2);
    return $parts[0] === 'time.calendar_object' ? positiveInt($parts[1] ?? null) : null;
}

/** A Dataset action's object_id is always "time.calendar:<numeric id>" -- parse that back out. */
function timeCalendarIdFrom(mixed $objectId): ?int
{
    $parts = explode(':', trim((string) $objectId), 2);
    return $parts[0] === 'time.calendar' ? positiveInt($parts[1] ?? null) : null;
}

function timeParseDateTime(mixed $value, ?string $timezone): ?DateTimeImmutable
{
    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }
    try {
        $zone = ($timezone !== null && $timezone !== '') ? new DateTimeZone($timezone) : null;
        return new DateTimeImmutable($text, $zone);
    } catch (\Throwable) {
        return null;
    }
}

/**
 * Builds the field set CalendarStore::create()/update() expect from a time.event.* Call's
 * content, validating along the way. $requireCore is true for time.event.create (title and
 * starts_at must be present; ends_at defaults to starts_at + 1 hour) and false for
 * time.event.update (every field is optional; only the keys present in $content are returned,
 * so CalendarStore::update()'s merge leaves everything else untouched).
 *
 * Date/timezone contract: starts_at/ends_at are ISO 8601 and may carry their own UTC offset
 * (help.starts_at's documented shape); that offset is always honored over everything else, per
 * "preserve offsets or convert explicitly." $defaultTimezone is the zone used only when a value
 * carries no offset of its own -- the caller resolves this *after* picking the target calendar
 * (its own declared zone, or UTC) for a create, or the existing object's current zone for an
 * update, never a guess made before the calendar is known. CalendarObject::build() expects a
 * bare wall-clock string paired with a 'timezone' field naming the zone those digits are in, so
 * every parsed instant is explicitly converted into $defaultTimezone before formatting, rather
 * than formatted in whatever zone happened to survive parsing.
 *
 * @param array<string, mixed> $content
 * @return array{0: array<string, mixed>, 1: ?string}
 */
function timeEventFieldsFromContent(array $content, bool $requireCore, string $defaultTimezone): array
{
    $fields = [];

    if (array_key_exists('title', $content)) {
        $title = trim((string) $content['title']);
        if ($title === '') {
            return [[], 'title cannot be empty.'];
        }
        $fields['title'] = $title;
    } elseif ($requireCore) {
        return [[], 'title is required.'];
    }

    $timezoneInput = cleanOptionalString($content['timezone'] ?? null);
    if ($timezoneInput !== null && validTimezone($timezoneInput) === null) {
        return [[], 'timezone is not valid.'];
    }
    $effectiveTimezone = $timezoneInput ?? $defaultTimezone;
    if ($timezoneInput !== null || $requireCore) {
        // An explicit override always sticks; a brand-new object must always declare a zone
        // (its own default or UTC) since none exists yet to fall back on.
        $fields['timezone'] = $effectiveTimezone;
    }
    $effectiveZone = new DateTimeZone($effectiveTimezone);

    $allDay = array_key_exists('all_day', $content) ? truthy($content['all_day']) : null;
    if ($allDay !== null) {
        $fields['all_day'] = $allDay ? 1 : 0;
    }

    $startsAt = null;
    if (array_key_exists('starts_at', $content)) {
        $startsAt = timeParseDateTime($content['starts_at'], $effectiveTimezone);
        if ($startsAt === null) {
            return [[], 'starts_at is not a valid date/time.'];
        }
        $fields['starts_at'] = $startsAt->setTimezone($effectiveZone)->format('Y-m-d H:i:s');
    } elseif ($requireCore) {
        return [[], 'starts_at is required.'];
    }

    $endsAtRaw = cleanOptionalString($content['ends_at'] ?? null);
    if ($endsAtRaw !== null) {
        $endsAt = timeParseDateTime($endsAtRaw, $effectiveTimezone);
        if ($endsAt === null) {
            return [[], 'ends_at is not a valid date/time.'];
        }
        $fields['ends_at'] = $endsAt->setTimezone($effectiveZone)->format('Y-m-d H:i:s');
    } elseif ($requireCore && $startsAt !== null) {
        $fields['ends_at'] = $startsAt->modify('+1 hour')->setTimezone($effectiveZone)->format('Y-m-d H:i:s');
    }

    foreach (['location', 'description', 'recurrence_rule', 'attendees'] as $key) {
        if (array_key_exists($key, $content)) {
            $value = trim((string) $content[$key]);
            $fields[$key] = $value === '' ? null : $value;
        }
    }

    return [$fields, null];
}

/**
 * Validates the *complete* effective interval of a VEVENT: starts_at/ends_at after combining
 * whatever the Call actually submits ($fields, from timeEventFieldsFromContent()) with
 * whatever the object already has ($existing, from CalendarStore::find() -- null for a
 * create, which has nothing to combine with since timeEventFieldsFromContent() with
 * requireCore=true always already fills in both sides). Validating $fields in isolation would
 * miss a partial update that only moves one side of the interval past the other -- e.g.
 * submitting only a new ends_at that falls before the event's existing, untouched starts_at.
 *
 * Deliberately does not change how a missing end is filled in: create's own
 * starts_at+1-hour default and update's own duration-preserving logic (when starts_at moves
 * without an explicit ends_at) both run first and already guarantee ends_at > starts_at in
 * their own cases, so this never rejects either of those intended behaviors -- it only catches
 * genuinely inverted intervals.
 *
 * @param array<string, mixed> $fields
 * @param array<string, mixed>|null $existing
 */
function timeValidateEventInterval(array $fields, ?array $existing, string $defaultTimezone): ?string
{
    $zone = new DateTimeZone((string) ($fields['timezone'] ?? $defaultTimezone));
    $resolve = static function (string $key) use ($fields, $existing, $zone): ?DateTimeImmutable {
        if (array_key_exists($key, $fields)) {
            return new DateTimeImmutable((string) $fields[$key], $zone);
        }
        $existingValue = $existing[$key] ?? null;
        return is_string($existingValue) && trim($existingValue) !== '' ? new DateTimeImmutable($existingValue) : null;
    };

    $effectiveStartsAt = $resolve('starts_at');
    $effectiveEndsAt = $resolve('ends_at');
    if ($effectiveStartsAt === null || $effectiveEndsAt === null) {
        return null;
    }

    return $effectiveEndsAt <= $effectiveStartsAt ? 'Event ends_at must be after starts_at.' : null;
}

/**
 * Same shape as timeEventFieldsFromContent(), for time.task.create / time.task.update. Tasks
 * have no 'timezone' Contract argument of their own, but due_at/starts_at still need a zone to
 * interpret an offset-less value and to pair with the naive string CalendarObject::build()
 * expects -- see timeEventFieldsFromContent()'s doc comment for the full contract.
 *
 * @param array<string, mixed> $content
 * @return array{0: array<string, mixed>, 1: ?string}
 */
function timeTaskFieldsFromContent(array $content, bool $requireCore, string $defaultTimezone): array
{
    $fields = [];

    if (array_key_exists('title', $content)) {
        $title = trim((string) $content['title']);
        if ($title === '') {
            return [[], 'title cannot be empty.'];
        }
        $fields['title'] = $title;
    } elseif ($requireCore) {
        return [[], 'title is required.'];
    }

    if ($requireCore) {
        $fields['timezone'] = $defaultTimezone;
    }
    $effectiveZone = new DateTimeZone($defaultTimezone);

    foreach (['due_at', 'starts_at'] as $key) {
        if (!array_key_exists($key, $content)) {
            continue;
        }
        $raw = cleanOptionalString($content[$key] ?? null);
        if ($raw === null) {
            $fields[$key] = null;
            continue;
        }
        $parsed = timeParseDateTime($raw, $defaultTimezone);
        if ($parsed === null) {
            return [[], $key . ' is not a valid date/time.'];
        }
        $fields[$key] = $parsed->setTimezone($effectiveZone)->format('Y-m-d H:i:s');
    }

    if (array_key_exists('priority', $content)) {
        if ($content['priority'] === null || $content['priority'] === '') {
            $fields['priority'] = null;
        } else {
            $priority = (int) $content['priority'];
            if ($priority < 0 || $priority > 9) {
                return [[], 'priority must be between 0 and 9.'];
            }
            $fields['priority'] = $priority;
        }
    }

    foreach (['description', 'recurrence_rule'] as $key) {
        if (array_key_exists($key, $content)) {
            $value = trim((string) $content[$key]);
            $fields[$key] = $value === '' ? null : $value;
        }
    }

    return [$fields, null];
}

/** @return array<int, array<string, mixed>> */
function timeAgendaObjects(PDO $pdo, string $memberId, DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $utc = new DateTimeZone('UTC');
    $stmt = $pdo->prepare(
        'SELECT o.id, o.identity_user_id, o.calendar_id, o.uri, o.uid, o.component_type, o.title, o.description, o.location,
                o.starts_at, o.ends_at, o.due_at, o.completed_at, o.timezone, o.all_day, o.status, o.priority,
                o.recurrence_rule, o.attendees, o.calendar_data, o.source_service, o.source_object_type, o.source_object_id,
                o.source_url, o.created_at, o.updated_at, c.name AS calendar_name, 1.0 AS search_confidence
         FROM time_calendar_objects o
         INNER JOIN time_calendars c ON c.id = o.calendar_id
         WHERE o.identity_user_id = :member_id
           AND o.component_type = "VEVENT"
           AND o.status <> "deleted"
           AND c.status = "active"
           AND (
                o.recurrence_rule IS NOT NULL
                OR (o.starts_at IS NOT NULL AND o.starts_at < :range_end AND COALESCE(o.ends_at, o.starts_at) >= :range_start)
           )
         ORDER BY o.starts_at, o.id'
    );
    $stmt->execute([
        'member_id' => $memberId,
        'range_start' => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
        'range_end' => $end->setTimezone($utc)->format('Y-m-d H:i:s'),
    ]);

    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $calendarData = (string) $row['calendar_data'];
        unset($row['calendar_data']);
        if (($row['recurrence_rule'] ?? null) === null) {
            $result[] = $row;
            continue;
        }
        try {
            $calendar = \Sabre\VObject\Reader::read($calendarData);
            $iterator = new \Sabre\VObject\Recur\EventIterator($calendar, (string) $row['uid']);
            $iterator->fastForward($start);
            while ($iterator->valid() && $iterator->getDTStart() < $end) {
                $occurrence = $row;
                $occurrence['occurrence_start'] = $iterator->getDTStart()->setTimezone($utc)->format('Y-m-d H:i:s');
                $occurrence['occurrence_end'] = $iterator->getDTEnd()->setTimezone($utc)->format('Y-m-d H:i:s');
                $result[] = $occurrence;
                $iterator->next();
            }
        } catch (\Throwable) {
            $result[] = $row;
        }
    }
    return $result;
}

/** @return array<int, array<string, mixed>> */
function timeTaskObjects(PDO $pdo, string $memberId, string $status, ?DateTimeImmutable $dueBefore, ?DateTimeImmutable $dueAfter): array
{
    $utc = new DateTimeZone('UTC');
    $sql = 'SELECT o.id, o.identity_user_id, o.calendar_id, o.uri, o.uid, o.component_type, o.title, o.description, o.location,
                   o.starts_at, o.ends_at, o.due_at, o.completed_at, o.timezone, o.all_day, o.status, o.priority,
                   o.recurrence_rule, o.attendees, o.source_service, o.source_object_type, o.source_object_id, o.source_url,
                   o.created_at, o.updated_at, c.name AS calendar_name, 1.0 AS search_confidence
            FROM time_calendar_objects o
            INNER JOIN time_calendars c ON c.id = o.calendar_id
            WHERE o.identity_user_id = :member_id AND o.component_type = "VTODO" AND o.status <> "deleted" AND c.status = "active"';
    $params = ['member_id' => $memberId];

    if ($status === 'open') {
        $sql .= ' AND o.completed_at IS NULL';
    } elseif ($status === 'completed') {
        $sql .= ' AND o.completed_at IS NOT NULL';
    }
    if ($dueBefore !== null) {
        $sql .= ' AND o.due_at IS NOT NULL AND o.due_at < :due_before';
        $params['due_before'] = $dueBefore->setTimezone($utc)->format('Y-m-d H:i:s');
    }
    if ($dueAfter !== null) {
        $sql .= ' AND o.due_at IS NOT NULL AND o.due_at >= :due_after';
        $params['due_after'] = $dueAfter->setTimezone($utc)->format('Y-m-d H:i:s');
    }
    $sql .= ' ORDER BY (o.completed_at IS NOT NULL), (o.due_at IS NULL), o.due_at, o.priority, o.id';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * The real actions every Time calendar carries: Edit is always offered (renaming, recoloring, or
 * re-timezoning a Social mirror calendar is still the member's own local preference); Delete is
 * withheld for a Social-owned mirror calendar, which CalendarStore::deleteCalendar() refuses
 * server-side -- withholding the action here just keeps the Dashboard from offering one that
 * would fail.
 *
 * @return array<int, array<string, mixed>>
 */
function timeCalendarObjectActions(string $id, array $calendar): array
{
    $actions = [[
        'id' => 'update_calendar',
        'type' => 'update_object',
        'label' => 'Edit',
        // dev.elonn canonical/action.md, Initial values: the calendar's own current
        // name/description/color/timezone, matched to time.calendar.update's declared
        // arguments -- a Runtime's generic form submits every rendered field, including ones
        // the member never touched, so an empty payload here would submit blanks for them
        // and silently clear description/color/timezone on an unrelated edit.
        'operation_invocation' => ['service' => 'time.elonn', 'operation' => 'time.calendar.update', 'object_id' => $id, 'payload' => [
            'name' => (string) $calendar['name'],
            'description' => $calendar['description'],
            'color' => $calendar['color'],
            'timezone' => $calendar['timezone'],
        ]],
        'availability' => 'enabled',
    ]];

    if (($calendar['deletable'] ?? true) === true) {
        $actions[] = [
            'id' => 'delete_calendar',
            'type' => 'delete_object',
            'label' => 'Delete',
            'operation_invocation' => ['service' => 'time.elonn', 'operation' => 'time.calendar.delete', 'object_id' => $id, 'payload' => []],
            'availability' => 'enabled',
        ];
    }

    return $actions;
}

/** @param array<int, array<string, mixed>> $calendar @return array<string, mixed> */
function timeCalendarObject(array $calendar): array
{
    $id = 'time.calendar:' . (string) $calendar['id'];
    $content = [
        'color' => $calendar['color'] ?? null,
        'timezone' => $calendar['timezone'] ?? null,
        'components' => (string) ($calendar['components'] ?? ''),
        'source' => ($calendar['source_service'] ?? null) === null ? null : ['service' => (string) $calendar['source_service']],
    ];
    $bodyNodes = timeCalendarBodyNodes($content);
    if ($bodyNodes !== []) {
        $content['body'] = $bodyNodes;
    }
    return [
        'id' => $id,
        'type' => 'time.calendar',
        // Canonical text fields (dev.elonn canonical/object.md): a calendar's real stored data
        // is its name and its (optional) description -- there is no distinct title or
        // summary concept, so those stay blank rather than being filled by copying name's or
        // description's value.
        'title' => '',
        'name' => (string) $calendar['name'],
        'summary' => '',
        'description' => (string) ($calendar['description'] ?? ''),
        'content' => $content,
        'permissions' => ['can_view' => true, 'can_act' => true, 'can_share' => false],
        'resources' => [],
        'actions' => ['local' => timeCalendarObjectActions($id, $calendar), 'inbound' => [], 'outbound' => []],
        'relationships' => [],
        'metadata' => ['service' => 'time', 'source_id' => (string) $calendar['id']],
    ];
}

/** @param array<int, array<string, mixed>> $calendars @return array<string, mixed> */
function timeCalendarsDataset(array $calendars, string $caller): array
{
    $objects = array_map('timeCalendarObject', $calendars);
    $collectionId = 'collection:time.calendars:' . bin2hex(random_bytes(8));
    $summary = count($objects) === 0 ? 'No calendars are available yet.' : count($objects) . ' calendars available.';

    return [
        'id' => 'dataset:service:time:' . bin2hex(random_bytes(16)),
        'type' => 'service',
        'scope' => count($objects) === 1 ? 'object' : 'collection',
        'mode' => 'snapshot',
        'created' => gmdate('c'),
        'objects' => $objects,
        'actions' => timeDatasetActions($objects),
        'relationships' => [],
        'collections' => count($objects) !== 1 ? [[
            'id' => $collectionId,
            'type' => 'time.calendars.results',
            // Canonical text fields: this generated container has one real concept, a
            // heading, not a separate identifying name -- name stays blank. summary carries
            // the one real sentence Time has for this result set; description has no
            // distinct concept beyond it, so it stays empty rather than being filled by
            // copying (dev.elonn canonical/object.md, Canonical text fields).
            'title' => 'Calendars',
            'name' => '',
            'summary' => $summary,
            'description' => '',
            'items' => array_map(static fn (array $o): string => (string) $o['id'], $objects),
            'content' => ['count' => count($objects)],
        ]] : [],
        'resources' => [],
        'placements' => [],
        'errors' => [],
        'context' => ['service' => 'time', 'operation' => 'time.calendars', 'caller' => $caller],
    ];
}

/** @param array<string, mixed> $row @return array<string, mixed> */
function timeOpenDataset(array $row, string $operation, string $caller): array
{
    $object = timeObjectFromRow($row);

    return [
        'id' => 'dataset:service:time:' . bin2hex(random_bytes(16)),
        'type' => 'service',
        'scope' => 'object',
        'mode' => 'snapshot',
        'created' => gmdate('c'),
        'objects' => [$object],
        'actions' => timeDatasetActions([$object]),
        'relationships' => [],
        'collections' => [],
        'resources' => timeDatasetResources([$object]),
        // No `id` here: World mints a Placement's id itself (placement.md, Placement identity).
        'placements' => [['type' => 'carry', 'content' => ['object' => $object['id']]]],
        'errors' => [],
        'context' => ['service' => 'time', 'operation' => $operation, 'caller' => $caller],
    ];
}

/**
 * Formats a stored instant (always a UTC-naive 'Y-m-d H:i:s' string, per the storage
 * convention CalendarObject::parse() and CalendarStore::payload() both use) for the Contract
 * boundary. An all-day value carries no time-of-day or zone of its own, so it becomes a bare
 * 'Y-m-d' date; a timed value becomes an ISO 8601 datetime with an explicit offset, expressed
 * in the object's own declared timezone (falling back to UTC) so a caller sees the same local
 * time the event was created or last edited in.
 */
function timeFormatContractInstant(mixed $storedUtc, bool $allDay, mixed $timezone): ?string
{
    if (!is_string($storedUtc) || trim($storedUtc) === '') {
        return null;
    }
    if ($allDay) {
        return substr($storedUtc, 0, 10);
    }
    $zoneName = is_string($timezone) ? validTimezone($timezone) : null;
    $date = new DateTimeImmutable($storedUtc, new DateTimeZone('UTC'));
    return $date->setTimezone(new DateTimeZone($zoneName ?? 'UTC'))->format(DATE_ATOM);
}

/** @param array<string, mixed> $row @return array<string, mixed> */
function timeObjectFromRow(array $row): array
{
    $id = 'time.calendar_object:' . (string) $row['id'];
    $componentType = (string) $row['component_type'];
    $objectType = $componentType === 'VTODO' ? 'time.task' : 'time.calendar_event';
    $sourceUrl = trim((string) ($row['source_url'] ?? ''));
    $allDay = truthy($row['all_day'] ?? false);
    $timezone = $row['timezone'] ?? null;

    $content = [
        'component_type' => $componentType,
        'location' => $row['location'] ?? null,
        'starts_at' => timeFormatContractInstant($row['occurrence_start'] ?? $row['starts_at'] ?? null, $allDay, $timezone),
        'ends_at' => timeFormatContractInstant($row['occurrence_end'] ?? $row['ends_at'] ?? null, $allDay, $timezone),
        'due_at' => timeFormatContractInstant($row['due_at'] ?? null, $allDay, $timezone),
        'completed_at' => timeFormatContractInstant($row['completed_at'] ?? null, false, $timezone),
        'all_day' => $allDay,
        'status' => (string) ($row['status'] ?? ''),
        'priority' => $row['priority'] ?? null,
        'recurrence_rule' => $row['recurrence_rule'] ?? null,
        'calendar' => (string) ($row['calendar_name'] ?? ''),
        'attendees' => timeFormatAttendees($row['attendees'] ?? null),
        'source' => [
            'service' => $row['source_service'] ?? null,
            'object_type' => $row['source_object_type'] ?? null,
            'object_id' => $row['source_object_id'] ?? null,
            'url' => $sourceUrl !== '' ? $sourceUrl : null,
        ],
        'search' => [
            'confidence' => (float) ($row['search_confidence'] ?? 0.5),
            'index_status' => 'ready',
            'why' => 'Matched calendar object title, description, or location.',
        ],
    ];
    $bodyNodes = timeCalendarObjectBodyNodes($content);
    if ($bodyNodes !== []) {
        $content['body'] = $bodyNodes;
    }

    return [
        'id' => $id,
        'type' => $objectType,
        // Canonical text fields: the underlying calendar format defines this value (stored
        // here under the 'title' key) as its SUMMARY property -- a condensed account, not a
        // heading. It maps to summary, not title. There is no distinct heading or identifying
        // name concept for an event/task, so title and name stay blank.
        'title' => '',
        'name' => '',
        'summary' => (string) $row['title'],
        'description' => (string) ($row['description'] ?? ''),
        'content' => $content,
        'permissions' => ['can_view' => true, 'can_act' => true, 'can_share' => false],
        'resources' => $sourceUrl !== '' ? ['resource:' . $id . ':source'] : [],
        'actions' => [
            'local' => timeObjectActions($id, $componentType, $row),
            'inbound' => [],
            'outbound' => [],
        ],
        'relationships' => [],
        'metadata' => ['service' => 'time', 'source_id' => (string) $row['id'], 'uid' => (string) ($row['uid'] ?? '')],
    ];
}

/**
 * Renders a stored attendees JSON payload back into the same comma-separated
 * "Name <email>" / bare-email string shape the Contract's `attendees` argument
 * accepts, so a generic Runtime form prefills with editable text, not raw JSON.
 */
function timeFormatAttendees(mixed $value): string
{
    if (!is_string($value) || trim($value) === '') {
        return '';
    }
    $decoded = json_decode($value, true);
    if (!is_array($decoded)) {
        return '';
    }
    $parts = [];
    foreach ($decoded as $attendee) {
        if (!is_array($attendee)) {
            continue;
        }
        $email = trim((string) ($attendee['email'] ?? ''));
        if ($email === '') {
            continue;
        }
        $name = trim((string) ($attendee['name'] ?? ''));
        $parts[] = $name !== '' ? $name . ' <' . $email . '>' : $email;
    }
    return implode(', ', $parts);
}

/**
 * Human-readable instant text for a calendar object's Body (dev.elonn canonical/object.md,
 * Content body), reusing the already-formatted content.starts_at/ends_at/due_at the Head
 * fields already carry (timeFormatContractInstant()) rather than re-deriving from raw storage.
 */
function timeFormatHumanInstant(string $iso, bool $dateOnly): string
{
    $date = new DateTimeImmutable($iso);
    return $dateOnly ? $date->format('l, F j, Y') : $date->format('l, F j, Y, g:i A');
}

/** Body schedule text for a VEVENT: a single start, a start/end on one day, or a start/end
 * spanning multiple days -- timed or all-day. Null when there is no starts_at at all. */
function timeEventScheduleText(array $content): ?string
{
    $startsAt = $content['starts_at'] ?? null;
    if (!is_string($startsAt) || $startsAt === '') {
        return null;
    }
    $endsAt = is_string($content['ends_at'] ?? null) && $content['ends_at'] !== '' ? $content['ends_at'] : null;
    $allDay = (bool) ($content['all_day'] ?? false);

    if ($allDay) {
        $startText = timeFormatHumanInstant($startsAt, true);
        if ($endsAt === null || substr($endsAt, 0, 10) === substr($startsAt, 0, 10)) {
            return $startText . ' (all day)';
        }
        return $startText . ' - ' . timeFormatHumanInstant($endsAt, true) . ' (all day)';
    }

    $zoneName = is_string($content['timezone'] ?? null) ? trim($content['timezone']) : '';
    $zoneSuffix = $zoneName !== '' ? ' (' . $zoneName . ')' : '';
    $startText = timeFormatHumanInstant($startsAt, false);
    if ($endsAt === null) {
        return $startText . $zoneSuffix;
    }
    if (substr($endsAt, 0, 10) === substr($startsAt, 0, 10)) {
        return $startText . ' - ' . (new DateTimeImmutable($endsAt))->format('g:i A') . $zoneSuffix;
    }
    return $startText . ' - ' . timeFormatHumanInstant($endsAt, false) . $zoneSuffix;
}

/** Body schedule text for a VTODO: starts_at and/or due_at, each explicitly labeled since
 * neither one is self-evident the way an event's single start/end range is. Null when
 * neither is set. */
function timeTaskScheduleText(array $content): ?string
{
    $zoneName = is_string($content['timezone'] ?? null) ? trim($content['timezone']) : '';
    $zoneSuffix = $zoneName !== '' ? ' (' . $zoneName . ')' : '';
    $parts = [];
    if (is_string($content['starts_at'] ?? null) && $content['starts_at'] !== '') {
        $parts[] = 'Starts ' . timeFormatHumanInstant($content['starts_at'], false) . $zoneSuffix;
    }
    if (is_string($content['due_at'] ?? null) && $content['due_at'] !== '') {
        $parts[] = 'Due ' . timeFormatHumanInstant($content['due_at'], false) . $zoneSuffix;
    }
    return $parts === [] ? null : implode('. ', $parts) . '.';
}

/**
 * A human-readable label for a calendar object's iCalendar STATUS vocabulary (CONFIRMED/
 * CANCELLED/TENTATIVE for a VEVENT; NEEDS-ACTION/IN-PROCESS/COMPLETED/CANCELLED for a VTODO;
 * the app's own 'active' alias for CONFIRMED). Falls back to a capitalized form of whatever
 * value is actually stored, rather than silently dropping an unrecognized status.
 */
function timeHumanStatus(string $status): string
{
    $normalized = strtolower(trim($status));
    if ($normalized === '') {
        return '';
    }
    return match ($normalized) {
        'confirmed', 'active' => 'Confirmed',
        'cancelled', 'canceled' => 'Cancelled',
        'tentative' => 'Tentative',
        'needs-action' => 'Needs action',
        'in-process' => 'In progress',
        'completed' => 'Completed',
        default => ucfirst(str_replace(['-', '_'], ' ', $normalized)),
    };
}

/**
 * A bounded-effort, always-produces-something natural-language rendering of an RFC 5545 RRULE
 * string's most common shape (FREQ, INTERVAL, BYDAY, COUNT, UNTIL). An RRULE this doesn't fully
 * understand still renders as a plain 'Repeats' sentence with whatever parts it did recognize,
 * rather than surfacing the raw RRULE syntax to a member.
 */
function timeHumanRecurrence(string $rrule): string
{
    $parts = [];
    foreach (explode(';', $rrule) as $pair) {
        $keyValue = explode('=', $pair, 2);
        if (count($keyValue) === 2 && trim($keyValue[0]) !== '') {
            $parts[strtoupper(trim($keyValue[0]))] = trim($keyValue[1]);
        }
    }

    $dayNames = ['MO' => 'Monday', 'TU' => 'Tuesday', 'WE' => 'Wednesday', 'TH' => 'Thursday', 'FR' => 'Friday', 'SA' => 'Saturday', 'SU' => 'Sunday'];
    $interval = max(1, (int) ($parts['INTERVAL'] ?? 1));
    $sentence = match (strtoupper($parts['FREQ'] ?? '')) {
        'DAILY' => $interval === 1 ? 'Repeats daily' : "Repeats every {$interval} days",
        'WEEKLY' => $interval === 1 ? 'Repeats weekly' : "Repeats every {$interval} weeks",
        'MONTHLY' => $interval === 1 ? 'Repeats monthly' : "Repeats every {$interval} months",
        'YEARLY' => $interval === 1 ? 'Repeats yearly' : "Repeats every {$interval} years",
        default => 'Repeats',
    };

    if (isset($parts['BYDAY'])) {
        $days = array_map(
            static fn (string $day): string => $dayNames[strtoupper(trim($day))] ?? trim($day),
            explode(',', $parts['BYDAY'])
        );
        $sentence .= ' on ' . implode(', ', $days);
    }
    if (isset($parts['COUNT'])) {
        $sentence .= ', ' . $parts['COUNT'] . ' times';
    } elseif (isset($parts['UNTIL'])) {
        $sentence .= ' until ' . $parts['UNTIL'];
    }

    return $sentence . '.';
}

/**
 * Canonical Body nodes (dev.elonn canonical/object.md, Content body) presenting a calendar
 * event or task's substantive, human-readable information -- applicable dates/times,
 * location, attendees, status, recurrence, and calendar -- for generic Runtime presentation.
 * This is additive, never a replacement: the same information already lives in this Object's
 * structured content fields (starts_at, location, attendees, etc.) for operations, which stay
 * exactly as they are (dev.elonn canonical/object.md, Content: Head and Body). Body is also
 * independent of, and never duplicates, the four canonical text fields -- summary already
 * carries this Object's own condensed account (its SUMMARY property) and description its own
 * notes; neither is re-presented here. A section this Object has no real data for is simply
 * omitted, never manufactured empty (same doc, Content body).
 *
 * @param array<string, mixed> $content
 * @return array<int, array<string, mixed>>
 */
function timeCalendarObjectBodyNodes(array $content): array
{
    $nodes = [];

    $schedule = (string) ($content['component_type'] ?? '') === 'VTODO'
        ? timeTaskScheduleText($content)
        : timeEventScheduleText($content);
    if ($schedule !== null) {
        $nodes[] = ['node' => 'section', 'kind' => 'schedule', 'content' => [
            ['node' => 'paragraph', 'text' => $schedule],
        ]];
    }

    $location = trim((string) ($content['location'] ?? ''));
    if ($location !== '') {
        $nodes[] = ['node' => 'section', 'kind' => 'location', 'content' => [
            ['node' => 'paragraph', 'text' => $location],
        ]];
    }

    $attendeeEntries = array_values(array_filter(array_map(
        'trim',
        explode(',', is_string($content['attendees'] ?? null) ? $content['attendees'] : '')
    ), static fn (string $entry): bool => $entry !== ''));
    if ($attendeeEntries !== []) {
        $nodes[] = ['node' => 'section', 'kind' => 'attendees', 'content' => [
            ['node' => 'list', 'ordered' => false, 'items' => array_map(
                static fn (string $entry): array => ['content' => [['node' => 'paragraph', 'text' => $entry]]],
                $attendeeEntries
            )],
        ]];
    }

    $status = timeHumanStatus((string) ($content['status'] ?? ''));
    if ($status !== '') {
        $nodes[] = ['node' => 'section', 'kind' => 'status', 'content' => [
            ['node' => 'paragraph', 'text' => $status],
        ]];
    }

    $recurrenceRule = trim((string) ($content['recurrence_rule'] ?? ''));
    if ($recurrenceRule !== '') {
        $nodes[] = ['node' => 'section', 'kind' => 'recurrence', 'content' => [
            ['node' => 'paragraph', 'text' => timeHumanRecurrence($recurrenceRule)],
        ]];
    }

    $calendarName = trim((string) ($content['calendar'] ?? ''));
    if ($calendarName !== '') {
        $nodes[] = ['node' => 'section', 'kind' => 'calendar', 'content' => [
            ['node' => 'paragraph', 'text' => $calendarName],
        ]];
    }

    return $nodes;
}

/**
 * Canonical Body nodes for a time.calendar Object: its own substantive properties worth a
 * member seeing -- which component types it supports, its declared time zone, and (for a
 * Social-owned mirror) what it mirrors. Lighter than an event/task's Body since a calendar has
 * fewer substantive properties; a property with no real value is simply omitted.
 *
 * @param array<string, mixed> $content
 * @return array<int, array<string, mixed>>
 */
function timeCalendarBodyNodes(array $content): array
{
    $nodes = [];

    $componentTypes = array_filter(array_map('trim', explode(',', (string) ($content['components'] ?? ''))));
    $supports = array_values(array_filter([
        in_array('VEVENT', $componentTypes, true) ? 'events' : null,
        in_array('VTODO', $componentTypes, true) ? 'tasks' : null,
    ]));
    if ($supports !== []) {
        $nodes[] = ['node' => 'section', 'kind' => 'components', 'content' => [
            ['node' => 'paragraph', 'text' => 'Supports ' . implode(' and ', $supports) . '.'],
        ]];
    }

    $timezone = is_string($content['timezone'] ?? null) ? trim($content['timezone']) : '';
    if ($timezone !== '') {
        $nodes[] = ['node' => 'section', 'kind' => 'timezone', 'content' => [
            ['node' => 'paragraph', 'text' => $timezone],
        ]];
    }

    $source = is_array($content['source'] ?? null) ? $content['source'] : null;
    $sourceService = $source !== null ? trim((string) ($source['service'] ?? '')) : '';
    if ($sourceService !== '') {
        $nodes[] = ['node' => 'section', 'kind' => 'source', 'content' => [
            ['node' => 'paragraph', 'text' => 'Mirrored from ' . ucfirst($sourceService) . '.'],
        ]];
    }

    return $nodes;
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<string, mixed>
 */
function timeServiceDataset(array $rows, string $operation, string $caller, string $text): array
{
    $objects = [];
    foreach ($rows as $row) {
        $objects[] = timeObjectFromRow($row);
    }
    $collectionId = 'collection:time.search:' . bin2hex(random_bytes(8));
    $summary = count($objects) === 0
        ? ($text !== '' ? 'No Time objects matched "' . $text . '".' : 'No Time objects are available.')
        : count($objects) . ' Time objects matched.';

    return [
        'id' => 'dataset:service:time:' . bin2hex(random_bytes(16)),
        'type' => 'service',
        'scope' => count($objects) === 1 ? 'object' : 'collection',
        'mode' => 'snapshot',
        'created' => gmdate('c'),
        'objects' => $objects,
        'actions' => timeDatasetActions($objects),
        'relationships' => [],
        'collections' => count($objects) !== 1 ? [[
            'id' => $collectionId,
            'type' => 'time.search.results',
            // Canonical text fields: one real concept, a heading -- name stays blank.
            // summary carries the one real sentence Time has for this result set;
            // description has no distinct concept beyond it, so it stays empty rather
            // than being filled by copying (dev.elonn canonical/object.md, Canonical
            // text fields).
            'title' => $text !== '' ? 'Time results for ' . $text : 'Time results',
            'name' => '',
            'summary' => $summary,
            'description' => '',
            'items' => array_map(static fn (array $object): string => (string) $object['id'], $objects),
            'content' => ['query' => $text, 'count' => count($objects)],
        ]] : [],
        'resources' => timeDatasetResources($objects),
        // A search/list result places nothing: its Objects are unplaced Findings.
        'placements' => [],
        'errors' => [],
        'context' => [
            'service' => 'time',
            'operation' => $operation,
            'caller' => $caller,
            'search' => ['query_text' => $text, 'result_count' => count($objects)],
        ],
    ];
}

/**
 * The real actions every Time calendar object carries -- real Contract operation_invocations,
 * not a dead href into time.elonn.local's own REST routes, which a browser talking only to
 * web.elonn.local can never reach directly. A Social-owned mirror only ever gets Open: its core
 * fields are read-only here (CalendarStore::update()/delete() already enforce this server-side;
 * this just keeps a mirror from offering an action that would fail).
 *
 * @param array<string, mixed> $row
 * @return array<int, array<string, mixed>>
 */
function timeObjectActions(string $id, string $componentType, array $row): array
{
    $actions = [[
        'id' => 'open_time_object',
        'type' => 'open_object',
        'label' => 'Open',
        'operation_invocation' => [
            'service' => 'time.elonn',
            'operation' => 'time.open',
            'object_id' => $id,
            'payload' => [],
        ],
        'availability' => 'enabled',
    ]];

    if (($row['source_service'] ?? null) === 'social') {
        return $actions;
    }

    if ($componentType === 'VTODO') {
        $isCompleted = ($row['completed_at'] ?? null) !== null || strtolower((string) ($row['status'] ?? '')) === 'completed';
        $actions[] = [
            'id' => 'update_task',
            'type' => 'update_object',
            'label' => 'Edit',
            // dev.elonn canonical/action.md, Initial values: the task's own current field
            // values, matched to time.task.update's declared arguments -- a Runtime's
            // generic form submits every rendered field, including ones the member never
            // touched, so an empty payload here would submit blanks for them and silently
            // clear description/due_at/priority/etc. on an unrelated edit.
            'operation_invocation' => ['service' => 'time.elonn', 'operation' => 'time.task.update', 'object_id' => $id, 'payload' => [
                'title' => (string) $row['title'],
                'description' => $row['description'] ?? null,
                // timeFormatContractInstant(), not the raw stored UTC-naive string: a Runtime
                // that resubmits this value unchanged would otherwise have it reinterpreted as
                // wall-clock-in-the-task's-own-zone by timeTaskFieldsFromContent(), shifting
                // the real instant by that zone's offset on every untouched save.
                'due_at' => timeFormatContractInstant($row['due_at'] ?? null, false, $row['timezone'] ?? null),
                'starts_at' => timeFormatContractInstant($row['starts_at'] ?? null, false, $row['timezone'] ?? null),
                'priority' => $row['priority'] ?? null,
                'recurrence_rule' => $row['recurrence_rule'] ?? null,
                // The task's current calendar, by name: a Runtime resubmitting this unchanged
                // preserves membership (time.task.update matches it back to the same calendar);
                // submitting it blank also preserves membership (the handler treats blank the
                // same as omitted), so only a deliberately different name actually moves it.
                'calendar' => (string) ($row['calendar_name'] ?? ''),
            ]],
            'availability' => 'enabled',
        ];
        $actions[] = $isCompleted
            ? [
                'id' => 'reopen_task',
                'type' => 'reopen_task',
                'label' => 'Reopen',
                'operation_invocation' => ['service' => 'time.elonn', 'operation' => 'time.task.reopen', 'object_id' => $id, 'payload' => []],
                'availability' => 'enabled',
            ]
            : [
                'id' => 'complete_task',
                'type' => 'complete_task',
                'label' => 'Complete',
                'operation_invocation' => ['service' => 'time.elonn', 'operation' => 'time.task.complete', 'object_id' => $id, 'payload' => []],
                'availability' => 'enabled',
            ];
        $actions[] = [
            'id' => 'delete_task',
            'type' => 'delete_object',
            'label' => 'Delete',
            'operation_invocation' => ['service' => 'time.elonn', 'operation' => 'time.task.delete', 'object_id' => $id, 'payload' => []],
            'availability' => 'enabled',
        ];
        return $actions;
    }

    $actions[] = [
        'id' => 'update_event',
        'type' => 'update_object',
        'label' => 'Edit',
        // dev.elonn canonical/action.md, Initial values: the event's own current field
        // values, matched to time.event.update's declared arguments -- a Runtime's generic
        // form submits every rendered field, including ones the member never touched, so an
        // empty payload here would submit blanks for them and silently clear
        // location/description/recurrence_rule/etc. on an unrelated edit.
        'operation_invocation' => ['service' => 'time.elonn', 'operation' => 'time.event.update', 'object_id' => $id, 'payload' => [
            'title' => (string) $row['title'],
            'timezone' => $row['timezone'] ?? null,
            // timeFormatContractInstant(), not the raw stored UTC-naive string -- see the
            // matching comment on time.task.update's payload just above.
            'starts_at' => timeFormatContractInstant($row['occurrence_start'] ?? $row['starts_at'] ?? null, truthy($row['all_day'] ?? false), $row['timezone'] ?? null),
            'ends_at' => timeFormatContractInstant($row['occurrence_end'] ?? $row['ends_at'] ?? null, truthy($row['all_day'] ?? false), $row['timezone'] ?? null),
            'all_day' => truthy($row['all_day'] ?? false),
            'location' => $row['location'] ?? null,
            'description' => $row['description'] ?? null,
            'recurrence_rule' => $row['recurrence_rule'] ?? null,
            // timeFormatAttendees(), not the raw stored value: the argument is the plain
            // string the member edits (matching content.attendees, built the same way just
            // above), never the internal JSON encoding.
            'attendees' => timeFormatAttendees($row['attendees'] ?? null),
            // The event's current calendar, by name -- see the matching comment on
            // time.task.update's payload above for why blank/unchanged both preserve it.
            'calendar' => (string) ($row['calendar_name'] ?? ''),
        ]],
        'availability' => 'enabled',
    ];
    $actions[] = [
        'id' => 'delete_event',
        'type' => 'delete_object',
        'label' => 'Delete',
        'operation_invocation' => ['service' => 'time.elonn', 'operation' => 'time.event.delete', 'object_id' => $id, 'payload' => []],
        'availability' => 'enabled',
    ];
    return $actions;
}

/**
 * @param array<int, array<string, mixed>> $objects
 * @return array<int, array<string, mixed>>
 */
function timeDatasetActions(array $objects): array
{
    $actions = [];
    foreach ($objects as $object) {
        foreach ((array) ($object['actions']['local'] ?? []) as $sourceAction) {
            if (!is_array($sourceAction) || trim((string) ($sourceAction['id'] ?? '')) === '') {
                continue;
            }
            $invocation = $sourceAction['operation_invocation'] ?? null;
            if (!is_array($invocation) || $invocation === []) {
                // An Action is invoked only through its operation_invocation
                // (dev.elonn canonical/action.md); one without is not emitted.
                continue;
            }
            $actions[] = [
                'id' => 'action:' . $object['id'] . ':' . $sourceAction['id'],
                'type' => (string) ($sourceAction['type'] ?? 'open_object'),
                'target' => (string) $object['id'],
                'content' => [
                    'label' => (string) ($sourceAction['label'] ?? 'Open'),
                    'operation_invocation' => $invocation,
                ],
                'availability' => ['state' => (string) ($sourceAction['availability'] ?? 'enabled')],
            ];
        }
    }

    return $actions;
}

/**
 * @param array<int, array<string, mixed>> $objects
 * @return array<int, array<string, mixed>>
 */
function timeDatasetResources(array $objects): array
{
    $resources = [];
    foreach ($objects as $object) {
        $href = trim((string) ($object['content']['source']['url'] ?? ''));
        if ($href === '') {
            continue;
        }
        $resources[] = [
            'id' => 'resource:' . $object['id'] . ':source',
            'type' => 'resource',
            'content' => [
                'kind' => 'link',
                'href' => $href,
                'label' => (string) ($object['summary'] ?? 'Source'),
                'media_type' => 'text/html',
            ],
        ];
    }

    return $resources;
}

function timeServiceDatasetError(string $code, string $class, string $message, int $status, string $caller = ''): void
{
    Response::json([
        'id' => 'dataset:service:time:' . bin2hex(random_bytes(16)),
        'type' => 'service',
        'scope' => 'error',
        'mode' => 'snapshot',
        'created' => gmdate('c'),
        'objects' => [],
        'actions' => [],
        'relationships' => [],
        'collections' => [],
        'resources' => [],
        'placements' => [],
        'errors' => [['code' => $code, 'class' => $class, 'message' => $message]],
        'context' => ['service' => 'time', 'caller' => $caller],
    ], $status);
}

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
            redirect(accountLoginUrl($apiBaseUrl));
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
            redirect(accountLoginUrl($apiBaseUrl));
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

/**
 * RFC 6764 service discovery: map /.well-known/caldav to the CalDAV context path
 * so clients given only the bare host can find the collection root.
 */
function wellKnownCalDavRedirect(string $path): ?string
{
    return '/' . trim($path, '/') === '/.well-known/caldav'
        ? '/dav/'
        : null;
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

    // OPTIONS is an unauthenticated capability probe (RFC 4918 10.1). Answer it
    // directly so clients see the DAV feature set; authenticated OPTIONS still
    // falls through to SabreDAV for the precise per-node Allow header.
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS' && $credentials === null) {
        http_response_code(200);
        header('Allow: OPTIONS, GET, HEAD, POST, PUT, DELETE, PROPFIND, PROPPATCH, REPORT, MKCOL, MKCALENDAR, MOVE, COPY');
        header('DAV: 1, 3, extended-mkcol, calendar-access');
        header('MS-Author-Via: DAV');
        header('Content-Length: 0');
        return;
    }

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
 * @param array<string, mixed> $calendar
 * @return array<string, string>
 */
function calendarFormOld(array $calendar): array
{
    return [
        'name' => (string) ($calendar['name'] ?? ''),
        'description' => (string) ($calendar['description'] ?? ''),
        'color' => (string) ($calendar['color'] ?? ''),
        'timezone' => (string) ($calendar['timezone'] ?? ''),
    ];
}

/**
 * @param array<string, mixed> $event
 * @return array<string, string>
 */
function eventFormOld(array $event): array
{
    return [
        'calendar_id' => (string) ($event['calendar_id'] ?? ''),
        'title' => (string) ($event['title'] ?? ''),
        // time_events (the legacy table this reads) stores starts_at/ends_at as plain local
        // wall-clock digits with no UTC conversion anywhere in its own pipeline (unlike the
        // canonical time_calendar_objects table CalendarStore reads) -- no zone conversion here.
        'starts_at' => htmlDateTimeLocal($event['starts_at'] ?? null),
        'ends_at' => htmlDateTimeLocal($event['ends_at'] ?? null),
        'location' => (string) ($event['location'] ?? ''),
        'description' => (string) ($event['description'] ?? ''),
        'timezone' => (string) ($event['timezone'] ?? ''),
        'status' => (string) ($event['status'] ?? 'active'),
        'all_day' => truthy($event['all_day'] ?? false) ? '1' : '',
    ];
}

/**
 * @param array<string, mixed> $task
 * @return array<string, string>
 */
function taskFormOld(array $task): array
{
    return [
        'calendar_id' => (string) ($task['calendar_id'] ?? ''),
        'title' => (string) ($task['title'] ?? ''),
        'due_at' => htmlDateTimeLocal($task['due_at'] ?? null, $task['timezone'] ?? null),
        'description' => (string) ($task['description'] ?? ''),
        'location' => (string) ($task['location'] ?? ''),
        'timezone' => (string) ($task['timezone'] ?? ''),
        'priority' => (string) ($task['priority'] ?? ''),
        'status' => (string) ($task['status'] ?? 'needs-action'),
        'all_day' => truthy($task['all_day'] ?? false) ? '1' : '',
    ];
}

/**
 * @param array<string, mixed> $input
 * @param array<string, mixed>|null $existing
 * @return array<string, mixed>
 */
function taskFieldsFromInput(array $input, ?array $existing = null): array
{
    $status = cleanString($input['status'] ?? null) ?? (string) ($existing['status'] ?? 'needs-action');
    $completedAt = $status === 'completed'
        ? (($existing['completed_at'] ?? null) ?: now())
        : null;

    return [
        'calendar_id' => positiveInt($input['calendar_id'] ?? null) ?? (int) ($existing['calendar_id'] ?? 0),
        'component_type' => 'VTODO',
        'title' => cleanString($input['title'] ?? null) ?? '',
        'description' => cleanOptionalString($input['description'] ?? null),
        'location' => cleanOptionalString($input['location'] ?? null),
        'due_at' => normalizeDateTime($input['due_at'] ?? null),
        'completed_at' => $completedAt,
        'timezone' => cleanOptionalString($input['timezone'] ?? null) ?? ($existing['timezone'] ?? null),
        'all_day' => truthy($input['all_day'] ?? false) ? 1 : 0,
        'status' => $status,
        'priority' => taskPriority($input['priority'] ?? null),
    ];
}

/**
 * @param array<string, mixed> $fields
 * @param array<int, array<string, mixed>> $calendars
 */
function taskValidationError(array $fields, array $calendars): ?string
{
    $calendarIds = array_map(static fn (array $calendar): int => (int) $calendar['id'], $calendars);
    if (!in_array((int) ($fields['calendar_id'] ?? 0), $calendarIds, true)) {
        return 'Valid calendar is required.';
    }

    if (trim((string) ($fields['title'] ?? '')) === '') {
        return 'Task title is required.';
    }

    if (!in_array((string) ($fields['status'] ?? ''), ['needs-action', 'in-process', 'completed', 'cancelled'], true)) {
        return 'Task status must be open, in progress, completed, or cancelled.';
    }

    return null;
}

/**
 * @param array<string, mixed> $appointment
 * @return array<string, string>
 */
function appointmentFormOld(array $appointment): array
{
    return [
        'calendar_id' => (string) ($appointment['calendar_id'] ?? ''),
        'title' => (string) ($appointment['title'] ?? ''),
        'starts_at' => htmlDateTimeLocal($appointment['starts_at'] ?? null, $appointment['timezone'] ?? null),
        'ends_at' => htmlDateTimeLocal($appointment['ends_at'] ?? null, $appointment['timezone'] ?? null),
        'location' => (string) ($appointment['location'] ?? ''),
        'description' => (string) ($appointment['description'] ?? ''),
        'timezone' => (string) ($appointment['timezone'] ?? ''),
        'status' => appointmentDisplayStatus($appointment['status'] ?? null),
        'all_day' => truthy($appointment['all_day'] ?? false) ? '1' : '',
    ];
}

/**
 * The stored status on a canonical calendar object round-trips through iCalendar's own
 * STATUS vocabulary (CONFIRMED/TENTATIVE/CANCELLED for VEVENT), not the app's own
 * active/cancelled wording — normalize back to active/cancelled so the edit form's
 * dropdown and revalidation agree with what CalendarObject::build() expects on save.
 */
function appointmentDisplayStatus(mixed $status): string
{
    return in_array(strtolower((string) $status), ['cancelled', 'canceled'], true) ? 'cancelled' : 'active';
}

/**
 * @param array<string, mixed> $input
 * @param array<string, mixed>|null $existing
 * @return array<string, mixed>
 */
function appointmentFieldsFromInput(array $input, ?array $existing = null): array
{
    return [
        'calendar_id' => positiveInt($input['calendar_id'] ?? null) ?? (int) ($existing['calendar_id'] ?? 0),
        'component_type' => 'VEVENT',
        'title' => cleanString($input['title'] ?? null) ?? '',
        'description' => cleanOptionalString($input['description'] ?? null),
        'location' => cleanOptionalString($input['location'] ?? null),
        'starts_at' => normalizeDateTime($input['starts_at'] ?? null),
        'ends_at' => normalizeDateTime($input['ends_at'] ?? null),
        // A blank submitted field preserves the object's existing zone rather than wiping it to
        // null, which would otherwise make CalendarObject::build() reinterpret the wall-clock
        // digits above with no zone at all (PHP's ambient default) on the very next save.
        'timezone' => cleanOptionalString($input['timezone'] ?? null) ?? ($existing['timezone'] ?? null),
        'all_day' => truthy($input['all_day'] ?? false) ? 1 : 0,
        'status' => cleanString($input['status'] ?? null) ?? appointmentDisplayStatus($existing['status'] ?? null),
    ];
}

/**
 * @param array<string, mixed> $fields
 * @param array<int, array<string, mixed>> $calendars
 */
function appointmentValidationError(array $fields, array $calendars): ?string
{
    $calendarIds = array_map(static fn (array $calendar): int => (int) $calendar['id'], $calendars);
    if (!in_array((int) ($fields['calendar_id'] ?? 0), $calendarIds, true)) {
        return 'Valid calendar is required.';
    }

    if (trim((string) ($fields['title'] ?? '')) === '') {
        return 'Event title is required.';
    }

    if ($fields['starts_at'] === null || $fields['ends_at'] === null) {
        return 'Start and end are required.';
    }

    if ($fields['ends_at'] <= $fields['starts_at']) {
        return 'Event end must be after start.';
    }

    if (!in_array((string) ($fields['status'] ?? ''), ['active', 'cancelled'], true)) {
        return 'Event status must be active or cancelled.';
    }

    return null;
}

function taskPriority(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_string($value) && ctype_digit($value)) {
        return max(0, min(9, (int) $value));
    }

    if (is_int($value)) {
        return max(0, min(9, $value));
    }

    return null;
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

function plannerView(mixed $value): string
{
    $view = is_string($value) ? strtolower(trim($value)) : '';
    return in_array($view, ['day', 'week', 'month', 'agenda', 'tasks'], true) ? $view : 'week';
}

function plannerAnchorDate(mixed $value): string
{
    if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
        try {
            return (new DateTimeImmutable($value))->format('Y-m-d');
        } catch (Throwable) {
        }
    }

    return (new DateTimeImmutable('today'))->format('Y-m-d');
}

function plannerTimezone(mixed $value): string
{
    if (is_string($value) && $value !== '') {
        try {
            return (new DateTimeZone($value))->getName();
        } catch (Throwable) {
        }
    }

    return date_default_timezone_get() ?: 'UTC';
}

function html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Prefills a <input type="datetime-local"> with a stored instant, converted into the zone the
 * edit form's own Timezone field shows -- never the ambient/UTC zone the value happens to
 * carry. CalendarStore::find()/payload() always return starts_at/ends_at/due_at as ISO 8601
 * with an explicit UTC offset, so reading the raw digits without converting first would show
 * the UTC wall clock mislabeled as local time; saving that unmodified value back would then
 * silently shift the real instant by the zone's offset on every open-and-resave. A bare
 * 'Y-m-d' (all-day, no time-of-day or zone of its own) is shown at local midnight verbatim.
 */
function htmlDateTimeLocal(mixed $value, mixed $timezone = null): string
{
    if (!is_string($value) || trim($value) === '') {
        return '';
    }
    if (!str_contains($value, 'T')) {
        return $value . 'T00:00';
    }

    try {
        $date = new DateTimeImmutable($value);
        $zoneName = is_string($timezone) ? validTimezone($timezone) : null;
        if ($zoneName !== null) {
            $date = $date->setTimezone(new DateTimeZone($zoneName));
        }
        return $date->format('Y-m-d\TH:i');
    } catch (Throwable) {
        return '';
    }
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

function redirectToHttps(string $canonicalUrl): void
{
    if (currentScheme() === 'https') {
        return;
    }

    $target = timeHttpsRedirectTarget($canonicalUrl);
    if ($target === null) {
        return;
    }

    http_response_code(308);
    header('Location: ' . $target);
    exit;
}

function timeHttpsRedirectTarget(string $canonicalUrl): ?string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = is_string($uri) && str_starts_with($uri, '/') ? $uri : '/';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $host = is_string($host) ? strtolower(explode(':', $host, 2)[0]) : '';

    if (in_array($host, ['time.elonn.local', 'time.elonn.com'], true)) {
        return 'https://' . $host . $path;
    }

    $canonical = rtrim($canonicalUrl, '/');
    return $canonical === '' ? null : $canonical . $path;
}

function accountLoginUrl(string $apiBaseUrl): string
{
    return rtrim($apiBaseUrl, '/') . '/identity/login?return_to=' . rawurlencode(currentUrl());
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
    $sql = "SELECT e.id, e.calendar_id, e.title, e.description, e.location, e.starts_at, e.ends_at, e.timezone, e.all_day, e.status,
                   e.source_service, e.source_object_type, e.source_object_id, e.source_url, e.created_at, e.updated_at,
                   c.name AS calendar_name, c.color AS calendar_color
            FROM time_events e
            LEFT JOIN time_calendars c ON c.id = e.calendar_id AND c.identity_user_id = e.identity_user_id
            WHERE e.identity_user_id = :identity_user_id
              AND e.status <> 'deleted'";
    $params = [':identity_user_id' => $identityUserId];

    if ($calendarId !== null) {
        $sql .= ' AND e.calendar_id = :calendar_id';
        $params[':calendar_id'] = $calendarId;
    }

    $sql .= ' ORDER BY e.starts_at, e.id';
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
        "SELECT id, uri, name, description, color, timezone, components, status, source_service, source_object_type, source_object_id, source_url, created_at, updated_at
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
 * @param array<int, array<string, mixed>> $events
 * @return array<int, array<string, mixed>>
 */
function upcomingEvents(array $events, int $limit): array
{
    $now = time();
    $upcoming = array_values(array_filter($events, static function (array $event) use ($now): bool {
        $timestamp = strtotime((string) ($event['starts_at'] ?? ''));
        return $timestamp !== false && $timestamp >= $now;
    }));

    return array_slice($upcoming, 0, $limit);
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
 * @param array{name:string,description:mixed,color:mixed,timezone:mixed,status:string} $fields
 */
function updateCalendar(PDO $pdo, string $identityUserId, int $calendarId, array $fields): void
{
    $stmt = $pdo->prepare(
        "UPDATE time_calendars
         SET name = :name, description = :description, color = :color, timezone = :timezone, status = :status, updated_at = :updated_at
         WHERE id = :id AND identity_user_id = :identity_user_id"
    );
    $stmt->execute([
        ':name' => $fields['name'],
        ':description' => $fields['description'],
        ':color' => $fields['color'],
        ':timezone' => $fields['timezone'],
        ':status' => $fields['status'],
        ':updated_at' => now(),
        ':id' => $calendarId,
        ':identity_user_id' => $identityUserId,
    ]);
}

function deleteCalendar(PDO $pdo, string $identityUserId, int $calendarId): void
{
    $stmt = $pdo->prepare(
        "UPDATE time_calendars
         SET status = 'deleted', updated_at = :updated_at
         WHERE id = :id AND identity_user_id = :identity_user_id"
    );
    $stmt->execute([
        ':updated_at' => now(),
        ':id' => $calendarId,
        ':identity_user_id' => $identityUserId,
    ]);
}

/**
 * @param array{calendar_id:int,title:string,description:mixed,location:mixed,starts_at:string,ends_at:string,timezone:mixed,all_day:int,status:string} $fields
 */
function updateEvent(PDO $pdo, string $identityUserId, int $eventId, array $fields): void
{
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
        ':calendar_id' => $fields['calendar_id'],
        ':title' => $fields['title'],
        ':description' => $fields['description'],
        ':location' => $fields['location'],
        ':starts_at' => $fields['starts_at'],
        ':ends_at' => $fields['ends_at'],
        ':timezone' => $fields['timezone'],
        ':all_day' => $fields['all_day'],
        ':status' => $fields['status'],
        ':updated_at' => now(),
        ':id' => $eventId,
        ':identity_user_id' => $identityUserId,
    ]);

    $updated = findEvent($pdo, $identityUserId, $eventId);
    if ($updated !== null) {
        syncLegacyEventObject($pdo, $updated);
    }
}

/**
 * @param array<string, mixed> $event
 */
function deleteEvent(PDO $pdo, string $identityUserId, int $eventId, array $event): void
{
    $stmt = $pdo->prepare(
        "UPDATE time_events
         SET status = 'deleted', updated_at = :updated_at
         WHERE id = :id AND identity_user_id = :identity_user_id"
    );
    $stmt->execute([
        ':updated_at' => now(),
        ':id' => $eventId,
        ':identity_user_id' => $identityUserId,
    ]);
    $event['status'] = 'deleted';
    syncLegacyEventObject($pdo, $event);
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
                 status, priority, recurrence_rule, alarm_trigger, attendees, first_occurrence, last_occurrence,
                 source_service, source_object_type, source_object_id, source_url, created_at)
             VALUES
                (:identity_user_id, :calendar_id, :uri, :uid, :component_type, :calendar_data, :etag, :size_bytes,
                 :title, :description, :location, :starts_at, :ends_at, :due_at, :completed_at, :timezone, :all_day,
                 :status, :priority, :recurrence_rule, :alarm_trigger, :attendees, :first_occurrence, :last_occurrence,
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

function timeMetricsAuthFailed(): void
{
    Response::json([
        'errors' => [[
            'code' => 'time.service_auth_failed',
            'class' => 'auth',
            'message' => 'Authenticated admin service request is required.',
        ]],
    ], 401);
}
