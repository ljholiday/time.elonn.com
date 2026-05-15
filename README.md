# Elonn Time

`time.elonn.local` is the standalone calendar/time product service for Elonn.

Production is `time.elonn.com`.

Time owns its product database and validates identity by calling the shared API identity service. It must not connect directly to the `elonn_api` database.

## Dependencies

CalDAV support will use SabreDAV:

```text
sabre/dav
```

Dependencies are managed with Composer locally, but production shared hosting does not need Composer installed.

Committed dependency files:

```text
composer.json
composer.lock
```

Ignored generated dependency directory:

```text
vendor/
```

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

### Protected JSON Endpoints

JSON calendar and event endpoints require:

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

## Browser Frontend

The service includes a minimal server-rendered frontend using plain PHP templates and shared CSS from:

```text
public/assets/css/time.css
```

Browser-facing routes:

- `GET /`
- `GET /calendars`
- `GET /calendars/new`
- `POST /calendars`
- `GET /events`
- `GET /events/new`
- `POST /events`

Templates live under:

```text
templates/layout.php
templates/home.php
templates/calendars/
templates/events/
```

Time does not implement login or registration. Browser requests without a valid API auth cookie are redirected to the Elonn account surface:

```text
http://elonn.local/account/login
```

Production redirects to:

```text
https://elonn.com/account/login
```

If the shared `elonn_api_token` cookie is available to `time.elonn.local` or `time.elonn.com`, Time validates it through `GET /identity/me` before rendering pages.

## CalDAV Access

The planned Thunderbird and CalDAV client URL is:

```text
https://time.elonn.com/dav/
```

Local development:

```text
http://time.elonn.local/dav/
```

CalDAV clients cannot rely on the browser shared cookie. DAV access uses HTTP Basic Auth:

```text
username: Elonn account email or username
password: generated Elonn DAV token
```

The DAV token is owned by the API identity service. Time receives Basic Auth credentials from the DAV client and validates them by calling:

```text
POST https://api.elonn.com/identity/dav/validate
```

Local development:

```text
POST http://api.elonn.local/identity/dav/validate
```

On success, Time uses the returned `id` to scope calendars, events, and future tasks. On failure, Time returns `401` with a `WWW-Authenticate: Basic` challenge.

Current DAV status:

- `/dav/` route exists.
- Basic Auth challenge exists.
- Time validates DAV credentials through API.
- Full CalDAV collections, events, tasks, sync, and Thunderbird discovery are not implemented yet.

## Database

The local database is:

```text
elonn_time
```

The current production database on shared hosting is:

```text
ljholida_elonn_time
```

Use the database name that exists on the target host in `.env`.

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

Create `.env` from `.env.example`.

Development:

```env
APP_ENV=local
APP_URL=https://time.elonn.local
DB_DATABASE=elonn_time
DB_USERNAME=elonn_time
ELONN_API_BASE_URL=https://api.elonn.local
```

Production:

```env
APP_ENV=production
APP_URL=https://time.elonn.com
ELONN_API_BASE_URL=https://api.elonn.com
```

Production database values should match the hosting account, for example:

```env
DB_DATABASE=ljholida_elonn_time
```

`public/index.php` and `scripts/migrate.php` load `vendor/autoload.php` and `vlucas/phpdotenv`. `config/config.php` is the only layer that reads deployment environment values and returns normalized config arrays to the application.

## Boundary Rules

- Time owns calendar data.
- API owns shared identity.
- Time validates bearer tokens only through API HTTP endpoints.
- Time validates DAV Basic Auth credentials only through API HTTP endpoints.
- Time must not connect directly to `elonn_api`.
- Time must not implement its own login or registration.
- CalDAV should be implemented as an additional protocol surface without replacing existing browser or JSON routes.
- Thunderbird-facing authentication must preserve the identity boundary and validate through the API, not by reading `elonn_api`.

## Deployment

Production shared hosting does not run Composer.

Build dependencies locally before deploying:

```bash
composer install --no-dev --optimize-autoloader
```

The release artifact or upload must include:

```text
vendor/
composer.json
composer.lock
```

Do not commit `vendor/`. It is generated locally and deployed with the release artifact.

## Verification

Run migrations:

```bash
php scripts/migrate.php
php scripts/migrate.php status
```

Run PHP syntax checks:

```bash
find public src templates -name '*.php' -print0 | xargs -0 -n1 php -l
```

Local URLs:

```text
http://time.elonn.local/health
http://time.elonn.local/ready
http://time.elonn.local/
http://time.elonn.local/calendars
http://time.elonn.local/events
```

Production URLs:

```text
https://time.elonn.com/health
https://time.elonn.com/ready
https://time.elonn.com/
https://time.elonn.com/calendars
https://time.elonn.com/events
https://time.elonn.com/dav/
```
