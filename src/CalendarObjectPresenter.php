<?php

declare(strict_types=1);

namespace Elonn\Time;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Builds the Contract-facing presentation of a calendar object or calendar row: the single-row
 * fetch behind time.open/create/update's response, the human-facing instant/attendee
 * formatting, and the real actions.local array (including the Edit action's initial payload,
 * dev.elonn canonical/action.md, Initial values). Kept separate from CalendarObjectInput
 * (which handles a Call's own content on the way *in*) so each half of the Contract boundary is
 * independently testable against real data, not a copy of either side's logic.
 */
final class CalendarObjectPresenter
{
    /**
     * The same raw row shape (including calendar_name, search_confidence) timeObjectActions()
     * and timeObjectFromRow() in public/index.php both expect.
     *
     * @return array<string, mixed>|null
     */
    public static function fetchRow(PDO $pdo, string $identityUserId, int $id): ?array
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
        $stmt->execute(['id' => $id, 'member_id' => $identityUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Formats a stored instant (always a UTC-naive 'Y-m-d H:i:s' string, per the storage
     * convention CalendarObject::parse() and CalendarStore::payload() both use) for the
     * Contract boundary. An all-day value carries no time-of-day or zone of its own, so it
     * becomes a bare 'Y-m-d' date; a timed value becomes an ISO 8601 datetime with an explicit
     * offset, expressed in the object's own declared timezone (falling back to UTC) so a
     * caller sees the same local time the event was created or last edited in.
     */
    public static function formatInstant(mixed $storedUtc, bool $allDay, mixed $timezone): ?string
    {
        if (!is_string($storedUtc) || trim($storedUtc) === '') {
            return null;
        }
        if ($allDay) {
            return substr($storedUtc, 0, 10);
        }
        $zoneName = is_string($timezone) ? self::validTimezone($timezone) : null;
        $date = new DateTimeImmutable($storedUtc, new DateTimeZone('UTC'));
        return $date->setTimezone(new DateTimeZone($zoneName ?? 'UTC'))->format(DATE_ATOM);
    }

    /**
     * Renders a stored attendees JSON payload back into the same comma-separated
     * "Name <email>" / bare-email string shape the Contract's `attendees` argument accepts, so
     * a generic Runtime form prefills with editable text, not raw JSON.
     */
    public static function formatAttendees(mixed $value): string
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
     * The real actions a Time calendar object (event or task) carries, including the Edit
     * action's initial payload -- a Runtime's generic form submits every rendered field,
     * including ones the member never touched, so an empty payload here would submit blanks
     * for them and silently clear fields on an unrelated edit (dev.elonn canonical/action.md,
     * Initial values).
     *
     * @param array<string, mixed> $row
     * @return array<int, array<string, mixed>>
     */
    public static function actionsForObject(string $id, string $componentType, array $row): array
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
                'operation_invocation' => ['service' => 'time.elonn', 'operation' => 'time.task.update', 'object_id' => $id, 'payload' => [
                    'title' => (string) $row['title'],
                    'description' => $row['description'] ?? null,
                    // formatInstant(), not the raw stored UTC-naive string: a Runtime that
                    // resubmits this value unchanged would otherwise have it reinterpreted as
                    // wall-clock-in-the-task's-own-zone by CalendarObjectInput::
                    // taskFieldsFromContent(), shifting the real instant by that zone's offset
                    // on every untouched save.
                    'due_at' => self::formatInstant($row['due_at'] ?? null, false, $row['timezone'] ?? null),
                    'starts_at' => self::formatInstant($row['starts_at'] ?? null, false, $row['timezone'] ?? null),
                    'priority' => $row['priority'] ?? null,
                    'recurrence_rule' => $row['recurrence_rule'] ?? null,
                    // The task's current calendar, by name: a Runtime resubmitting this
                    // unchanged preserves membership (time.task.update matches it back to the
                    // same calendar); submitting it blank also preserves membership (the
                    // handler treats blank the same as omitted), so only a deliberately
                    // different name actually moves it.
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
            'operation_invocation' => ['service' => 'time.elonn', 'operation' => 'time.event.update', 'object_id' => $id, 'payload' => [
                'title' => (string) $row['title'],
                'timezone' => $row['timezone'] ?? null,
                // formatInstant(), not the raw stored UTC-naive string -- see the matching
                // comment on time.task.update's payload just above.
                'starts_at' => self::formatInstant($row['occurrence_start'] ?? $row['starts_at'] ?? null, self::truthy($row['all_day'] ?? false), $row['timezone'] ?? null),
                'ends_at' => self::formatInstant($row['occurrence_end'] ?? $row['ends_at'] ?? null, self::truthy($row['all_day'] ?? false), $row['timezone'] ?? null),
                'all_day' => self::truthy($row['all_day'] ?? false),
                'location' => $row['location'] ?? null,
                'description' => $row['description'] ?? null,
                'recurrence_rule' => $row['recurrence_rule'] ?? null,
                // formatAttendees(), not the raw stored value: the argument is the plain
                // string the member edits (matching content.attendees, built the same way just
                // above), never the internal JSON encoding.
                'attendees' => self::formatAttendees($row['attendees'] ?? null),
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
     * The real actions every Time calendar carries: Edit is always offered (renaming,
     * recoloring, or re-timezoning a Social mirror calendar is still the member's own local
     * preference); Delete is withheld for a Social-owned mirror calendar, which
     * CalendarStore::deleteCalendar() refuses server-side -- withholding the action here just
     * keeps the Dashboard from offering one that would fail.
     *
     * @param array<string, mixed> $calendar
     * @return array<int, array<string, mixed>>
     */
    public static function actionsForCalendar(string $id, array $calendar): array
    {
        $actions = [[
            'id' => 'update_calendar',
            'type' => 'update_object',
            'label' => 'Edit',
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

    private static function validTimezone(string $value): ?string
    {
        $value = trim($value);
        return $value !== '' && in_array($value, DateTimeZone::listIdentifiers(), true) ? $value : null;
    }

    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }
}
