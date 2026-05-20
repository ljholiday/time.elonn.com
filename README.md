# Elonn Time

`time.elonn.local` is the calendar and scheduling service for Elonn.

It owns calendar data, event data, and the Time runtime panel. Identity is validated through the shared API. Social events are ingested into Time as per-member calendar copies so members can see social events on their calendars without duplicating event authority.

## Responsibilities

- calendar and event storage
- authenticated calendar/event APIs
- Social event ingestion for calendar mirrors
- runtime panel rendering for the Web shell
- identity checks through `api.elonn`

## Consumed by

- `web.elonn.local`
- `world.elonn.local`
- `elonn.local` for shared auth flow

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

The repo-specific guidance file is [`time.elonn.md`](./time.elonn.md).

## Migrations

Run migrations with:

```bash
php scripts/migrate.php
php scripts/migrate.php status
```

Add a new SQL file when schema or seed behavior changes. Do not edit applied migrations.
