-- Canonical mixed VEVENT/VTODO storage shared by Time APIs and CalDAV.
ALTER TABLE time_calendars
    ADD COLUMN uri VARCHAR(255) NULL AFTER identity_user_id,
    ADD COLUMN description TEXT NULL AFTER name,
    ADD COLUMN components VARCHAR(64) NOT NULL DEFAULT 'VEVENT,VTODO' AFTER timezone,
    ADD COLUMN sync_token BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER components,
    ADD UNIQUE KEY time_calendars_identity_uri_unique (identity_user_id, uri);

UPDATE time_calendars
SET uri = CONCAT('calendar-', id)
WHERE uri IS NULL OR uri = '';

ALTER TABLE time_calendars
    MODIFY COLUMN uri VARCHAR(255) NOT NULL;

CREATE TABLE IF NOT EXISTS time_calendar_objects (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identity_user_id VARCHAR(255) NOT NULL,
    calendar_id BIGINT UNSIGNED NOT NULL,
    uri VARCHAR(255) NOT NULL,
    uid VARCHAR(255) NOT NULL,
    component_type VARCHAR(16) NOT NULL,
    calendar_data LONGTEXT NOT NULL,
    etag CHAR(64) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    location VARCHAR(255) NULL,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    due_at DATETIME NULL,
    completed_at DATETIME NULL,
    timezone VARCHAR(64) NULL,
    all_day TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(50) NOT NULL DEFAULT 'active',
    priority TINYINT UNSIGNED NULL,
    recurrence_rule TEXT NULL,
    alarm_trigger VARCHAR(255) NULL,
    first_occurrence DATETIME NULL,
    last_occurrence DATETIME NULL,
    source_service VARCHAR(64) NULL,
    source_object_type VARCHAR(64) NULL,
    source_object_id VARCHAR(255) NULL,
    source_url VARCHAR(255) NULL,
    local_visibility VARCHAR(32) NOT NULL DEFAULT 'default',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    UNIQUE KEY time_calendar_objects_calendar_uri_unique (calendar_id, uri),
    UNIQUE KEY time_calendar_objects_identity_uid_unique (identity_user_id, uid),
    KEY time_calendar_objects_identity_component_idx (identity_user_id, component_type),
    KEY time_calendar_objects_calendar_idx (calendar_id),
    KEY time_calendar_objects_range_idx (identity_user_id, starts_at, ends_at),
    KEY time_calendar_objects_due_idx (identity_user_id, due_at),
    KEY time_calendar_objects_source_idx (identity_user_id, source_service, source_object_type, source_object_id),
    CONSTRAINT time_calendar_objects_calendar_fk
        FOREIGN KEY (calendar_id) REFERENCES time_calendars(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS time_calendar_changes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    calendar_id BIGINT UNSIGNED NOT NULL,
    sync_token BIGINT UNSIGNED NOT NULL,
    uri VARCHAR(255) NOT NULL,
    operation VARCHAR(16) NOT NULL,
    created_at DATETIME NOT NULL,
    KEY time_calendar_changes_sync_idx (calendar_id, sync_token),
    CONSTRAINT time_calendar_changes_calendar_fk
        FOREIGN KEY (calendar_id) REFERENCES time_calendars(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO time_calendar_objects (
    identity_user_id, calendar_id, uri, uid, component_type, calendar_data,
    etag, size_bytes, title, description, location, starts_at, ends_at,
    timezone, all_day, status, first_occurrence, last_occurrence,
    source_service, source_object_type, source_object_id, source_url,
    created_at, updated_at
)
SELECT
    e.identity_user_id,
    e.calendar_id,
    CONCAT('event-', e.id, '.ics'),
    CONCAT('time-event-', e.id, '@elonn'),
    'VEVENT',
    CONCAT(
        'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Elonn//Time//EN\r\n',
        'BEGIN:VEVENT\r\nUID:time-event-', e.id, '@elonn\r\n',
        'DTSTAMP:', DATE_FORMAT(COALESCE(e.updated_at, e.created_at), '%Y%m%dT%H%i%sZ'), '\r\n',
        IF(e.starts_at IS NULL, '', CONCAT('DTSTART:', DATE_FORMAT(e.starts_at, '%Y%m%dT%H%i%s'), '\r\n')),
        IF(e.ends_at IS NULL, '', CONCAT('DTEND:', DATE_FORMAT(e.ends_at, '%Y%m%dT%H%i%s'), '\r\n')),
        'SUMMARY:', REPLACE(REPLACE(REPLACE(e.title, '\\', '\\\\'), '\n', '\\n'), ',', '\\,'), '\r\n',
        IF(e.description IS NULL, '', CONCAT('DESCRIPTION:', REPLACE(REPLACE(REPLACE(e.description, '\\', '\\\\'), '\n', '\\n'), ',', '\\,'), '\r\n')),
        IF(e.location IS NULL, '', CONCAT('LOCATION:', REPLACE(REPLACE(e.location, '\\', '\\\\'), ',', '\\,'), '\r\n')),
        'STATUS:', UPPER(IF(e.status = 'active', 'CONFIRMED', e.status)), '\r\n',
        'END:VEVENT\r\nEND:VCALENDAR\r\n'
    ),
    SHA2(CONCAT('time-event-', e.id, '-', COALESCE(e.updated_at, e.created_at)), 256),
    0,
    e.title,
    e.description,
    e.location,
    e.starts_at,
    e.ends_at,
    e.timezone,
    e.all_day,
    e.status,
    e.starts_at,
    e.ends_at,
    e.source_service,
    e.source_object_type,
    e.source_object_id,
    e.source_url,
    e.created_at,
    e.updated_at
FROM time_events e;

UPDATE time_calendar_objects
SET size_bytes = OCTET_LENGTH(calendar_data)
WHERE size_bytes = 0;
