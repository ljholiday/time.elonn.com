<?php

declare(strict_types=1);

namespace Elonn\Time;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Sabre\VObject\Recur\EventIterator;
use Sabre\VObject\Reader;

/**
 * Canonical native Time API over the same calendar objects used by CalDAV.
 */
final class CalendarStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function workspace(
        string $identityUserId,
        string $view,
        string $anchorDate,
        string $timezone
    ): array {
        $timezoneObject = new DateTimeZone($timezone);
        $anchor = new DateTimeImmutable($anchorDate . ' 00:00:00', $timezoneObject);
        [$rangeStart, $rangeEnd] = $this->viewRange($view, $anchor);
        $objects = $this->objectsInRange($identityUserId, $rangeStart, $rangeEnd, $timezoneObject);
        $appointments = array_values(array_filter($objects, static fn (array $item): bool => $item['component_type'] === 'VEVENT'));
        $tasks = $this->tasks($identityUserId, $view === 'tasks' ? null : $rangeEnd);

        return [
            'kind' => 'time',
            'view' => $view,
            'anchor_date' => $anchor->format('Y-m-d'),
            'timezone' => $timezone,
            'range' => [
                'start' => $rangeStart->format(DATE_ATOM),
                'end' => $rangeEnd->format(DATE_ATOM),
            ],
            'calendars' => $this->calendars($identityUserId),
            'appointments' => $appointments,
            'tasks' => $tasks,
        ];
    }

    /** @return array{appointments: array<int, array<string, mixed>>, tasks: array<int, array<string, mixed>>} */
    public function objectSources(string $identityUserId): array
    {
        $timezone = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $timezone);
        $appointments = $this->objectsInRange($identityUserId, $now, $now->add(new DateInterval('P90D')), $timezone);
        $tasks = $this->tasks($identityUserId, null);

        return [
            'appointments' => array_map(
                fn (array $object): array => $this->calendarObjectSource($object),
                $appointments
            ),
            'tasks' => array_map(
                fn (array $object): array => $this->calendarObjectSource($object),
                $tasks
            ),
        ];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function create(string $identityUserId, array $fields): array
    {
        $calendarId = (int) ($fields['calendar_id'] ?? 0);
        $this->requireCalendar($identityUserId, $calendarId);
        $calendarData = CalendarObject::build($fields);
        $parsed = CalendarObject::parse($calendarData);
        $uri = trim((string) ($fields['uri'] ?? ''));
        if ($uri === '') {
            $uri = preg_replace('/[^a-zA-Z0-9._-]+/', '-', (string) $parsed['uid']) . '.ics';
        }
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO time_calendar_objects
                (identity_user_id, calendar_id, uri, uid, component_type, calendar_data, etag, size_bytes,
                 title, description, location, starts_at, ends_at, due_at, completed_at, timezone, all_day,
                 status, priority, recurrence_rule, alarm_trigger, first_occurrence, last_occurrence, created_at)
             VALUES
                (:identity_user_id, :calendar_id, :uri, :uid, :component_type, :calendar_data, :etag, :size_bytes,
                 :title, :description, :location, :starts_at, :ends_at, :due_at, :completed_at, :timezone, :all_day,
                 :status, :priority, :recurrence_rule, :alarm_trigger, :first_occurrence, :last_occurrence, :created_at)'
        );
        $stmt->execute(['identity_user_id' => $identityUserId, 'calendar_id' => $calendarId, 'uri' => $uri, 'created_at' => $now] + $parsed);
        $id = (int) $this->pdo->lastInsertId();
        $this->touchCalendar($calendarId, $uri, 'created');
        return $this->find($identityUserId, $id) ?? [];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function update(string $identityUserId, int $id, array $fields): array
    {
        $existing = $this->findRow($identityUserId, $id);
        if ($existing === null) {
            throw new \RuntimeException('Calendar object not found.');
        }
        $current = $this->payload($existing);
        $merged = $current + [];
        foreach ($fields as $key => $value) {
            $merged[$key] = $value;
        }

        if (($existing['source_service'] ?? null) === 'social') {
            foreach (['component_type', 'title', 'description', 'location', 'starts_at', 'ends_at', 'due_at', 'completed_at', 'all_day', 'status', 'priority', 'recurrence_rule'] as $field) {
                if (array_key_exists($field, $fields) && $fields[$field] !== $current[$field]) {
                    throw new \DomainException('Social-owned calendar fields are read-only.');
                }
            }
        }

        $calendarId = (int) ($merged['calendar_id'] ?? $existing['calendar_id']);
        $this->requireCalendar($identityUserId, $calendarId);
        $calendarData = CalendarObject::build($merged, (string) $existing['uid']);
        $parsed = CalendarObject::parse($calendarData);
        $assignments = [];
        foreach (array_keys($parsed) as $field) {
            $assignments[] = $field . ' = :' . $field;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE time_calendar_objects SET calendar_id = :calendar_id, ' . implode(', ', $assignments) . ',
                    local_visibility = :local_visibility, updated_at = :updated_at
             WHERE id = :id AND identity_user_id = :identity_user_id'
        );
        $stmt->execute($parsed + [
            'calendar_id' => $calendarId,
            'local_visibility' => (string) ($merged['local_visibility'] ?? 'default'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
            'id' => $id,
            'identity_user_id' => $identityUserId,
        ]);
        $existingCalendarId = (int) $existing['calendar_id'];
        if ($existingCalendarId !== $calendarId) {
            $this->touchCalendar($existingCalendarId, (string) $existing['uri'], 'deleted');
            $this->touchCalendar($calendarId, (string) $existing['uri'], 'created');
        } else {
            $this->touchCalendar($calendarId, (string) $existing['uri'], 'updated');
        }
        return $this->find($identityUserId, $id) ?? [];
    }

    public function delete(string $identityUserId, int $id): void
    {
        $existing = $this->findRow($identityUserId, $id);
        if ($existing === null) {
            return;
        }
        if (($existing['source_service'] ?? null) === 'social') {
            throw new \DomainException('Social event mirrors cannot be deleted from Time.');
        }
        $this->pdo->prepare(
            'DELETE FROM time_calendar_objects WHERE id = :id AND identity_user_id = :identity_user_id'
        )->execute(['id' => $id, 'identity_user_id' => $identityUserId]);
        $this->touchCalendar((int) $existing['calendar_id'], (string) $existing['uri'], 'deleted');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $identityUserId, int $id): ?array
    {
        $row = $this->findRow($identityUserId, $id);
        return $row === null ? null : $this->payload($row);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function calendars(string $identityUserId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, uri, name, description, color, timezone, components, source_service, source_object_type, source_object_id
             FROM time_calendars WHERE identity_user_id = :identity_user_id AND status = \'active\' ORDER BY name'
        );
        $stmt->execute(['identity_user_id' => $identityUserId]);
        return array_map(static function (array $calendar): array {
            $calendar['id'] = (int) $calendar['id'];
            $calendar['editable_fields'] = ['name', 'description', 'color', 'timezone'];
            $calendar['deletable'] = ($calendar['source_service'] ?? null) !== 'social';
            return $calendar;
        }, $stmt->fetchAll());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function objectsInRange(
        string $identityUserId,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        DateTimeZone $timezone
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT o.*, c.name AS calendar_name, c.color AS calendar_color
             FROM time_calendar_objects o
             INNER JOIN time_calendars c ON c.id = o.calendar_id
             WHERE o.identity_user_id = :identity_user_id
               AND o.component_type = \'VEVENT\'
               AND c.status = \'active\'
               AND (
                    o.recurrence_rule IS NOT NULL
                    OR (o.starts_at IS NOT NULL AND o.starts_at < :range_end AND COALESCE(o.ends_at, o.starts_at) >= :range_start)
               )
             ORDER BY o.starts_at, o.id'
        );
        $stmt->execute([
            'identity_user_id' => $identityUserId,
            'range_start' => $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'range_end' => $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            if (($row['recurrence_rule'] ?? null) === null) {
                $result[] = $this->payload($row, $timezone);
                continue;
            }

            try {
                $calendar = Reader::read((string) $row['calendar_data']);
                $iterator = new EventIterator($calendar, (string) $row['uid']);
                $iterator->fastForward($start);
                while ($iterator->valid() && $iterator->getDTStart() < $end) {
                    $occurrence = $this->payload($row, $timezone);
                    $occurrence['occurrence_start'] = $iterator->getDTStart()->setTimezone($timezone)->format(DATE_ATOM);
                    $occurrence['occurrence_end'] = $iterator->getDTEnd()->setTimezone($timezone)->format(DATE_ATOM);
                    $result[] = $occurrence;
                    $iterator->next();
                }
            } catch (\Throwable) {
                $result[] = $this->payload($row, $timezone);
            }
        }
        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tasks(string $identityUserId, ?DateTimeImmutable $before): array
    {
        $sql = 'SELECT o.*, c.name AS calendar_name, c.color AS calendar_color
                FROM time_calendar_objects o
                INNER JOIN time_calendars c ON c.id = o.calendar_id
                WHERE o.identity_user_id = :identity_user_id AND o.component_type = \'VTODO\' AND c.status = \'active\'';
        $params = ['identity_user_id' => $identityUserId];
        if ($before !== null) {
            $sql .= ' AND (o.due_at IS NULL OR o.due_at < :due_before)';
            $params['due_before'] = $before->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        $sql .= ' ORDER BY CASE WHEN o.completed_at IS NULL THEN 0 ELSE 1 END, o.due_at, o.priority, o.id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map(fn (array $row): array => $this->payload($row), $stmt->fetchAll());
    }

    /**
     * @return array{0:DateTimeImmutable,1:DateTimeImmutable}
     */
    private function viewRange(string $view, DateTimeImmutable $anchor): array
    {
        return match ($view) {
            'week' => [
                $anchor->modify('monday this week'),
                $anchor->modify('monday this week')->add(new DateInterval('P7D')),
            ],
            'month' => [
                $anchor->modify('first day of this month'),
                $anchor->modify('first day of next month'),
            ],
            'agenda' => [$anchor, $anchor->add(new DateInterval('P90D'))],
            'tasks' => [$anchor, $anchor->add(new DateInterval('P1Y'))],
            default => [$anchor, $anchor->add(new DateInterval('P1D'))],
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRow(string $identityUserId, int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.*, c.name AS calendar_name, c.color AS calendar_color
             FROM time_calendar_objects o
             INNER JOIN time_calendars c ON c.id = o.calendar_id
             WHERE o.id = :id AND o.identity_user_id = :identity_user_id'
        );
        $stmt->execute(['id' => $id, 'identity_user_id' => $identityUserId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function requireCalendar(string $identityUserId, int $calendarId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM time_calendars
             WHERE id = :id AND identity_user_id = :identity_user_id AND status = \'active\''
        );
        $stmt->execute(['id' => $calendarId, 'identity_user_id' => $identityUserId]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new \InvalidArgumentException('Valid calendar_id is required.');
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function payload(array $row, ?DateTimeZone $timezone = null): array
    {
        $format = static function (mixed $value) use ($timezone): ?string {
            if ($value === null || $value === '') {
                return null;
            }
            $date = new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
            return $timezone === null
                ? $date->format(DATE_ATOM)
                : $date->setTimezone($timezone)->format(DATE_ATOM);
        };
        return [
            'id' => (int) $row['id'],
            'calendar_id' => (int) $row['calendar_id'],
            'calendar_name' => (string) ($row['calendar_name'] ?? ''),
            'calendar_color' => $row['calendar_color'] ?? null,
            'uri' => (string) $row['uri'],
            'uid' => (string) $row['uid'],
            'component_type' => (string) $row['component_type'],
            'title' => (string) $row['title'],
            'description' => $row['description'] ?? null,
            'location' => $row['location'] ?? null,
            'starts_at' => $format($row['starts_at'] ?? null),
            'ends_at' => $format($row['ends_at'] ?? null),
            'due_at' => $format($row['due_at'] ?? null),
            'completed_at' => $format($row['completed_at'] ?? null),
            'timezone' => $row['timezone'] ?? null,
            'all_day' => (bool) $row['all_day'],
            'status' => (string) $row['status'],
            'priority' => $row['priority'] === null ? null : (int) $row['priority'],
            'recurrence_rule' => $row['recurrence_rule'] ?? null,
            'alarm_trigger' => $row['alarm_trigger'] ?? $this->alarmTrigger((string) $row['calendar_data']),
            'source' => $row['source_service'] === null ? null : [
                'service' => (string) $row['source_service'],
                'object_type' => (string) ($row['source_object_type'] ?? ''),
                'object_id' => (string) ($row['source_object_id'] ?? ''),
                'url' => $row['source_url'] ?? null,
            ],
            'local_visibility' => (string) ($row['local_visibility'] ?? 'default'),
            'editable_fields' => $row['source_service'] === 'social'
                ? ['calendar_id', 'local_visibility', 'alarm_trigger']
                : ['calendar_id', 'title', 'description', 'location', 'starts_at', 'ends_at', 'due_at', 'completed_at', 'all_day', 'status', 'priority', 'recurrence_rule', 'alarm_trigger'],
        ];
    }

    /** @param array<string, mixed> $object */
    private function calendarObjectSource(array $object): array
    {
        $id = (string) ($object['id'] ?? '');
        $componentType = (string) ($object['component_type'] ?? '');
        $objectType = $componentType === 'VTODO' ? 'task' : 'calendar_event';
        $editableFields = is_array($object['editable_fields'] ?? null) ? $object['editable_fields'] : [];
        $canMutate = $editableFields !== [];

        return [
            'source' => [
                'service' => 'time',
                'resource_type' => $objectType,
                'resource_id' => $id,
            ],
            'object_type' => $objectType,
            'title' => (string) ($object['title'] ?? ($objectType === 'task' ? 'Task' : 'Calendar event')),
            'summary' => $this->calendarObjectSummary($object),
            'state' => $object,
            'domain_permissions' => [
                'can_view' => true,
                'can_update' => $canMutate,
                'can_delete' => !is_array($object['source'] ?? null),
            ],
            'domain_actions' => [
                [
                    'id' => 'open_time_object',
                    'type' => 'open_object',
                    'label' => 'Open',
                    'availability' => 'enabled',
                ],
                [
                    'id' => 'update_time_object',
                    'type' => 'update_object',
                    'label' => 'Update',
                    'availability' => $canMutate ? 'enabled' : 'denied',
                    'controls' => $this->calendarObjectControls($object),
                ],
                [
                    'id' => 'delete_time_object',
                    'type' => 'delete_object',
                    'label' => 'Delete',
                    'availability' => is_array($object['source'] ?? null) ? 'denied' : 'enabled',
                ],
            ],
            'relationships' => [],
        ];
    }

    /** @param array<string, mixed> $object */
    private function calendarObjectSummary(array $object): string
    {
        $parts = array_values(array_filter([
            (string) ($object['starts_at'] ?? $object['due_at'] ?? ''),
            (string) ($object['location'] ?? ''),
            (string) ($object['calendar_name'] ?? ''),
        ]));

        return implode(' · ', $parts);
    }

    /** @param array<string, mixed> $object */
    private function calendarObjectControls(array $object): array
    {
        $editable = is_array($object['editable_fields'] ?? null) ? $object['editable_fields'] : [];
        $controls = [];
        foreach ($editable as $field) {
            $field = (string) $field;
            $controls[] = [
                'id' => $field,
                'type' => match ($field) {
                    'description' => 'textarea',
                    'all_day' => 'checkbox',
                    default => 'text',
                },
                'label' => ucwords(str_replace('_', ' ', $field)),
                'required' => in_array($field, ['calendar_id', 'title'], true),
            ];
        }

        return $controls;
    }

    private function touchCalendar(int $calendarId, string $uri, string $operation): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE time_calendars SET sync_token = sync_token + 1, updated_at = :updated_at WHERE id = :id'
            )->execute(['updated_at' => $now, 'id' => $calendarId]);
            $token = (int) $this->pdo->query('SELECT sync_token FROM time_calendars WHERE id = ' . $calendarId)->fetchColumn();
            $this->pdo->prepare(
                'INSERT INTO time_calendar_changes (calendar_id, sync_token, uri, operation, created_at)
                 VALUES (:calendar_id, :sync_token, :uri, :operation, :created_at)'
            )->execute([
                'calendar_id' => $calendarId,
                'sync_token' => $token,
                'uri' => $uri,
                'operation' => $operation,
                'created_at' => $now,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $throwable) {
            $this->pdo->rollBack();
            throw $throwable;
        }
    }

    private function alarmTrigger(string $calendarData): ?string
    {
        try {
            return CalendarObject::parse($calendarData)['alarm_trigger'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }
}
