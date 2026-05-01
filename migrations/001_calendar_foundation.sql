CREATE TABLE IF NOT EXISTS time_calendars (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identity_user_id VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    color VARCHAR(32) NULL,
    timezone VARCHAR(64) NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    KEY time_calendars_identity_user_id_idx (identity_user_id),
    KEY time_calendars_status_idx (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS time_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identity_user_id VARCHAR(255) NOT NULL,
    calendar_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    location VARCHAR(255) NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    timezone VARCHAR(64) NULL,
    all_day TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(50) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    KEY time_events_identity_user_id_idx (identity_user_id),
    KEY time_events_calendar_id_idx (calendar_id),
    KEY time_events_starts_at_idx (starts_at),
    KEY time_events_status_idx (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
