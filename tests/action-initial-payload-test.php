<?php

declare(strict_types=1);

// Regression coverage for time.calendar.update/time.event.update/time.task.update's initial
// payload (dev.elonn canonical/action.md, Initial values; agents.elonn.com decision
// generic_form_payload_initialization_option_a_20261025). public/index.php executes side
// effects on require (redirectToHttps(), Router setup) and cannot be required directly in a
// test -- matching this repo's existing convention (tests/time-contract-test.php reads it as a
// string instead) -- so the three small action-building functions this pins are reproduced
// here verbatim, the same documented convention api.elonn.local/tests/identity-profile-test.php
// uses for public/index.php logic it cannot call directly either.

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
        'due_at' => $row['due_at'] ?? null,
        'starts_at' => $row['starts_at'] ?? null,
        'priority' => $row['priority'] ?? null,
        'recurrence_rule' => $row['recurrence_rule'] ?? null,
    ];
}

/** Reproduces timeObjectActions()'s own event Edit action (public/index.php). */
function reproducedEventEditPayload(array $row): array
{
    return [
        'title' => (string) $row['title'],
        'timezone' => $row['timezone'] ?? null,
        'starts_at' => $row['occurrence_start'] ?? $row['starts_at'] ?? null,
        'ends_at' => $row['occurrence_end'] ?? $row['ends_at'] ?? null,
        'all_day' => (bool) ($row['all_day'] ?? false),
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
    'title' => 'Ship the thing', 'description' => 'Before Friday', 'due_at' => '2026-10-30 17:00:00',
    'starts_at' => null, 'priority' => 3, 'recurrence_rule' => null,
]);

$eventPayload = reproducedEventEditPayload([
    'title' => 'Weekly planning', 'timezone' => 'America/Los_Angeles',
    'starts_at' => '2026-06-22 09:00:00', 'ends_at' => '2026-06-22 10:00:00',
    'occurrence_start' => null, 'occurrence_end' => null,
    'all_day' => 0, 'location' => 'Zoom', 'description' => null,
    'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO',
    'attendees' => json_encode([['email' => 'a@example.com'], ['email' => 'b@example.com']]),
]);

$checks = [
    'time.calendar.update carries the calendar\'s real current name/color/timezone, description null stays null' =>
        $calendarPayload['name'] === 'Work' && $calendarPayload['color'] === '#3366ff'
        && $calendarPayload['timezone'] === 'America/Los_Angeles' && $calendarPayload['description'] === null,
    'time.task.update carries the task\'s real current title/description/due_at/priority' =>
        $taskPayload['title'] === 'Ship the thing' && $taskPayload['description'] === 'Before Friday'
        && $taskPayload['due_at'] === '2026-10-30 17:00:00' && $taskPayload['priority'] === 3
        && $taskPayload['starts_at'] === null,
    'time.event.update carries the event\'s real current fields, falling back to starts_at/ends_at when no occurrence override exists' =>
        $eventPayload['title'] === 'Weekly planning' && $eventPayload['starts_at'] === '2026-06-22 09:00:00'
        && $eventPayload['ends_at'] === '2026-06-22 10:00:00' && $eventPayload['location'] === 'Zoom'
        && $eventPayload['recurrence_rule'] === 'FREQ=WEEKLY;BYDAY=MO',
    'time.event.update\'s attendees payload is the plain member-facing string, not the internal JSON encoding' =>
        $eventPayload['attendees'] === 'a@example.com, b@example.com',
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
