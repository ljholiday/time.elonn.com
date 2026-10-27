<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Elonn\Time\CalendarObjectInput;
use Elonn\Time\CalendarStore;
use Elonn\Time\Database;

/*
 * Exercises CalendarObjectInput -- the real class public/index.php's time.event.* and
 * time.task.* route handlers call -- directly, against a real local database, in place of the copies of
 * this logic tests/action-initial-payload-test.php and tests/event-interval-validation-test.php
 * used to reproduce verbatim. A reproduction can pass while the real function it copies has
 * drifted or broken; calling the real class through the same CalendarStore the route handlers
 * use cannot.
 *
 * Covers: an edit that touches nothing leaves everything (instant, zone, calendar membership)
 * untouched; an edit that explicitly blanks a field clears it; a named calendar move actually
 * moves the object and an unknown name is rejected; an offset-less edit is interpreted in the
 * object's own declared zone, not UTC or an ambient default; and an inverted interval is
 * rejected on create and on every shape of partial update, while the documented missing-end
 * defaults are preserved.
 *
 * CalendarStore::create()/update()/find() always report starts_at/ends_at/due_at in UTC (no
 * display timezone requested), so instants below are compared by their real moment
 * (DateTimeImmutable::getTimestamp()), not by a specific expected offset string -- a correct
 * fix should not require guessing which of several equally-valid ISO 8601 renderings of the
 * same instant CalendarStore happens to return.
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';
Dotenv::createImmutable(BASE_PATH)->safeLoad();
$config = require BASE_PATH . '/config/config.php';

function instant(string $iso): int
{
    return (new DateTimeImmutable($iso))->getTimestamp();
}

$pdo = Database::connect($config['database'])->pdo();
$identityUserId = 'input_test_' . bin2hex(random_bytes(8));
$store = new CalendarStore($pdo);
$checks = [];

try {
    // --- Create: calendar resolved and its timezone applied as the default. ---
    $workCalendarId = $store->resolveCalendarId($identityUserId, null);
    $store->updateCalendar($identityUserId, $workCalendarId, ['name' => 'Work', 'timezone' => 'America/New_York']);
    $homeCalendarStmt = $pdo->prepare(
        "INSERT INTO time_calendars (identity_user_id, uri, name, components, sync_token, status, created_at)
         VALUES (:identity_user_id, 'home', 'Home', 'VEVENT,VTODO', 1, 'active', :created_at)"
    );
    $homeCalendarStmt->execute(['identity_user_id' => $identityUserId, 'created_at' => gmdate('Y-m-d H:i:s')]);

    [$createFields, $createError] = CalendarObjectInput::resolveEventCreateFields($store, $identityUserId, [
        'title' => 'Launch review',
        'starts_at' => '2026-09-18T09:00:00',
        'location' => 'Zoom',
        'attendees' => 'Jane Smith <jane@example.com>',
        // Named explicitly: with both Work and Home now existing, an unnamed create would
        // resolve to whichever sorts first alphabetically (resolveCalendarId()'s own documented
        // fallback), not necessarily Work -- this test wants Work specifically.
        'calendar' => 'Work',
    ]);
    $checks['create: no error, and the calendar\'s own timezone is applied as the default for an offset-less value'] =
        $createError === null && $createFields['timezone'] === 'America/New_York';
    $event = $store->create($identityUserId, $createFields);
    $checks['create: the event lands in the resolved default calendar'] = (int) $event['calendar_id'] === $workCalendarId;
    // 09:00 with no offset, defaulted to America/New_York (EDT, UTC-4) == 13:00 UTC.
    $checks['create: an offset-less starts_at is interpreted in the calendar\'s own zone'] =
        instant($event['starts_at']) === instant('2026-09-18T13:00:00+00:00');

    // --- Untouched edit: nothing submitted but an unrelated field changes nothing else. ---
    [$untouchedFields, $untouchedError] = CalendarObjectInput::resolveEventUpdateFields($store, $identityUserId, ['title' => 'Launch review (renamed)'], $event);
    $untouched = $untouchedError === null ? $store->update($identityUserId, (int) $event['id'], $untouchedFields) : null;
    $checks['untouched edit: starts_at/ends_at, location, attendees, timezone, and calendar membership all survive byte-identical'] =
        $untouchedError === null
        && $untouched['starts_at'] === $event['starts_at']
        && $untouched['ends_at'] === $event['ends_at']
        && $untouched['location'] === $event['location']
        && $untouched['attendees'] === $event['attendees']
        && $untouched['timezone'] === $event['timezone']
        && (int) $untouched['calendar_id'] === $workCalendarId;

    // --- Timezone preservation: editing starts_at alone (no timezone submitted) stays in the
    // event's own declared zone, not UTC -- the exact regression the date/timezone fix closed. ---
    [$movedStartFields, $movedStartError] = CalendarObjectInput::resolveEventUpdateFields($store, $identityUserId, ['starts_at' => '2026-09-18T11:00:00'], $untouched);
    $movedStart = $movedStartError === null ? $store->update($identityUserId, (int) $event['id'], $movedStartFields) : null;
    $checks['timezone preservation: moving starts_at with no explicit timezone keeps the event\'s own declared zone'] =
        $movedStartError === null
        && $movedStart['timezone'] === 'America/New_York'
        && instant($movedStart['starts_at']) === instant('2026-09-18T11:00:00-04:00');

    // --- Intentional clear: explicitly blanking location removes it, not preserves it. ---
    [$clearedFields, $clearedError] = CalendarObjectInput::resolveEventUpdateFields($store, $identityUserId, ['location' => ''], $movedStart);
    $cleared = $clearedError === null ? $store->update($identityUserId, (int) $event['id'], $clearedFields) : null;
    $checks['intentional clear: explicitly blanking location actually clears it'] =
        $clearedError === null && $cleared['location'] === null;
    $checks['intentional clear: clearing one field leaves an untouched field (attendees) alone'] =
        $cleared['attendees'] === $event['attendees'];

    // --- Calendar move: a named, existing calendar actually moves the object. ---
    [$movedCalendarFields, $movedCalendarError] = CalendarObjectInput::resolveEventUpdateFields($store, $identityUserId, ['calendar' => 'Home'], $cleared);
    $movedCalendar = $movedCalendarError === null ? $store->update($identityUserId, (int) $event['id'], $movedCalendarFields) : null;
    $checks['calendar move: naming a different existing calendar actually moves the event'] =
        $movedCalendarError === null && $movedCalendar['calendar_name'] === 'Home';

    // --- Calendar move: an unknown name is rejected, not silently substituted. ---
    [, $unknownCalendarError] = CalendarObjectInput::resolveEventUpdateFields($store, $identityUserId, ['calendar' => 'Nonexistent'], $movedCalendar);
    $checks['calendar move: an unknown calendar name is rejected with an error'] = $unknownCalendarError !== null;
    $stillOnHome = $store->find($identityUserId, (int) $event['id']);
    $checks['calendar move: a rejected unknown-name edit leaves membership on the last real calendar'] =
        $stillOnHome['calendar_name'] === 'Home';

    // --- Blank/omitted calendar both preserve membership (not just omission). ---
    [$blankCalendarFields, $blankCalendarError] = CalendarObjectInput::resolveEventUpdateFields($store, $identityUserId, ['calendar' => ''], $stillOnHome);
    $afterBlankCalendar = $blankCalendarError === null ? $store->update($identityUserId, (int) $event['id'], $blankCalendarFields) : null;
    $checks['calendar move: a blank calendar field preserves membership rather than reassigning it'] =
        $blankCalendarError === null && $afterBlankCalendar['calendar_name'] === 'Home';

    // --- Invalid intervals: create. ---
    [, $invertedCreateError] = CalendarObjectInput::resolveEventCreateFields($store, $identityUserId, [
        'title' => 'Backwards', 'starts_at' => '2026-10-01T10:00:00-04:00', 'ends_at' => '2026-10-01T09:00:00-04:00',
    ]);
    $checks['invalid interval: create with an end before the start is rejected'] = $invertedCreateError !== null;

    [, $missingEndError] = CalendarObjectInput::resolveEventCreateFields($store, $identityUserId, [
        'title' => 'No end given', 'starts_at' => '2026-10-01T09:00:00-04:00',
    ]);
    $checks['invalid interval: create with a missing end still passes (the documented +1-hour default)'] = $missingEndError === null;

    // --- Invalid intervals: update, including every shape of partial update. ---
    $baseline = $store->create($identityUserId, [
        'component_type' => 'VEVENT', 'calendar_id' => $workCalendarId, 'title' => 'Baseline',
        'starts_at' => '2026-10-05 13:00:00', 'ends_at' => '2026-10-05 14:00:00', 'timezone' => 'America/New_York',
    ]);
    [, $onlyEndInvertedError] = CalendarObjectInput::resolveEventUpdateFields($store, $identityUserId, ['ends_at' => '2026-10-05T08:00:00-04:00'], $baseline);
    $checks['invalid interval: a partial update moving only ends_at before the existing starts_at is rejected'] = $onlyEndInvertedError !== null;

    [$validOnlyEndFields, $validOnlyEndError] = CalendarObjectInput::resolveEventUpdateFields($store, $identityUserId, ['ends_at' => '2026-10-05T16:00:00-04:00'], $baseline);
    $validOnlyEnd = $validOnlyEndError === null ? $store->update($identityUserId, (int) $baseline['id'], $validOnlyEndFields) : null;
    $checks['invalid interval: a partial update moving only ends_at to something still valid succeeds'] =
        $validOnlyEndError === null && instant($validOnlyEnd['ends_at']) === instant('2026-10-05T16:00:00-04:00');

    [$movedStartOnlyFields, $movedStartOnlyError] = CalendarObjectInput::resolveEventUpdateFields($store, $identityUserId, ['starts_at' => '2026-10-05T20:00:00-04:00'], $validOnlyEnd);
    $movedStartOnly = $movedStartOnlyError === null ? $store->update($identityUserId, (int) $baseline['id'], $movedStartOnlyFields) : null;
    // $validOnlyEnd's duration is 13:00-16:00 EDT (3 hours); moving starts_at alone to 20:00 EDT
    // with no ends_at given recomputes a new end 3 hours later (23:00 EDT), never compared
    // against the old end at all.
    $checks['invalid interval: moving only starts_at past the old end recomputes a new, later end via duration-preservation instead of being rejected'] =
        $movedStartOnlyError === null && instant($movedStartOnly['ends_at']) === instant('2026-10-05T23:00:00-04:00');

    [, $bothInvertedError] = CalendarObjectInput::resolveEventUpdateFields($store, $identityUserId, [
        'starts_at' => '2026-10-06T10:00:00-04:00', 'ends_at' => '2026-10-06T09:00:00-04:00',
    ], $movedStartOnly);
    $checks['invalid interval: both fields submitted explicitly with end before start is rejected'] = $bothInvertedError !== null;

    [$untouchedIntervalFields, $untouchedIntervalError] = CalendarObjectInput::resolveEventUpdateFields($store, $identityUserId, ['description' => 'still fine'], $movedStartOnly);
    $checks['invalid interval: an edit touching neither starts_at nor ends_at is never rejected'] = $untouchedIntervalError === null;

    // --- Task equivalents: calendar move, timezone preservation, intentional clear. ---
    [$taskCreateFields, $taskCreateError] = CalendarObjectInput::resolveTaskCreateFields($store, $identityUserId, [
        'title' => 'File report', 'due_at' => '2026-09-25T17:00:00', 'description' => 'Quarterly', 'calendar' => 'Work',
    ]);
    $checks['task create: no error'] = $taskCreateError === null;
    $task = $store->create($identityUserId, $taskCreateFields);
    $checks['task create: an offset-less due_at is interpreted in the calendar\'s own zone'] =
        instant($task['due_at']) === instant('2026-09-25T17:00:00-04:00');

    [$taskUntouchedFields, $taskUntouchedError] = CalendarObjectInput::resolveTaskUpdateFields($store, $identityUserId, ['priority' => 2], $task);
    $taskUntouched = $taskUntouchedError === null ? $store->update($identityUserId, (int) $task['id'], $taskUntouchedFields) : null;
    $checks['task untouched edit: due_at and description survive byte-identical'] =
        $taskUntouchedError === null && $taskUntouched['due_at'] === $task['due_at'] && $taskUntouched['description'] === $task['description'];

    [$taskClearFields, $taskClearError] = CalendarObjectInput::resolveTaskUpdateFields($store, $identityUserId, ['description' => ''], $taskUntouched);
    $taskCleared = $taskClearError === null ? $store->update($identityUserId, (int) $task['id'], $taskClearFields) : null;
    $checks['task intentional clear: explicitly blanking description actually clears it'] =
        $taskClearError === null && $taskCleared['description'] === null;

    [$taskMoveFields, $taskMoveError] = CalendarObjectInput::resolveTaskUpdateFields($store, $identityUserId, ['calendar' => 'Home'], $taskCleared);
    $taskMoved = $taskMoveError === null ? $store->update($identityUserId, (int) $task['id'], $taskMoveFields) : null;
    $checks['task calendar move: naming a different existing calendar moves it'] =
        $taskMoveError === null && $taskMoved['calendar_name'] === 'Home';

    [, $taskUnknownCalendarError] = CalendarObjectInput::resolveTaskUpdateFields($store, $identityUserId, ['calendar' => 'Nonexistent'], $taskMoved);
    $checks['task calendar move: an unknown calendar name is rejected'] = $taskUnknownCalendarError !== null;
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
