<?php

declare(strict_types=1);

use Elonn\Time\CalendarObject;

require dirname(__DIR__) . '/vendor/autoload.php';

$checks = [];

$appointment = CalendarObject::build([
    'component_type' => 'VEVENT',
    'title' => 'Weekly planning',
    'starts_at' => '2026-06-22 09:00:00 America/Los_Angeles',
    'ends_at' => '2026-06-22 10:00:00 America/Los_Angeles',
    'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO',
    'alarm_trigger' => '-PT15M',
    'status' => 'active',
]);
$parsedAppointment = CalendarObject::parse($appointment);
$checks['VEVENT round trip'] = $parsedAppointment['component_type'] === 'VEVENT'
    && $parsedAppointment['title'] === 'Weekly planning'
    && $parsedAppointment['recurrence_rule'] === 'FREQ=WEEKLY;BYDAY=MO'
    && $parsedAppointment['alarm_trigger'] === '-PT15M';

$task = CalendarObject::build([
    'component_type' => 'VTODO',
    'title' => 'File quarterly taxes',
    'due_at' => '2026-07-15 17:00:00 America/Los_Angeles',
    'priority' => 2,
    'status' => 'needs-action',
]);
$parsedTask = CalendarObject::parse($task);
$checks['VTODO round trip'] = $parsedTask['component_type'] === 'VTODO'
    && $parsedTask['title'] === 'File quarterly taxes'
    && $parsedTask['due_at'] !== null
    && $parsedTask['priority'] === 2;

$timezoneAppointment = CalendarObject::build([
    'component_type' => 'VEVENT',
    'title' => 'Timezone check',
    'starts_at' => '2026-06-22 09:00:00',
    'ends_at' => '2026-06-22 10:00:00',
    'timezone' => 'America/Los_Angeles',
    'all_day' => false,
]);
$parsedTimezoneAppointment = CalendarObject::parse($timezoneAppointment);
$checks['Timezone round trip'] = $parsedTimezoneAppointment['timezone'] === 'America/Los_Angeles'
    && $parsedTimezoneAppointment['starts_at'] === '2026-06-22 16:00:00';

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;
    if (!$passed) {
        $failed++;
    }
}

exit($failed === 0 ? 0 : 1);
