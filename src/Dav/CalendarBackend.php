<?php

declare(strict_types=1);

namespace Elonn\Time\Dav;

use Elonn\Time\CalendarObject;
use PDO;
use Sabre\CalDAV\Backend\AbstractBackend;
use Sabre\CalDAV\Backend\SyncSupport;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\DAV\PropPatch;

/**
 * SabreDAV backend over Time's canonical mixed VEVENT/VTODO collections.
 */
final class CalendarBackend extends AbstractBackend implements SyncSupport
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $identityUserId
    ) {
    }

    public function getCalendarsForUser($principalUri): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, uri, name, description, color, timezone, components, sync_token
             FROM time_calendars
             WHERE identity_user_id = :identity_user_id AND status = \'active\'
             ORDER BY id'
        );
        $stmt->execute(['identity_user_id' => $this->identityUserId]);

        return array_map(fn (array $row): array => $this->calendarPayload($row, (string) $principalUri), $stmt->fetchAll());
    }

    public function createCalendar($principalUri, $calendarUri, array $properties): int
    {
        $displayName = trim((string) ($properties['{DAV:}displayname'] ?? $calendarUri));
        $color = trim((string) ($properties['{http://apple.com/ns/ical/}calendar-color'] ?? ''));
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO time_calendars
                (identity_user_id, uri, name, color, components, sync_token, status, created_at)
             VALUES
                (:identity_user_id, :uri, :name, :color, \'VEVENT,VTODO\', 1, \'active\', :created_at)'
        );
        $stmt->execute([
            'identity_user_id' => $this->identityUserId,
            'uri' => $calendarUri,
            'name' => $displayName !== '' ? $displayName : $calendarUri,
            'color' => $color !== '' ? $color : null,
            'created_at' => $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateCalendar($calendarId, PropPatch $propPatch): void
    {
        $map = [
            '{DAV:}displayname' => 'name',
            '{urn:ietf:params:xml:ns:caldav}calendar-description' => 'description',
            '{http://apple.com/ns/ical/}calendar-color' => 'color',
            '{urn:ietf:params:xml:ns:caldav}calendar-timezone' => 'timezone',
        ];
        $propPatch->handle(array_keys($map), function (array $mutations) use ($calendarId, $map): bool {
            foreach ($mutations as $property => $value) {
                $column = $map[$property];
                $stmt = $this->pdo->prepare(
                    "UPDATE time_calendars SET {$column} = :value, updated_at = :updated_at
                     WHERE id = :id AND identity_user_id = :identity_user_id"
                );
                $stmt->execute([
                    'value' => is_scalar($value) ? (string) $value : null,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                    'id' => (int) $calendarId,
                    'identity_user_id' => $this->identityUserId,
                ]);
            }
            return true;
        });
    }

    public function deleteCalendar($calendarId): void
    {
        $calendar = $this->calendarRow((int) $calendarId);
        if (($calendar['source_service'] ?? null) === 'social') {
            throw new \Sabre\DAV\Exception\Forbidden('The Social mirror calendar cannot be deleted from Time.');
        }
        $stmt = $this->pdo->prepare(
            'UPDATE time_calendars SET status = \'deleted\', updated_at = :updated_at
             WHERE id = :id AND identity_user_id = :identity_user_id'
        );
        $stmt->execute([
            'updated_at' => gmdate('Y-m-d H:i:s'),
            'id' => (int) $calendarId,
            'identity_user_id' => $this->identityUserId,
        ]);
    }

    public function getCalendarObjects($calendarId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT calendar_id, uri, calendar_data, etag, size_bytes, component_type, updated_at, created_at
             FROM time_calendar_objects
             WHERE calendar_id = :calendar_id AND identity_user_id = :identity_user_id
             ORDER BY id'
        );
        $stmt->execute([
            'calendar_id' => (int) $calendarId,
            'identity_user_id' => $this->identityUserId,
        ]);
        return array_map([$this, 'objectPayload'], $stmt->fetchAll());
    }

    public function getCalendarObject($calendarId, $objectUri): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT calendar_id, uri, calendar_data, etag, size_bytes, component_type, updated_at, created_at
             FROM time_calendar_objects
             WHERE calendar_id = :calendar_id AND identity_user_id = :identity_user_id AND uri = :uri'
        );
        $stmt->execute([
            'calendar_id' => (int) $calendarId,
            'identity_user_id' => $this->identityUserId,
            'uri' => $objectUri,
        ]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->objectPayload($row) : null;
    }

    public function createCalendarObject($calendarId, $objectUri, $calendarData): string
    {
        $fields = CalendarObject::parse((string) $calendarData);
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
        $stmt->execute(['identity_user_id' => $this->identityUserId, 'calendar_id' => (int) $calendarId, 'uri' => $objectUri, 'created_at' => $now] + $fields);
        $this->recordChange((int) $calendarId, $objectUri, 'created');
        return '"' . $fields['etag'] . '"';
    }

    public function updateCalendarObject($calendarId, $objectUri, $calendarData): string
    {
        $existing = $this->objectRow((int) $calendarId, (string) $objectUri);
        if ($existing === null) {
            return $this->createCalendarObject($calendarId, $objectUri, $calendarData);
        }

        $fields = CalendarObject::parse((string) $calendarData);
        if (($existing['source_service'] ?? null) === 'social') {
            $current = CalendarObject::parse((string) $existing['calendar_data']);
            foreach (['uid', 'component_type', 'title', 'description', 'location', 'starts_at', 'ends_at', 'due_at', 'completed_at', 'all_day', 'status', 'priority', 'recurrence_rule'] as $field) {
                if ($fields[$field] !== $current[$field]) {
                    throw new \Sabre\DAV\Exception\Forbidden('Social-owned calendar fields are read-only.');
                }
            }
        }

        $assignments = [];
        foreach (array_keys($fields) as $field) {
            $assignments[] = $field . ' = :' . $field;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE time_calendar_objects SET ' . implode(', ', $assignments) . ', updated_at = :updated_at
             WHERE calendar_id = :calendar_id AND identity_user_id = :identity_user_id AND uri = :uri'
        );
        $stmt->execute($fields + [
            'updated_at' => gmdate('Y-m-d H:i:s'),
            'calendar_id' => (int) $calendarId,
            'identity_user_id' => $this->identityUserId,
            'uri' => $objectUri,
        ]);
        $this->recordChange((int) $calendarId, (string) $objectUri, 'updated');
        return '"' . $fields['etag'] . '"';
    }

    public function deleteCalendarObject($calendarId, $objectUri): void
    {
        $existing = $this->objectRow((int) $calendarId, (string) $objectUri);
        if (($existing['source_service'] ?? null) === 'social') {
            throw new \Sabre\DAV\Exception\Forbidden('Social event mirrors cannot be deleted from Time.');
        }
        $stmt = $this->pdo->prepare(
            'DELETE FROM time_calendar_objects
             WHERE calendar_id = :calendar_id AND identity_user_id = :identity_user_id AND uri = :uri'
        );
        $stmt->execute([
            'calendar_id' => (int) $calendarId,
            'identity_user_id' => $this->identityUserId,
            'uri' => $objectUri,
        ]);
        $this->recordChange((int) $calendarId, (string) $objectUri, 'deleted');
    }

    public function getChangesForCalendar($calendarId, $syncToken, $syncLevel, $limit = null): array
    {
        $calendar = $this->calendarRow((int) $calendarId);
        $currentToken = (int) ($calendar['sync_token'] ?? 1);
        $token = $syncToken === null ? 0 : (int) $syncToken;
        $sql = 'SELECT uri, operation FROM time_calendar_changes
                WHERE calendar_id = :calendar_id AND sync_token > :sync_token
                ORDER BY sync_token';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, (int) $limit);
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['calendar_id' => (int) $calendarId, 'sync_token' => $token]);
        $added = [];
        $modified = [];
        $deleted = [];
        foreach ($stmt->fetchAll() as $row) {
            if ($row['operation'] === 'created') {
                $added[] = $row['uri'];
            } elseif ($row['operation'] === 'deleted') {
                $deleted[] = $row['uri'];
            } else {
                $modified[] = $row['uri'];
            }
        }
        return [
            'syncToken' => $currentToken,
            'added' => array_values(array_unique($added)),
            'modified' => array_values(array_unique($modified)),
            'deleted' => array_values(array_unique($deleted)),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function calendarPayload(array $row, string $principalUri): array
    {
        return [
            'id' => (int) $row['id'],
            'uri' => (string) $row['uri'],
            'principaluri' => $principalUri,
            '{DAV:}displayname' => (string) $row['name'],
            '{urn:ietf:params:xml:ns:caldav}calendar-description' => (string) ($row['description'] ?? ''),
            '{http://apple.com/ns/ical/}calendar-color' => (string) ($row['color'] ?? ''),
            '{urn:ietf:params:xml:ns:caldav}calendar-timezone' => (string) ($row['timezone'] ?? ''),
            '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new SupportedCalendarComponentSet(['VEVENT', 'VTODO']),
            '{http://sabredav.org/ns}sync-token' => (int) $row['sync_token'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function objectPayload(array $row): array
    {
        return [
            'calendarid' => (int) $row['calendar_id'],
            'uri' => (string) $row['uri'],
            'calendardata' => (string) $row['calendar_data'],
            'etag' => '"' . (string) $row['etag'] . '"',
            'size' => (int) $row['size_bytes'],
            'component' => strtolower((string) $row['component_type']),
            'lastmodified' => strtotime((string) ($row['updated_at'] ?: $row['created_at'])) ?: time(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function calendarRow(int $calendarId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM time_calendars WHERE id = :id AND identity_user_id = :identity_user_id'
        );
        $stmt->execute(['id' => $calendarId, 'identity_user_id' => $this->identityUserId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function objectRow(int $calendarId, string $uri): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM time_calendar_objects
             WHERE calendar_id = :calendar_id AND identity_user_id = :identity_user_id AND uri = :uri'
        );
        $stmt->execute(['calendar_id' => $calendarId, 'identity_user_id' => $this->identityUserId, 'uri' => $uri]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function recordChange(int $calendarId, string $uri, string $operation): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE time_calendars SET sync_token = sync_token + 1, updated_at = :updated_at
                 WHERE id = :id AND identity_user_id = :identity_user_id'
            )->execute([
                'updated_at' => gmdate('Y-m-d H:i:s'),
                'id' => $calendarId,
                'identity_user_id' => $this->identityUserId,
            ]);
            $calendar = $this->calendarRow($calendarId);
            $this->pdo->prepare(
                'INSERT INTO time_calendar_changes (calendar_id, sync_token, uri, operation, created_at)
                 VALUES (:calendar_id, :sync_token, :uri, :operation, :created_at)'
            )->execute([
                'calendar_id' => $calendarId,
                'sync_token' => (int) ($calendar['sync_token'] ?? 1),
                'uri' => $uri,
                'operation' => $operation,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $this->pdo->commit();
        } catch (\Throwable $throwable) {
            $this->pdo->rollBack();
            throw $throwable;
        }
    }
}
