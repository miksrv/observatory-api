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

# API key for authenticating pipeline requests
API_KEY = your-secret-api-key-here
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

## Authentication

Every request from the pipeline must include:

```
X-API-Key: <secret>
Content-Type: application/json
Accept: application/json
```

Invalid or missing key → `401 Unauthorized`.

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
| `POST` | `/sources/charts/batch` | Batch version of `.../chart` for multiple sources |
| `GET` | `/sources/{id}/chart.png` | Fetch a source's stored finder-chart PNG |

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

All tables use `CHAR(24)` primary keys generated via `uniqid('', true)` (no auto-increment).

| Table | Purpose |
|-------|---------|
| `frames` | Metadata for each FITS frame |
| `sources` | Catalog of unique celestial objects (matched by catalog identity, not position) |
| `source_observations` | Photometric measurements per epoch (light curves + per-epoch positions) |
| `frame_sources` | Many-to-many link between frames and sources |
| `anomalies` | Classified anomalies per frame |
| `object_stats` | Pre-aggregated statistics per object/filter, updated on frame insert |
| `source_charts` | Current finder-chart PNG metadata per source (image bytes live on disk) |

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
php spark test
```

---

## Tests

Feature tests are located in `tests/Feature/`. Every endpoint has test coverage.

```bash
php spark test
```

---

## Related Repositories

- [`observatory-pipeline`](../observatory-pipeline) — Python pipeline, the primary API client
