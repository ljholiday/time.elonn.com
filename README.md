# Elonn Time

`time.elonn.local` is the standalone calendar service for Elonn. Production is `time.elonn.com`.

Time owns calendar and event data. It validates identity through the shared API and must not connect directly to the `elonn_api` database.

## Role in the stack

Consumption order:

1. User authenticates through `elonn.local`
2. `api.elonn` issues and validates the shared auth token
3. `world.elonn` composes Time into the runtime contract
4. `web.elonn` renders the Time carry panel
5. Time validates identity by calling `api.elonn`

Time is a product service, not a login authority.

## Routes

Public/runtime:

```text
GET /health
GET /ready
GET /
GET /calendars
GET /calendars/new
POST /calendars
GET /events
GET /events/new
POST /events
GET /runtime/panel/time
POST /runtime/calendars
```

Protected JSON endpoints:

```text
GET /calendars
POST /calendars
GET /calendars/{id}
PATCH /calendars/{id}
DELETE /calendars/{id}
GET /events
POST /events
GET /events/{id}
PATCH /events/{id}
DELETE /events/{id}
```

Browser requests without a valid shared auth cookie redirect to `elonn.local/account/login`.

## Configuration

Create `.env` from `.env.example` and keep it uncommitted.

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

`public/index.php` loads `vendor/autoload.php`, calls `Dotenv::createImmutable(BASE_PATH)->safeLoad()`, then loads `config/config.php`. `config/config.php` is the only place that reads deployment environment values and returns normalized config arrays.

## Migrations

Run migrations with:

```bash
php scripts/migrate.php
php scripts/migrate.php status
```

Do not edit an applied migration. Add a new SQL file under `migrations/` when schema or seed behavior changes.

## Database

Local database:

```text
elonn_time
```

Production shared hosting may use a prefixed database name such as `ljholida_elonn_time`.

## Deployment

Dependencies are committed for shared hosting. Production does not need to run Composer.

## Verification

```bash
find public src templates scripts config -name '*.php' -print0 | xargs -0 -n1 php -l
```

Local:

```text
http://time.elonn.local/
http://time.elonn.local/ready
```

Production:

```text
https://time.elonn.com/
https://time.elonn.com/ready
```

## Related repos

- `elonn.local`: browser account surface
- `api.elonn.local`: shared identity authority
- `web.elonn.local`: browser runtime
- `world.elonn.local`: composition layer
- `maps.elonn.local`: canonical field dataset
- `social.elonn.local`: social object service
- `admin.elonn.local`: operator console
