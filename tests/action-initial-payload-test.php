<?php

declare(strict_types=1);

// Regression coverage for time.calendar.update/time.event.update/time.task.update's initial
// payload (dev.elonn canonical/action.md, Initial values; agents.elonn.com decision
// generic_form_payload_initialization_option_a_20261025). public/index.php executes side
// effects on require (redirectToHttps(), Router setup) and cannot be required directly in a
// test -- matching this repo's existing convention (tests/time-contract-test.php reads it as a
// string instead) -- so the small action-building functions this pins are reproduced here
// verbatim, the same documented convention api.elonn.local/tests/identity-profile-test.php uses
// for public/index.php logic it cannot call directly either.
//
// Also pins the date/timezone round-tripping fix: the Edit action's starts_at/ends_at/due_at
// must be the same ISO 8601-with-offset (or bare all-day date) shape timeEventFieldsFromContent()/
// timeTaskFieldsFromContent() expect back, never the raw stored UTC-naive DB string -- a Runtime
// that resubmits an untouched value verbatim would otherwise have it reinterpreted in the
// object's own zone, shifting the real instant by that zone's offset on every unrelated edit.

require dirname(__DIR__) . '/vendor/autoload.php';

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
        $parts[] = $email;
    }
    return implode(', ', $parts);
}

/** Reproduces timeFormatContractInstant() (public/index.php) verbatim. */
function timeFormatContractInstant(mixed $storedUtc, bool $allDay, mixed $timezone): ?string
{
    if (!is_string($storedUtc) || trim($storedUtc) === '') {
        return null;
    }
    if ($allDay) {
        return substr($storedUtc, 0, 10);
    }
    $zoneName = is_string($timezone) && in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : null;
    $date = new DateTimeImmutable($storedUtc, new DateTimeZone('UTC'));
    return $date->setTimezone(new DateTimeZone($zoneName ?? 'UTC'))->format(DATE_ATOM);
}

/** Reproduces timeCalendarObjectActions()'s own Edit action (public/index.php). */
function reproducedCalendarEditPayload(array $calendar): array
{
    return [
        'name' => (string) $calendar['name'],
        'description' => $calendar['description'],
        'color' => $calendar['color'],
        'timezone' => $calendar['timezone'],
    ];
}

/** Reproduces timeObjectActions()'s own task Edit action (public/index.php). */
function reproducedTaskEditPayload(array $row): array
{
    return [
        'title' => (string) $row['title'],
        'description' => $row['description'] ?? null,
        'due_at' => timeFormatContractInstant($row['due_at'] ?? null, false, $row['timezone'] ?? null),
        'starts_at' => timeFormatContractInstant($row['starts_at'] ?? null, false, $row['timezone'] ?? null),
        'priority' => $row['priority'] ?? null,
        'recurrence_rule' => $row['recurrence_rule'] ?? null,
    ];
}

/** Reproduces timeObjectActions()'s own event Edit action (public/index.php). */
function reproducedEventEditPayload(array $row): array
{
    $allDay = (bool) ($row['all_day'] ?? false);
    return [
        'title' => (string) $row['title'],
        'timezone' => $row['timezone'] ?? null,
        'starts_at' => timeFormatContractInstant($row['occurrence_start'] ?? $row['starts_at'] ?? null, $allDay, $row['timezone'] ?? null),
        'ends_at' => timeFormatContractInstant($row['occurrence_end'] ?? $row['ends_at'] ?? null, $allDay, $row['timezone'] ?? null),
        'all_day' => $allDay,
        'location' => $row['location'] ?? null,
        'description' => $row['description'] ?? null,
        'recurrence_rule' => $row['recurrence_rule'] ?? null,
        'attendees' => timeFormatAttendees($row['attendees'] ?? null),
    ];
}

$calendarPayload = reproducedCalendarEditPayload([
    'name' => 'Work', 'description' => null, 'color' => '#3366ff', 'timezone' => 'America/Los_Angeles',
]);

$taskPayload = reproducedTaskEditPayload([
    'title' => 'Ship the thing', 'description' => 'Before Friday', 'due_at' => '2026-10-31 00:00:00',
    'timezone' => 'America/Los_Angeles',
    'starts_at' => null, 'priority' => 3, 'recurrence_rule' => null,
]);

$eventPayload = reproducedEventEditPayload([
    'title' => 'Weekly planning', 'timezone' => 'America/Los_Angeles',
    'starts_at' => '2026-06-22 16:00:00', 'ends_at' => '2026-06-22 17:00:00',
    'occurrence_start' => null, 'occurrence_end' => null,
    'all_day' => 0, 'location' => 'Zoom', 'description' => null,
    'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO',
    'attendees' => json_encode([['email' => 'a@example.com'], ['email' => 'b@example.com']]),
]);

$allDayEventPayload = reproducedEventEditPayload([
    'title' => 'Conference', 'timezone' => 'America/Los_Angeles',
    'starts_at' => '2026-03-08 00:00:00', 'ends_at' => '2026-03-10 00:00:00',
    'occurrence_start' => null, 'occurrence_end' => null,
    'all_day' => 1, 'location' => null, 'description' => null,
    'recurrence_rule' => null, 'attendees' => null,
]);

$checks = [
    'time.calendar.update carries the calendar\'s real current name/color/timezone, description null stays null' =>
        $calendarPayload['name'] === 'Work' && $calendarPayload['color'] === '#3366ff'
        && $calendarPayload['timezone'] === 'America/Los_Angeles' && $calendarPayload['description'] === null,
    'time.task.update carries the task\'s real current title/description/priority, and due_at as a local ISO 8601 instant with an explicit offset, not the raw stored UTC string' =>
        $taskPayload['title'] === 'Ship the thing' && $taskPayload['description'] === 'Before Friday'
        && $taskPayload['due_at'] === '2026-10-30T17:00:00-07:00' && $taskPayload['priority'] === 3
        && $taskPayload['starts_at'] === null,
    'time.event.update carries the event\'s real current fields, falling back to starts_at/ends_at when no occurrence override exists, as local ISO 8601 instants with an explicit offset' =>
        $eventPayload['title'] === 'Weekly planning' && $eventPayload['starts_at'] === '2026-06-22T09:00:00-07:00'
        && $eventPayload['ends_at'] === '2026-06-22T10:00:00-07:00' && $eventPayload['location'] === 'Zoom'
        && $eventPayload['recurrence_rule'] === 'FREQ=WEEKLY;BYDAY=MO',
    'time.event.update\'s attendees payload is the plain member-facing string, not the internal JSON encoding' =>
        $eventPayload['attendees'] === 'a@example.com, b@example.com',
    'an all-day event\'s Edit payload carries bare calendar dates, never shifted by the viewer\'s or event\'s own timezone' =>
        $allDayEventPayload['starts_at'] === '2026-03-08' && $allDayEventPayload['ends_at'] === '2026-03-10'
        && $allDayEventPayload['all_day'] === true,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ": {$name}\n";
    if (!$passed) {
        $failed[] = $name;
    }
}

if ($failed !== []) {
    exit(1);
}
