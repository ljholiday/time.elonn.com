<?php

declare(strict_types=1);

// Regression coverage for the canonical content.body nodes (dev.elonn canonical/object.md,
// Content body) time.elonn.local now adds to events, tasks, and calendars -- presentable,
// human-readable information (dates/times, location, attendees, status, recurrence, calendar;
// for a calendar: components, timezone, source) alongside the structured Head fields that stay
// unchanged for operations. public/index.php executes side effects on require and cannot be
// required directly in a test -- matching this repo's existing convention (tests/time-contract-
// test.php reads it as a string; tests/action-initial-payload-test.php reproduces functions
// verbatim) -- so the functions under test are reproduced here verbatim.

require dirname(__DIR__) . '/vendor/autoload.php';

function timeFormatHumanInstant(string $iso, bool $dateOnly): string
{
    $date = new DateTimeImmutable($iso);
    return $dateOnly ? $date->format('l, F j, Y') : $date->format('l, F j, Y, g:i A');
}

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

/** Asserts every node matches object.md's Content body vocabulary (the 'node' discriminator,
 * not 'type'; a list item always wraps a nested content array, never a bare string). */
function conforms(array $nodes): bool
{
    foreach ($nodes as $node) {
        if (!isset($node['node']) || array_key_exists('type', $node)) {
            return false;
        }
        if ($node['node'] === 'section' || $node['node'] === 'group') {
            if (!is_array($node['content'] ?? null) || !conforms($node['content'])) {
                return false;
            }
        }
        if ($node['node'] === 'list') {
            if (!is_bool($node['ordered'] ?? null) || !is_array($node['items'] ?? null)) {
                return false;
            }
            foreach ($node['items'] as $item) {
                if (!is_array($item['content'] ?? null) || !conforms($item['content'])) {
                    return false;
                }
            }
        }
        if ($node['node'] === 'paragraph' && (!is_string($node['text'] ?? null) || trim($node['text']) === '')) {
            return false;
        }
    }
    return true;
}

function kindsOf(array $nodes): array
{
    return array_values(array_filter(array_map(static fn (array $n): ?string => $n['kind'] ?? null, $nodes)));
}

$checks = [];

$fullEvent = [
    'component_type' => 'VEVENT', 'all_day' => false, 'timezone' => 'America/New_York',
    'starts_at' => '2026-09-18T09:00:00-04:00', 'ends_at' => '2026-09-18T10:00:00-04:00',
    'location' => 'Zoom', 'attendees' => 'Jane Smith <jane@example.com>, bob@example.com',
    'status' => 'confirmed', 'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO', 'calendar' => 'Work',
];
$fullEventBody = timeCalendarObjectBodyNodes($fullEvent);
$checks['a fully-populated event\'s body is canonically conformant'] = conforms($fullEventBody);
$checks['a fully-populated event\'s body covers schedule, location, attendees, status, recurrence, and calendar, in that order'] =
    kindsOf($fullEventBody) === ['schedule', 'location', 'attendees', 'status', 'recurrence', 'calendar'];
$checks['a same-day timed event\'s schedule shows one date with a start-end time range'] = (static function (array $body): bool {
    foreach ($body as $node) {
        if (($node['kind'] ?? null) === 'schedule') {
            return $node['content'][0]['text'] === 'Friday, September 18, 2026, 9:00 AM - 10:00 AM (America/New_York)';
        }
    }
    return false;
})($fullEventBody);
$checks['attendees render as a real list of two entries, not a comma-joined paragraph'] = (static function (array $body): bool {
    foreach ($body as $node) {
        if (($node['kind'] ?? null) === 'attendees') {
            $list = $node['content'][0];
            return $list['node'] === 'list' && $list['ordered'] === false && count($list['items']) === 2
                && $list['items'][0]['content'][0]['text'] === 'Jane Smith <jane@example.com>'
                && $list['items'][1]['content'][0]['text'] === 'bob@example.com';
        }
    }
    return false;
})($fullEventBody);
$checks['recurrence renders as a natural-language sentence, not the raw RRULE'] = (static function (array $body): bool {
    foreach ($body as $node) {
        if (($node['kind'] ?? null) === 'recurrence') {
            return $node['content'][0]['text'] === 'Repeats weekly on Monday.';
        }
    }
    return false;
})($fullEventBody);

