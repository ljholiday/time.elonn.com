# Elonn Time

`time.elonn.local` is the standalone calendar/time product service for Elonn.

It owns the `elonn_time` product database and validates identity by calling the shared API identity service. It must not connect directly to the `elonn_api` database.

## Endpoints

### `GET /health`

Reports that the app is alive.

```json
{
  "status": "ok",
  "service": "elonn_time"
}
```

### `GET /ready`

Reports whether required dependencies are reachable.

Required dependencies:

- `elonn_time` database
- API auth service configured by `ELONN_API_BASE_URL`

### Protected Calendar Endpoints

All calendar endpoints require:

```text
Authorization: Bearer <api-token>
```

The token is validated through:

```text
GET /identity/me
```

on `api.elonn.local` or `api.elonn.com`.

Current endpoints:

- `GET /calendars`
- `POST /calendars`
- `GET /calendars/{id}`
- `PATCH /calendars/{id}`
- `DELETE /calendars/{id}`
- `GET /events`
- `POST /events`
- `GET /events/{id}`
- `PATCH /events/{id}`
- `DELETE /events/{id}`

## Database

The current database is:

```text
elonn_time
```

Identity ownership is stored as `identity_user_id VARCHAR(255)` using the same type shape returned by `api.elonn.com`.

Current tables:

- `time_calendars`
- `time_events`

Run migrations with:

```bash
php scripts/migrate.php
```

Check migration status with:

```bash
php scripts/migrate.php status
```

## Configuration

Create `config/.env` from `config/.env.example`.

Development:

```env
ELONN_API_BASE_URL=http://api.elonn.local
```

Production:

```env
ELONN_API_BASE_URL=https://api.elonn.com
```

## Boundary Rules

- Time owns calendar data.
- API owns shared identity.
- Time validates bearer tokens only through API HTTP endpoints.
- Time must not connect directly to `elonn_api`.
- Do not add SabreDAV or CalDAV yet.
