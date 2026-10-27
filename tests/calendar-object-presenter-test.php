<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Elonn\Time\CalendarObjectPresenter;
use Elonn\Time\CalendarStore;
use Elonn\Time\Database;

/*
 * Exercises CalendarObjectPresenter -- the real class public/index.php's timeObjectFromRow()/
 * timeCalendarObject() call to fetch a row and build its actions.local, including the Edit
 * action's initial payload -- directly against real rows fetched from a real local database,
 * in place of tests/action-initial-payload-test.php's verbatim reproduction. A reproduction
 * can pass while the real function it copies has drifted or broken; calling the real class
 * against a real fetched row cannot.
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';
Dotenv::createImmutable(BASE_PATH)->safeLoad();
$config = require BASE_PATH . '/config/config.php';

/** Finds a named action in an actions.local array built by CalendarObjectPresenter. */
function findAction(array $actions, string $id): ?array
{
    foreach ($actions as $action) {
        if (($action['id'] ?? null) === $id) {
            return $action;
        }
    }
    return null;
}

$pdo = Database::connect($config['database'])->pdo();
$identityUserId = 'presenter_test_' . bin2hex(random_bytes(8));
$store = new CalendarStore($pdo);
$checks = [];

try {
    $calendarId = $store->resolveCalendarId($identityUserId, null);
    $store->updateCalendar($identityUserId, $calendarId, ['name' => 'Work', 'description' => 'Day job', 'color' => '#4285F4', 'timezone' => 'America/New_York']);

    // --- Calendar actions, against a real row from CalendarStore::calendars(). ---
    $calendarRow = $store->calendars($identityUserId)[0];
    $calendarActions = CalendarObjectPresenter::actionsForCalendar('time.calendar:' . $calendarId, $calendarRow);
    $calendarEdit = findAction($calendarActions, 'update_calendar');
    $checks['calendar Edit action carries the calendar\'s real current name/description/color/timezone'] =
        $calendarEdit !== null
        && $calendarEdit['operation_invocation']['payload'] === ['name' => 'Work', 'description' => 'Day job', 'color' => '#4285F4', 'timezone' => 'America/New_York'];
    $checks['calendar Delete action is offered for an ordinary (deletable) calendar'] = findAction($calendarActions, 'delete_calendar') !== null;

    $socialCalendarRow = $calendarRow;
    $socialCalendarRow['deletable'] = false;
    $checks['calendar Delete action is withheld when the calendar is not deletable'] =
        findAction(CalendarObjectPresenter::actionsForCalendar('time.calendar:' . $calendarId, $socialCalendarRow), 'delete_calendar') === null;

    // --- Event actions, against a real row fetched the same way the route handlers do. ---
    $event = $store->create($identityUserId, [
        'component_type' => 'VEVENT', 'calendar_id' => $calendarId, 'title' => 'Launch review',
        'starts_at' => '2026-09-18 09:00:00', 'ends_at' => '2026-09-18 10:00:00', 'timezone' => 'America/New_York',
        'location' => 'Zoom', 'attendees' => 'Jane Smith <jane@example.com>, bob@example.com',
        'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO',
    ]);
    $eventRow = CalendarObjectPresenter::fetchRow($pdo, $identityUserId, (int) $event['id']);
    $checks['fetchRow() finds the just-created event'] = $eventRow !== null;

    $eventActions = CalendarObjectPresenter::actionsForObject((string) $event['id'], 'VEVENT', $eventRow);
    $eventEdit = findAction($eventActions, 'update_event');
    $eventEditPayload = $eventEdit['operation_invocation']['payload'] ?? [];
    $checks['event Edit payload carries the real current title/location/recurrence/calendar'] =
        $eventEditPayload['title'] === 'Launch review'
        && $eventEditPayload['location'] === 'Zoom'
        && $eventEditPayload['recurrence_rule'] === 'FREQ=WEEKLY;BYDAY=MO'
        && $eventEditPayload['calendar'] === 'Work';
    $checks['event Edit payload\'s starts_at/ends_at are ISO 8601 with an explicit offset, not the raw stored UTC string'] =
        $eventEditPayload['starts_at'] === '2026-09-18T09:00:00-04:00'
        && $eventEditPayload['ends_at'] === '2026-09-18T10:00:00-04:00';
    $checks['event Edit payload\'s attendees are the plain member-facing string, not the internal JSON encoding'] =
        $eventEditPayload['attendees'] === 'Jane Smith <jane@example.com>, bob@example.com';
    $checks['event actions include Open and Delete alongside Edit'] =
        findAction($eventActions, 'open_time_object') !== null && findAction($eventActions, 'delete_event') !== null;

    // --- All-day event: the Edit payload's dates are bare, not shifted by any zone. ---
    $allDayEvent = $store->create($identityUserId, [
        'component_type' => 'VEVENT', 'calendar_id' => $calendarId, 'title' => 'Offsite',
        'starts_at' => '2026-10-05 00:00:00', 'ends_at' => '2026-10-07 00:00:00', 'all_day' => 1,
    ]);
    $allDayRow = CalendarObjectPresenter::fetchRow($pdo, $identityUserId, (int) $allDayEvent['id']);
    $allDayEditPayload = findAction(CalendarObjectPresenter::actionsForObject((string) $allDayEvent['id'], 'VEVENT', $allDayRow), 'update_event')['operation_invocation']['payload'];
    $checks['an all-day event\'s Edit payload carries bare calendar dates'] =
        $allDayEditPayload['starts_at'] === '2026-10-05' && $allDayEditPayload['ends_at'] === '2026-10-07' && $allDayEditPayload['all_day'] === true;

    // --- Task actions: Edit payload, and Complete/Reopen depending on status. ---
    $task = $store->create($identityUserId, [
        'component_type' => 'VTODO', 'calendar_id' => $calendarId, 'title' => 'File report',
        'due_at' => '2026-09-25 17:00:00', 'timezone' => 'America/New_York', 'priority' => 3,
    ]);
    $taskRow = CalendarObjectPresenter::fetchRow($pdo, $identityUserId, (int) $task['id']);
    $taskActions = CalendarObjectPresenter::actionsForObject((string) $task['id'], 'VTODO', $taskRow);
    $taskEditPayload = findAction($taskActions, 'update_task')['operation_invocation']['payload'] ?? [];
    $checks['task Edit payload carries the real current title/priority/calendar, and due_at with an explicit offset'] =
        $taskEditPayload['title'] === 'File report' && $taskEditPayload['priority'] === 3 && $taskEditPayload['calendar'] === 'Work'
        && $taskEditPayload['due_at'] === '2026-09-25T17:00:00-04:00';
    $checks['an incomplete task offers Complete, not Reopen'] =
        findAction($taskActions, 'complete_task') !== null && findAction($taskActions, 'reopen_task') === null;

    $completedTask = $store->complete($identityUserId, (int) $task['id']);
    $completedTaskRow = CalendarObjectPresenter::fetchRow($pdo, $identityUserId, (int) $task['id']);
    $completedTaskActions = CalendarObjectPresenter::actionsForObject((string) $task['id'], 'VTODO', $completedTaskRow);
    $checks['a completed task offers Reopen, not Complete'] =
        findAction($completedTaskActions, 'reopen_task') !== null && findAction($completedTaskActions, 'complete_task') === null;

    // --- A Social-owned mirror only ever gets Open, never Edit/Delete. ---
    $pdo->prepare('UPDATE time_calendar_objects SET source_service = :s WHERE id = :id')->execute(['s' => 'social', 'id' => (int) $event['id']]);
    $socialRow = CalendarObjectPresenter::fetchRow($pdo, $identityUserId, (int) $event['id']);
    $socialActions = CalendarObjectPresenter::actionsForObject((string) $event['id'], 'VEVENT', $socialRow);
    $checks['a Social-owned mirror object offers only Open, never Edit or Delete'] =
        count($socialActions) === 1 && $socialActions[0]['id'] === 'open_time_object';
} finally {
    $pdo->prepare('DELETE FROM time_calendar_objects WHERE identity_user_id = :id')->execute(['id' => $identityUserId]);
    $pdo->prepare('DELETE FROM time_calendars WHERE identity_user_id = :id')->execute(['id' => $identityUserId]);
}

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;
    $failed += $passed ? 0 : 1;
}

exit($failed === 0 && $checks !== [] ? 0 : 1);
