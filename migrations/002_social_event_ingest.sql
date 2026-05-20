ALTER TABLE time_calendars
    ADD COLUMN source_service VARCHAR(64) NULL AFTER status,
    ADD COLUMN source_object_type VARCHAR(64) NULL AFTER source_service,
    ADD COLUMN source_object_id VARCHAR(255) NULL AFTER source_object_type,
    ADD COLUMN source_url VARCHAR(255) NULL AFTER source_object_id,
    ADD UNIQUE KEY time_calendars_source_unique (identity_user_id, source_service, source_object_type, source_object_id);

ALTER TABLE time_events
    ADD COLUMN source_service VARCHAR(64) NULL AFTER status,
    ADD COLUMN source_object_type VARCHAR(64) NULL AFTER source_service,
    ADD COLUMN source_object_id VARCHAR(255) NULL AFTER source_object_type,
    ADD COLUMN source_url VARCHAR(255) NULL AFTER source_object_id,
    ADD UNIQUE KEY time_events_source_unique (identity_user_id, source_service, source_object_type, source_object_id);

UPDATE time_calendars
SET source_service = NULL,
    source_object_type = NULL,
    source_object_id = NULL,
    source_url = NULL
WHERE source_service IS NULL;
