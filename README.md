# Elonn Time

`time.elonn.local` is the calendar and scheduling service for Elonn.

It owns calendar data, event data, and the Time runtime panel. Identity is validated through the shared API. Social events are ingested into Time as per-member calendar copies so members can see social events on their calendars without duplicating event authority.

External CalDAV clients use `https://services.elonn.com/caldav/`.
`https://time.elonn.com/dav/` remains a compatibility alias.
The PHP DOM extension must be enabled for the active cPanel PHP version.

## Responsibilities

- calendar and event storage
- authenticated calendar/event APIs
- Social event ingestion for calendar mirrors
- runtime panel rendering for the Web shell
- identity checks through `api.elonn`

## Routes

- `GET /health`
- `GET /ready`
- `GET /`
- `GET /calendars`
- `POST /calendars`
- `GET /events`
- `POST /events`
- `POST /integrations/social/events`
- `GET /runtime/panel/time`
- `POST /runtime/calendars`

## Configuration

Use `.env` for deployment state and keep it uncommitted.

Important keys:

```env
APP_ENV=
APP_DEBUG=
APP_URL=
DB_HOST=
DB_PORT=
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=
DB_CHARSET=
ELONN_API_BASE_URL=
ELONN_SOCIAL_BASE_URL=
ELONN_SOCIAL_INGEST_TOKEN=
```

## Migrations

Run migrations with:

```bash
php scripts/migrate.php
php scripts/migrate.php status
```

Add a new SQL file when schema or seed behavior changes. Do not edit applied migrations.

## Verification

```bash
find public src templates scripts config -name '*.php' -print0 | xargs -0 -n1 php -l
```

Local: `http://time.elonn.local/`, `http://time.elonn.local/ready`
Production: `https://time.elonn.com/`, `https://time.elonn.com/ready`
