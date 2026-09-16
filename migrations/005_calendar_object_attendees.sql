-- Adds attendee round-tripping (VEVENT/VTODO ATTENDEE properties) as a JSON-encoded
-- array of {name?, email} pairs, stored alongside the other canonical calendar object fields.
ALTER TABLE time_calendar_objects
    ADD COLUMN attendees TEXT NULL AFTER alarm_trigger;
