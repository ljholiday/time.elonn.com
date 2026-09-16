<?php

declare(strict_types=1);

namespace Elonn\Time;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/**
 * Parses and builds canonical VEVENT/VTODO objects for Time and CalDAV.
 */
final class CalendarObject
{
    /**
     * @return array<string, mixed>
     */
    public static function parse(string $calendarData): array
    {
        $calendar = Reader::read($calendarData);
        if (!$calendar instanceof VCalendar) {
            throw new InvalidArgumentException('A VCALENDAR object is required.');
        }

        $component = $calendar->VEVENT ?? $calendar->VTODO ?? null;
        if ($component === null) {
            throw new InvalidArgumentException('A VEVENT or VTODO component is required.');
        }

        $type = strtoupper($component->name);
        $uid = trim((string) ($component->UID ?? ''));
        $title = trim((string) ($component->SUMMARY ?? ''));
        if ($uid === '' || $title === '') {
            throw new InvalidArgumentException('UID and SUMMARY are required.');
        }

        $start = self::dateProperty($component->DTSTART ?? null);
        $end = self::dateProperty($component->DTEND ?? null);
        $due = self::dateProperty($component->DUE ?? null);
        $completed = self::dateProperty($component->COMPLETED ?? null);
        $timezone = self::timezone($component->DTSTART ?? $component->DUE ?? null);
        $allDay = self::isAllDay($component->DTSTART ?? $component->DUE ?? null);
        $status = strtolower(trim((string) ($component->STATUS ?? ($type === 'VTODO' ? 'NEEDS-ACTION' : 'CONFIRMED'))));
        $priority = isset($component->PRIORITY) ? (int) ((string) $component->PRIORITY) : null;
        $rrule = isset($component->RRULE) ? trim((string) $component->RRULE) : null;
        $alarms = $component->select('VALARM');
        $alarmTrigger = isset($alarms[0]->TRIGGER) ? trim((string) $alarms[0]->TRIGGER) : null;
        $attendees = self::parseAttendees($component);

        return [
            'uid' => $uid,
            'component_type' => $type,
            'calendar_data' => $calendarData,
            'etag' => hash('sha256', $calendarData),
            'size_bytes' => strlen($calendarData),
            'title' => $title,
            'description' => self::optional($component->DESCRIPTION ?? null),
            'location' => self::optional($component->LOCATION ?? null),
            'starts_at' => $start,
            'ends_at' => $end,
            'due_at' => $due,
            'completed_at' => $completed,
            'timezone' => $timezone,
            'all_day' => $allDay ? 1 : 0,
            'status' => $status,
            'priority' => $priority,
            'recurrence_rule' => $rrule,
            'alarm_trigger' => $alarmTrigger,
            'attendees' => $attendees !== [] ? json_encode($attendees) : null,
            'first_occurrence' => $start ?? $due,
            'last_occurrence' => $end ?? $due ?? $start,
        ];
    }

