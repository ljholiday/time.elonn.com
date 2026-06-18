<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Elonn\Time\CalendarObject;
use Elonn\Time\Database;
use Elonn\Time\Dav\CalendarBackend;

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';
Dotenv::createImmutable(BASE_PATH)->safeLoad();
$config = require BASE_PATH . '/config/config.php';

$pdo = Database::connect($config['database'])->pdo();
$identityUserId = 'dav_test_' . bin2hex(random_bytes(8));
$backend = new CalendarBackend($pdo, $identityUserId);
$calendarId = 0;
$passed = false;

try {
    $calendarId = $backend->createCalendar('principals/' . $identityUserId, 'dav-test', [
        '{DAV:}displayname' => 'DAV test',
    ]);
    $appointment = CalendarObject::build([
        'component_type' => 'VEVENT',
        'title' => 'DAV appointment',
        'starts_at' => '2026-06-24 09:00:00',
        'ends_at' => '2026-06-24 10:00:00',
        'timezone' => 'America/Los_Angeles',
    ], 'dav-appointment@elonn');
    $createdEtag = $backend->createCalendarObject($calendarId, 'appointment.ics', $appointment);
    $object = $backend->getCalendarObject($calendarId, 'appointment.ics');
    $updated = CalendarObject::build([
        'component_type' => 'VEVENT',
        'title' => 'Updated DAV appointment',
        'starts_at' => '2026-06-24 09:00:00',
        'ends_at' => '2026-06-24 10:30:00',
        'timezone' => 'America/Los_Angeles',
    ], 'dav-appointment@elonn');
    $updatedEtag = $backend->updateCalendarObject($calendarId, 'appointment.ics', $updated);
    $changes = $backend->getChangesForCalendar($calendarId, 1, 1);
    $backend->deleteCalendarObject($calendarId, 'appointment.ics');
    $deletedChanges = $backend->getChangesForCalendar($calendarId, $changes['syncToken'], 1);

    $passed = is_array($object)
        && $createdEtag !== $updatedEtag
        && in_array('appointment.ics', $changes['added'], true)
        && in_array('appointment.ics', $changes['modified'], true)
        && in_array('appointment.ics', $deletedChanges['deleted'], true);
} catch (Throwable $error) {
    echo 'FAIL: DAV backend CRUD and sync (' . $error->getMessage() . ')' . PHP_EOL;
} finally {
    if ($calendarId > 0) {
        $pdo->prepare('DELETE FROM time_calendars WHERE id = :id')->execute(['id' => $calendarId]);
    }
}

if ($passed) {
    echo 'PASS: DAV backend CRUD and sync' . PHP_EOL;
}

exit($passed ? 0 : 1);