$minimalEvent = ['component_type' => 'VEVENT', 'all_day' => false, 'starts_at' => '2026-09-19T09:00:00+00:00', 'status' => 'confirmed', 'calendar' => 'Calendar'];
$checks['an event with no location/attendees/recurrence omits those sections rather than manufacturing them empty'] =
    kindsOf(timeCalendarObjectBodyNodes($minimalEvent)) === ['schedule', 'status', 'calendar'];

$allDaySameDay = ['component_type' => 'VEVENT', 'all_day' => true, 'starts_at' => '2026-10-05T00:00:00+00:00', 'ends_at' => '2026-10-05T00:00:00+00:00'];
$checks['a single-day all-day event shows one date with no clock time or timezone'] =
    timeEventScheduleText($allDaySameDay) === 'Monday, October 5, 2026 (all day)';
$allDaySpan = ['component_type' => 'VEVENT', 'all_day' => true, 'starts_at' => '2026-10-05T00:00:00+00:00', 'ends_at' => '2026-10-07T00:00:00+00:00'];
$checks['a multi-day all-day event shows both dates'] =
    timeEventScheduleText($allDaySpan) === 'Monday, October 5, 2026 - Wednesday, October 7, 2026 (all day)';

$taskWithBoth = ['component_type' => 'VTODO', 'timezone' => 'America/New_York', 'starts_at' => '2026-09-20T09:00:00-04:00', 'due_at' => '2026-09-25T17:00:00-04:00', 'status' => 'needs-action', 'calendar' => 'Calendar'];
$checks['a task with both starts_at and due_at labels each explicitly'] =
    timeTaskScheduleText($taskWithBoth) === 'Starts Sunday, September 20, 2026, 9:00 AM (America/New_York). Due Friday, September 25, 2026, 5:00 PM (America/New_York).';
$checks['a task with no starts_at or due_at has no schedule text at all'] = timeTaskScheduleText(['component_type' => 'VTODO']) === null;

$checks['status vocabulary covers the real VEVENT/VTODO STATUS values used'] =
    timeHumanStatus('confirmed') === 'Confirmed'
    && timeHumanStatus('cancelled') === 'Cancelled'
    && timeHumanStatus('needs-action') === 'Needs action'
    && timeHumanStatus('in-process') === 'In progress'
    && timeHumanStatus('completed') === 'Completed'
    && timeHumanStatus('') === '';
$checks['an unrecognized status still renders something readable rather than being dropped'] =
    timeHumanRecurrence('FREQ=UNKNOWN') === 'Repeats.'
    && timeHumanRecurrence('FREQ=MONTHLY;INTERVAL=2;COUNT=5') === 'Repeats every 2 months, 5 times.';

$calendarWithEverything = ['components' => 'VEVENT,VTODO', 'timezone' => 'America/Los_Angeles', 'source' => ['service' => 'social']];
$calendarBody = timeCalendarBodyNodes($calendarWithEverything);
$checks['a fully-populated calendar\'s body is canonically conformant and covers components, timezone, and source'] =
    conforms($calendarBody) && kindsOf($calendarBody) === ['components', 'timezone', 'source'];
$checks['a calendar mirrored from another service names it'] = (static function (array $body): bool {
    foreach ($body as $node) {
        if (($node['kind'] ?? null) === 'source') {
            return $node['content'][0]['text'] === 'Mirrored from Social.';
        }
    }
    return false;
})($calendarBody);

$plainCalendar = ['components' => 'VEVENT,VTODO', 'timezone' => null, 'source' => null];
$checks['a calendar with no declared timezone and no source omits those sections'] =
    kindsOf(timeCalendarBodyNodes($plainCalendar)) === ['components'];

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;
    if (!$passed) {
        $failed++;
    }
}

exit($failed === 0 && $checks !== [] ? 0 : 1);