    /**
     * @param array<string, mixed> $fields
     */
    public static function build(array $fields, ?string $existingUid = null): string
    {
        $type = strtoupper((string) ($fields['component_type'] ?? 'VEVENT'));
        if (!in_array($type, ['VEVENT', 'VTODO'], true)) {
            throw new InvalidArgumentException('component_type must be VEVENT or VTODO.');
        }

        $title = trim((string) ($fields['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('title is required.');
        }

        $uid = trim((string) ($fields['uid'] ?? $existingUid ?? ''));
        if ($uid === '') {
            $uid = bin2hex(random_bytes(16)) . '@elonn';
        }

        $calendar = new VCalendar();
        $component = $calendar->add($type, [
            'UID' => $uid,
            'SUMMARY' => $title,
            'DTSTAMP' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
        ]);

        $timezone = trim((string) ($fields['timezone'] ?? ''));
        self::addDate($component, 'DTSTART', $fields['starts_at'] ?? null, (bool) ($fields['all_day'] ?? false), $timezone);
        if ($type === 'VEVENT') {
            self::addDate($component, 'DTEND', $fields['ends_at'] ?? null, (bool) ($fields['all_day'] ?? false), $timezone);
        } else {
            self::addDate($component, 'DUE', $fields['due_at'] ?? null, (bool) ($fields['all_day'] ?? false), $timezone);
            self::addDate($component, 'COMPLETED', $fields['completed_at'] ?? null, false, $timezone);
            if (isset($fields['priority']) && $fields['priority'] !== '') {
                $component->add('PRIORITY', max(0, min(9, (int) $fields['priority'])));
            }
        }

        foreach (['description' => 'DESCRIPTION', 'location' => 'LOCATION'] as $field => $property) {
            $value = trim((string) ($fields[$field] ?? ''));
            if ($value !== '') {
                $component->add($property, $value);
            }
        }

        $status = trim((string) ($fields['status'] ?? ''));
        if ($status !== '') {
            $normalizedStatus = match (strtolower($status)) {
                'active' => $type === 'VEVENT' ? 'CONFIRMED' : 'NEEDS-ACTION',
                'completed' => 'COMPLETED',
                'cancelled', 'canceled', 'deleted' => 'CANCELLED',
                default => strtoupper($status),
            };
            $component->add('STATUS', $normalizedStatus);
        }

        $rrule = trim((string) ($fields['recurrence_rule'] ?? ''));
        if ($rrule !== '') {
            $component->add('RRULE', $rrule);
        }

        $alarmTrigger = trim((string) ($fields['alarm_trigger'] ?? ''));
        if ($alarmTrigger !== '') {
            $alarm = $component->add('VALARM', [
                'ACTION' => 'DISPLAY',
                'DESCRIPTION' => $title,
                'TRIGGER' => $alarmTrigger,
            ]);
            unset($alarm);
        }

        foreach (self::normalizeAttendees($fields['attendees'] ?? null) as $attendee) {
            $params = [];
            if (($attendee['name'] ?? '') !== '') {
                $params['CN'] = $attendee['name'];
            }
            $component->add('ATTENDEE', 'mailto:' . $attendee['email'], $params);
        }

        return $calendar->serialize();
    }

    /**
     * @return array<int, array{name?: string, email: string}>
     */
    private static function parseAttendees(mixed $component): array
    {
        $attendees = [];
        foreach ($component->select('ATTENDEE') as $property) {
            $value = trim((string) $property);
            $email = stripos($value, 'mailto:') === 0 ? substr($value, 7) : $value;
            $email = trim($email);
            if ($email === '') {
                continue;
            }
            $name = isset($property['CN']) ? trim((string) $property['CN']) : '';
            $attendees[] = $name !== '' ? ['name' => $name, 'email' => $email] : ['email' => $email];
        }
        return $attendees;
    }

    /**
     * Accepts an attendees value in any of the shapes callers may reasonably supply it:
     * a JSON-encoded array (round-tripped from storage), a raw comma-separated string of
     * "Name <email>" / bare-email entries (as a Contract argument arrives), or an already
     * structured array of {name?, email} pairs.
     *
     * @return array<int, array{name?: string, email: string}>
     */
    private static function normalizeAttendees(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : array_map('trim', explode(',', $value));
        }

        if (!is_array($value)) {
            return [];
        }

        $attendees = [];
        foreach ($value as $entry) {
            if (is_array($entry)) {
                $email = trim((string) ($entry['email'] ?? ''));
                $name = trim((string) ($entry['name'] ?? ''));
            } else {
                $text = trim((string) $entry);
                $name = '';
                $email = $text;
                if (preg_match('/^(.*)<(.+)>$/', $text, $matches)) {
                    $name = trim($matches[1]);
                    $email = trim($matches[2]);
                }
            }
            if ($email === '') {
                continue;
            }
            $attendees[] = $name !== '' ? ['name' => $name, 'email' => $email] : ['email' => $email];
        }
        return $attendees;
    }

    private static function optional(mixed $property): ?string
    {
        $value = trim((string) ($property ?? ''));
        return $value === '' ? null : $value;
    }

    private static function dateProperty(mixed $property): ?string
    {
        if ($property === null) {
            return null;
        }

        try {
            $date = $property->getDateTime();
        } catch (\Throwable) {
            return null;
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function timezone(mixed $property): ?string
    {
        if ($property === null) {
            return null;
        }

        try {
            return $property->getDateTime()->getTimezone()->getName();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function isAllDay(mixed $property): bool
    {
        return $property !== null
            && isset($property['VALUE'])
            && strtoupper((string) $property['VALUE']) === 'DATE';
    }

    private static function addDate(mixed $component, string $name, mixed $value, bool $allDay, string $timezone): void
    {
        if ($value === null || trim((string) $value) === '') {
            return;
        }

        $date = $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable(
                (string) $value,
                $timezone !== '' ? new DateTimeZone($timezone) : null
            );
        if ($allDay) {
            $component->add($name, $date->format('Ymd'), ['VALUE' => 'DATE']);
            return;
        }

        $component->add($name, $date);
    }
}
