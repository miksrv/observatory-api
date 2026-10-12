# Observatory API

REST API server — the central persistence layer for the [Observatory FITS Analysis Pipeline](https://github.com/miksrv/observatory-pipeline).

Built with **CodeIgniter 4 / PHP**, database — **MariaDB**.

---

## Architecture

```
[Observatory Server]                    [Cloud Hosting — this repo]
┌─────────────────────────┐            ┌──────────────────────────┐
│  observatory-pipeline   │            │  CodeIgniter 4 API       │
│  (Python)               │  HTTPS +   │  ┌────────────────────┐  │
│                         │──API Key──▶│  │  REST endpoints    │  │
└─────────────────────────┘            │  └────────────────────┘  │
                                       │             │            │
                                       │  ┌──────────▼─────────┐  │
                                       │  │  MariaDB           │  │
                                       │  └────────────────────┘  │
                                       └──────────────────────────┘
```

- The pipeline (`observatory-pipeline`) is the sole API client and has no direct database access
- The API owns the MariaDB schema and manages all data
- An Observatory Website (planned) will read data through this same API

---

## Requirements

- **PHP 8.2+** with extensions: `intl`, `mbstring`, `json`, `mysqlnd`
- **Composer**
- **Docker + Docker Compose** (for MariaDB)

---

## Installation & Setup

### 1. Clone the repository and install dependencies

```bash
git clone <repo-url>
cd observatory-api
composer install
```

### 2. Configure the environment

```bash
cp env .env
```

Edit `.env`:

```ini
CI_ENVIRONMENT = development

app.baseURL = 'http://localhost:8080'

database.default.hostname = localhost
database.default.database = db
database.default.username = user
database.default.password = password
database.default.DBDriver = MySQLi
database.default.port     = 3306

# Initial pipeline API key. `php spark migrate` registers it once as pipeline `default`;
# after that keys are managed with `php spark pipeline:*` (see Authentication) and this value
# is no longer read.
app.apiKey = your-secret-api-key-here
```

### 3. Start the database (Docker)

```bash
docker compose up -d
```

This starts a MariaDB 10.5.8 container with the following settings:

| Parameter | Value    |
|-----------|----------|
| Host port | 3306     |
| Database  | db       |
| User      | user     |
| Password  | password |
| Root pw   | password |

`docker-compose.yml` currently only defines the MariaDB service — the CodeIgniter app itself runs
via `spark serve` below rather than its own container. Add an app service to `docker-compose.yml`
when one is needed (e.g. for a production Apache/Nginx + PHP-FPM setup).

### 4. Run migrations

```bash
php spark migrate
```

### 5. Start the dev server

```bash
php spark serve
```

The API will be available at: `http://localhost:8080/api/v1`

---

## Production Deployment

Production runs on shared hosting at **https://observatory.miksoft.pro**, deployed by GitHub
Actions (`.github/workflows/deploy.yml`) on every push to `main` that touches the app (docs,
tests and Docker files are ignored), or by hand from the Actions tab (*Run workflow*;
its *debug* option logs the full transfer dialog).

The workflow installs Composer dependencies without dev packages and uploads over FTP (TLS) into the
root of a dedicated hosting account (jino), which is the site's document root. The root `.htaccess` routes
every request into `public/`, so `app/`, `vendor/`, `writable/` and `.env` are never served.

| Path on the hosting | On each deploy |
|---|---|
| `app/`, `vendor/` | mirrored, stale files deleted |
| `public/`, `writable/` | uploaded, nothing deleted (`writable/uploads` holds the pipeline's charts) |
| `.htaccess`, `spark` | overwritten |
| `.env` | **never touched** — maintained by hand on the hosting |

Repository settings (*Settings → Secrets and variables → Actions*):

| Name | Kind | Value |
|---|---|---|
| `FTP_HOSTNAME` | secret | the bare FTP host — not `sftp://…:2222`: that port is unreachable from GitHub's runners |
| `FTP_USERNAME` | secret | the account whose root is the site's document root |
| `FTP_PASSWORD` | secret | its password |
| `APP_URL` | variable | `https://observatory.miksoft.pro` — enables the post-deploy smoke test of `GET /` |

**First deployment:** after the first run, create the database and `.env` on the hosting
(`CI_ENVIRONMENT = production`, `app.baseURL`, `database.default.*`, and `app.apiKey` — the
migrations register that key as pipeline `default`), then run the migrations. The workflow
does not run migrations: the hosting has no shell, so run them by hand after every release that
adds one (its `CHANGELOG.md` line says so).

## Authentication

Every request from the pipeline must include:

```
X-API-Key: <secret>
Content-Type: application/json
Accept: application/json
```

Invalid or missing key → `401 Unauthorized`.

Every pipeline (one per observatory server, each keeping its FITS files locally) has its **own**
key, which is also how the API knows who is calling: frames are recorded as owned by the pipeline
that registered them, and tasks for them are claimable only by that pipeline. Keys are stored only
as SHA-256 hashes and managed on the API host:

```bash
php spark pipeline:create observatory-b   # prints the key once → that server's API_KEY
php spark pipeline:list                   # status, last seen, frames, queue
php spark pipeline:rotate <name>          # replace a key (old one stops working)
php spark pipeline:revoke <name>          # disable a server
php spark pipeline:rename <name> <new>
```

In Docker, prefix with `docker compose exec <api-service>`. The debug UI's dashboard (`/ui`) lists
every pipeline with its online status, current task and queue. See
[`docs/API.md`](docs/API.md) → Authentication.

---

## Endpoints

Base URL: `/api/v1`

### Frames

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/frames` | Register a new FITS frame |
| `POST` | `/frames/{id}/sources` | Save sources for a frame |
| `POST` | `/frames/{id}/anomalies` | Save anomalies for a frame |
| `GET` | `/frames/covering` | Frames covering a sky point |
| `POST` | `/frames/covering/batch` | Batch version for multiple positions |

### Sources

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/sources/near` | Cone search for sources |
| `POST` | `/sources/near/batch` | Batch cone search over historical observations |
| `GET` | `/sources/{id}/observations` | Observation history for a source |
| `GET` | `/sources/{id}/frames` | Frames containing a source |
| `GET` | `/sources/{id}/track` | Per-epoch position track for a source's finder chart |
| `POST` | `/sources/tracks/batch` | Batch version of `.../track` for multiple sources |
| `POST` | `/sources/{id}/chart` | Upload/replace a source's finder-chart PNG |
| `GET` | `/sources/{id}/chart.png` | Fetch a source's stored finder-chart PNG |

### Settings

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/settings` | Fetch all pipeline configuration parameters |

### Statistics

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/stats/objects` | List all objects with statistics |
| `GET` | `/stats/objects/{object}` | Detailed statistics for an object |

Full request/response reference (with examples) for every endpoint above, plus error codes and
the anomaly-type table, lives in **[`docs/API.md`](docs/API.md)** — that file is the single
source of truth for the API contract.

---

## Database Schema

All tables use `CHAR(24)` primary keys generated via `uniqid('', true)` (no auto-increment),
except `settings` which uses a plain auto-increment `INT` PK.

| Table | Purpose |
|-------|---------|
| `frames` | Metadata for each FITS frame |
| `sources` | Catalog of unique celestial objects (matched by catalog identity, not position) |
| `source_observations` | Photometric measurements per epoch (light curves + per-epoch positions) |
| `frame_sources` | Many-to-many link between frames and sources |
| `anomalies` | Classified anomalies per frame |
| `object_stats` | Pre-aggregated statistics per object/filter, updated on frame insert |
| `source_charts` | Current finder-chart PNG metadata per source (image bytes live on disk) |
| `settings` | Pipeline configuration parameters (key-value pairs, seeded with defaults) |

Full column-by-column reference, indexes, foreign keys, and the ER diagram live in
**[`docs/DATABASE.md`](docs/DATABASE.md)** — the single source of truth for the schema.

---

## CLI Commands

```bash
# Apply database migrations
php spark migrate

# Rebuild object statistics from scratch
php spark recalculate:object-stats

# Start the dev server
php spark serve
php spark serve --host 0.0.0.0

# Run tests
vendor/bin/phpunit
```

---

## Tests

Feature tests are located in `tests/Feature/`. Every endpoint has test coverage.

The suite runs against a **separate, throwaway database** — the feature tests empty every
application table in `setUp()`, so they must never point at the working `db`. The test
connection is the `tests` group (`database.tests.*` in `.env`, `db_test` by default), and
`Tests\Support\DatabaseTestCase` refuses to run unless that database's name ends in `_test`.
Create and migrate it once (the MariaDB container only creates `db`):

```bash
docker compose exec observatory-api sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" \
  -e "CREATE DATABASE db_test CHARACTER SET utf8mb4; GRANT ALL PRIVILEGES ON db_test.* TO \"user\"@\"%\";"'
php spark migrate -g tests
vendor/bin/phpunit
```

After adding a migration, run `php spark migrate -g tests` again so `db_test` keeps up.

---

## Related Repositories

- [`observatory-pipeline`](../observatory-pipeline) — Python pipeline, the primary API client
