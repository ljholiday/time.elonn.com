# Elonn Time

`time.elonn.local` is the calendar and event service for Elonn.

It owns calendar data, event data, and the Time runtime panel. Identity is validated through the shared API.

## Responsibilities

- calendar and event storage
- authenticated calendar/event APIs
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
```

The repo-specific guidance file is [`time.elonn.md`](./time.elonn.md).

## Migrations

Run migrations with:

```bash
php scripts/migrate.php
php scripts/migrate.php status
```

Add a new SQL file when schema or seed behavior changes. Do not edit applied migrations.
