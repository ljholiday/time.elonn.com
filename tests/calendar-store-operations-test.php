<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Elonn\Time\CalendarStore;
use Elonn\Time\Database;

/*
 * Exercises the CalendarStore additions the Conductor operation path (time.event.*,
 * time.task.*) relies on: default-calendar creation, calendar resolution by name, and
 * task complete/reopen -- against a real local database, cleaned up afterward.
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';
Dotenv::createImmutable(BASE_PATH)->safeLoad();
$config = require BASE_PATH . '/config/config.php';

$pdo = Database::connect($config['database'])->pdo();
$identityUserId = 'store_ops_test_' . bin2hex(random_bytes(8));
$socialGuardIdentityUserId = 'store_ops_test_' . bin2hex(random_bytes(8));
$store = new CalendarStore($pdo);
$checks = [];

try {
    $calendarId = $store->resolveCalendarId($identityUserId, null);
    $checks['resolveCalendarId creates a default calendar when the member has none'] = $calendarId > 0
        && count($store->calendars($identityUserId)) === 1
        && $store->calendars($identityUserId)[0]['name'] === 'Calendar';

    $sameCalendarId = $store->resolveCalendarId($identityUserId, null);
    $checks['resolveCalendarId reuses the existing calendar rather than creating another'] = $sameCalendarId === $calendarId
        && count($store->calendars($identityUserId)) === 1;

    $byName = $store->resolveCalendarId($identityUserId, 'calendar');
    $checks['resolveCalendarId matches an existing calendar case-insensitively by name'] = $byName === $calendarId;

    $checks['findCalendarIdByName matches an existing calendar case-insensitively'] =
        $store->findCalendarIdByName($identityUserId, 'CALENDAR') === $calendarId;
    $checks['findCalendarIdByName returns null for an unknown name, never a substitute'] =
        $store->findCalendarIdByName($identityUserId, 'Nonexistent') === null;

    $task = $store->create($identityUserId, [
        'component_type' => 'VTODO',
        'title' => 'Call JJ',
        'calendar_id' => $calendarId,
        'due_at' => '2026-09-19 17:00:00',
    ]);
    $checks['task create stores due_at and defaults to not completed'] = $task['due_at'] !== null
        && $task['completed_at'] === null;

    $completed = $store->complete($identityUserId, (int) $task['id']);
    $checks['complete() sets status and completed_at'] = $completed['status'] === 'completed'
        && $completed['completed_at'] !== null
        && $completed['title'] === 'Call JJ';

    $reopened = $store->reopen($identityUserId, (int) $task['id']);
    $checks['reopen() clears completed_at'] = $reopened['status'] !== 'completed'
        && $reopened['completed_at'] === null;

    $event = $store->create($identityUserId, [
        'component_type' => 'VEVENT',
        'title' => 'Attendee round trip',
        'calendar_id' => $calendarId,
        'starts_at' => '2026-09-20 12:00:00',
        'ends_at' => '2026-09-20 13:00:00',
        'attendees' => 'Jane Smith <jane@example.com>, bob@example.com',
    ]);
    $checks['create() persists and returns attendees'] = $event['attendees'] === [
        ['name' => 'Jane Smith', 'email' => 'jane@example.com'],
        ['email' => 'bob@example.com'],
    ];

    $store->delete($identityUserId, (int) $task['id']);
    $store->delete($identityUserId, (int) $event['id']);
    $checks['delete() removes the objects'] = $store->find($identityUserId, (int) $task['id']) === null
        && $store->find($identityUserId, (int) $event['id']) === null;

    $checks['calendarTimezone() returns null until the calendar declares one'] = $store->calendarTimezone($identityUserId, $calendarId) === null;
    $store->updateCalendar($identityUserId, $calendarId, ['timezone' => 'America/New_York']);
    $checks['calendarTimezone() returns the calendar\'s own declared zone'] = $store->calendarTimezone($identityUserId, $calendarId) === 'America/New_York';

    // Date/timezone round-tripping (fix for the contract/storage/CalDAV date boundary): a timed
    // instant stored as the established 'naive UTC wall-clock' convention must come back from
    // find() with its real offset intact, and must stay byte-identical after an edit that
    // doesn't touch it -- the exact value CalendarStore::update()'s merge, and the duration-
    // preserving logic in public/index.php's time.event.update handler, both depend on.
    $dstEvent = $store->create($identityUserId, [
        'component_type' => 'VEVENT',
        'title' => 'Spans spring-forward',
        'calendar_id' => $calendarId,
        // CalendarObject::build() expects a bare wall-clock string paired with 'timezone',
        // matching timeEventFieldsFromContent()'s own output shape -- 09:00 local on both
        // sides of the spring-forward transition (EST before, EDT after).
        'starts_at' => '2026-03-07 09:00:00',
        'ends_at' => '2026-03-09 09:00:00',
        'timezone' => 'America/New_York',
    ]);
    // find()/create() report in UTC (no display timezone requested) -- 14:00/13:00 UTC is the
    // same instant as 09:00 America/New_York on both sides of the spring-forward transition.
    $checks['create() stores a DST-spanning instant as an explicit-offset ISO 8601 value, not a bare string'] =
        $dstEvent['starts_at'] === '2026-03-07T14:00:00+00:00'
        && $dstEvent['ends_at'] === '2026-03-09T13:00:00+00:00';

    $touchedOnlyTitle = $store->update($identityUserId, (int) $dstEvent['id'], ['title' => 'Renamed, same instant']);
    $checks['update() with an unrelated field leaves a DST-spanning instant byte-identical'] =
        $touchedOnlyTitle['starts_at'] === $dstEvent['starts_at']
        && $touchedOnlyTitle['ends_at'] === $dstEvent['ends_at'];
    $store->delete($identityUserId, (int) $dstEvent['id']);

    // All-day round-tripping (fix for payload()'s all-day timezone shift): an all-day value
    // carries no time-of-day or zone of its own, so viewing it through a non-UTC display
    // timezone must never roll the calendar date to the adjacent day.
    $allDayEvent = $store->create($identityUserId, [
        'component_type' => 'VEVENT',
        'title' => 'Conference',
        'calendar_id' => $calendarId,
        'starts_at' => '2026-03-08 00:00:00',
        'ends_at' => '2026-03-10 00:00:00',
        'all_day' => 1,
    ]);
    $checks['create() stores an all-day event\'s dates as bare calendar dates'] =
        $allDayEvent['starts_at'] === '2026-03-08' && $allDayEvent['ends_at'] === '2026-03-10';

    $workspace = $store->workspace($identityUserId, 'agenda', '2026-03-01', 'America/Los_Angeles');
    $viewedAllDay = null;
    foreach ($workspace['appointments'] as $appointment) {
        if ((int) $appointment['id'] === (int) $allDayEvent['id']) {
            $viewedAllDay = $appointment;
        }
    }
    $checks['workspace() in a non-UTC display timezone does not shift an all-day event\'s dates'] =
        $viewedAllDay !== null
        && $viewedAllDay['starts_at'] === '2026-03-08' && $viewedAllDay['ends_at'] === '2026-03-10';
    $store->delete($identityUserId, (int) $allDayEvent['id']);

    $renamed = $store->updateCalendar($identityUserId, $calendarId, ['name' => 'Work', 'color' => '#4285F4']);
    $checks['updateCalendar() changes only the given fields'] = $renamed['name'] === 'Work'
        && $renamed['color'] === '#4285F4';

    $untouched = $store->updateCalendar($identityUserId, $calendarId, ['description' => 'Day job']);
    $checks['updateCalendar() leaves fields not present in $fields unchanged'] = $untouched['name'] === 'Work'
        && $untouched['color'] === '#4285F4'
        && $untouched['description'] === 'Day job';

    try {
        $store->updateCalendar($identityUserId, $calendarId, ['name' => '']);
        $checks['updateCalendar() rejects an empty name'] = false;
    } catch (\InvalidArgumentException) {
        $checks['updateCalendar() rejects an empty name'] = true;
    }

    $store->deleteCalendar($identityUserId, $calendarId);
    $checks['deleteCalendar() removes an ordinary calendar'] = !in_array(
        $calendarId,
        array_map(static fn (array $c): int => $c['id'], $store->calendars($identityUserId)),
        true
    );

    $socialCalendarId = $store->resolveCalendarId($socialGuardIdentityUserId, null);
    $pdo->prepare('UPDATE time_calendars SET source_service = :source WHERE id = :id')
        ->execute(['source' => 'social', 'id' => $socialCalendarId]);
    try {
        $store->deleteCalendar($socialGuardIdentityUserId, $socialCalendarId);
        $checks['deleteCalendar() refuses a Social-owned mirror calendar'] = false;
    } catch (\DomainException) {
        $checks['deleteCalendar() refuses a Social-owned mirror calendar'] = true;
    }
} finally {
    foreach ([$identityUserId, $socialGuardIdentityUserId] as $cleanupIdentityUserId) {
        $pdo->prepare('DELETE FROM time_calendar_objects WHERE identity_user_id = :id')->execute(['id' => $cleanupIdentityUserId]);
        $pdo->prepare('DELETE FROM time_calendars WHERE identity_user_id = :id')->execute(['id' => $cleanupIdentityUserId]);
    }
}

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;
    $failed += $passed ? 0 : 1;
}

exit($failed === 0 && $checks !== [] ? 0 : 1);
