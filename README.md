# Observatory API

REST API server — the central persistence layer for the [Observatory FITS Analysis Pipeline](../observatory-pipeline).

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
                                       │           │              │
                                       │  ┌────────▼───────────┐  │
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
| `POST` | `/sources/near/batch` | Batch cone search |
| `GET` | `/sources/{id}/observations` | Observation history for a source |
| `GET` | `/sources/{id}/frames` | Frames containing a source |

### Statistics

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/stats/objects` | List all objects with statistics |
| `GET` | `/stats/objects/{object}` | Detailed statistics for an object |

---

## Endpoint Reference

### POST /api/v1/frames

Register a processed FITS frame. Returns a `frame_id` used by subsequent calls.

**Required fields:** `filename`, `obs_time`, `ra_center`, `dec_center`, `fov_deg`, `quality_flag`

Registering a frame automatically updates the `object_stats` table.

<details>
<summary>Request example</summary>

```json
{
  "filename": "frame_20240315_220134.fits",
  "original_filepath": "/fits/archive/M51/frame_20240315_220134.fits",
  "obs_time": "2024-03-15T22:01:34Z",
  "ra_center": 202.4696,
  "dec_center": 47.1952,
  "fov_deg": 1.25,
  "quality_flag": "OK",

  "observation": {
    "object": "M51",
    "exptime": 120.0,
    "filter": "V",
    "frame_type": "Light",
    "airmass": 1.23
  },
  "instrument": {
    "telescope": "Celestron EdgeHD 11",
    "camera": "ZWO ASI2600MM Pro",
    "focal_length_mm": 2800,
    "aperture_mm": 280
  },
  "sensor": {
    "temp_celsius": -10.0,
    "binning_x": 1,
    "binning_y": 1,
    "gain": 100,
    "offset": 50,
    "width_px": 6248,
    "height_px": 4176
  },
  "observer": {
    "name": "John Smith",
    "site_name": "Backyard Observatory",
    "site_lat": 55.7558,
    "site_lon": 37.6173,
    "site_elev_m": 150
  },
  "qc": {
    "fwhm_median": 3.2,
    "elongation": 1.1,
    "snr_median": 42.5,
    "sky_background": 850.3,
    "star_count": 287,
    "eccentricity": 0.4
  }
}
```
</details>

**Response `201 Created`:**
```json
{ "id": "42", "message": "Frame registered successfully" }
```

---

### POST /api/v1/frames/{id}/sources

Save all detected sources for a previously registered frame.

**Required fields:** `filename`, `sources` (empty array `[]` is valid).  
Each source requires: `ra`, `dec`. All other source fields are optional.

**Matching logic:** a search within a 2 arcsec radius is performed. If a match is found, the existing source is reused; otherwise a new source record is created.

<details>
<summary>Request example</summary>

```json
{
  "filename": "frame_20240315_220134.fits",
  "sources": [
    {
      "ra": 202.461,
      "dec": 47.182,
      "mag": 14.23,
      "flux": 45230.5,
      "fwhm": 3.1,
      "catalog_name": "Gaia DR3",
      "catalog_id": "Gaia DR3 1234567890123456789",
      "catalog_mag": 14.15,
      "object_type": "STAR"
    }
  ]
}
```
</details>

**Response `201 Created`:**
```json
{
  "message": "Sources saved successfully",
  "count": 287,
  "new_sources": 12,
  "matched_sources": 275
}
```

---

### POST /api/v1/frames/{id}/anomalies

Save classified anomalies for a frame. An empty list is valid.

**Anomaly types:**  
`VARIABLE_STAR`, `BINARY_STAR`, `ASTEROID`, `COMET`,  
`SUPERNOVA_CANDIDATE` ⚠️, `MOVING_UNKNOWN` ⚠️, `SPACE_DEBRIS` ⚠️, `UNKNOWN` ⚠️

Types marked ⚠️ are alert-worthy (`is_alert = 1`).

<details>
<summary>Request example</summary>

```json
{
  "filename": "frame_20240315_220134.fits",
  "anomalies": [
    {
      "anomaly_type": "ASTEROID",
      "ra": 202.489,
      "dec": 47.201,
      "magnitude": 17.8,
      "mpc_designation": "2019 XY3",
      "ephemeris": {
        "predicted_ra": 202.491,
        "predicted_dec": 47.200,
        "predicted_mag": 17.9,
        "distance_au": 1.23,
        "angular_velocity_arcsec_per_hour": 45.2
      },
      "notes": "Matched MPC object within 3.2 arcsec"
    }
  ]
}
```
</details>

**Response `201 Created`:**
```json
{ "message": "Anomalies saved successfully", "count": 4, "alerts": 2 }
```

---

### GET /api/v1/sources/near

Cone search for sources in the catalog near a sky position.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `ra` | float | yes | Right ascension (degrees) |
| `dec` | float | yes | Declination (degrees) |
| `radius_arcsec` | float | yes | Search radius (arcseconds) |

**Response `200 OK`:**
```json
{
  "data": [
    {
      "id": "6612f8a5e3b9c9.12345678",
      "ra": 202.4612,
      "dec": 47.1819,
      "catalog_name": "Gaia DR3",
      "catalog_id": "Gaia DR3 1234567890123456789",
      "object_type": "STAR",
      "observation_count": 15,
      "last_observed_at": "2024-03-14T21:55:12Z"
    }
  ]
}
```

---

### POST /api/v1/sources/near/batch

Batch cone search for multiple positions — reduces O(N) API calls to one.

**Required fields:** `positions`, `radius_arcsec`

```json
{
  "positions": [
    {"ra": 202.461, "dec": 47.182},
    {"ra": 202.478, "dec": 47.201}
  ],
  "radius_arcsec": 5.0,
  "before_time": "2024-03-15T22:01:34Z"
}
```

**Response** — object keyed by position index:
```json
{
  "results": {
    "0": [ { "id": "...", "ra": 202.4612, "dec": 47.1819, "..." : "..." } ],
    "1": []
  }
}
```

---

### GET /api/v1/sources/{id}/observations

Observation history (light curve data) for a specific source.

| Parameter | Type | Description |
|-----------|------|-------------|
| `from_time` | ISO 8601 | Observations after this time |
| `to_time` | ISO 8601 | Observations before this time |
| `limit` | int | Max records to return (default 1000) |

**Response `200 OK`:**
```json
{
  "source": {
    "id": "6612f8a5e3b9c9.12345678",
    "ra": 202.4612,
    "dec": 47.1819,
    "catalog_name": "Gaia DR3",
    "object_type": "STAR"
  },
  "observations": [
    {
      "frame_id": "6612f7b2a1234.87654321",
      "obs_time": "2024-03-14T21:55:12Z",
      "mag": 14.21,
      "flux": 44850.0,
      "fwhm": 3.1,
      "snr": 125.5
    }
  ]
}
```

---

### GET /api/v1/sources/{id}/frames

All frames that contain a specific source.

**Response `200 OK`:**
```json
{
  "source_id": "6612f8a5e3b9c9.12345678",
  "data": [
    {
      "frame_id": "6612f7b2a1234.87654321",
      "filename": "frame_20240314_215512.fits",
      "obs_time": "2024-03-14T21:55:12Z",
      "ra_center": 202.470,
      "dec_center": 47.195
    }
  ]
}
```

---

### GET /api/v1/frames/covering

Returns frames whose field of view covered a sky point before a given time.  
A frame covers a point if the angular distance from the frame center to the point is less than `fov_deg / 2`.

| Parameter | Type | Required |
|-----------|------|----------|
| `ra` | float | yes |
| `dec` | float | yes |
| `before_time` | ISO 8601 | yes |

**Response `200 OK`:**
```json
{
  "data": [
    {
      "id": "38",
      "filename": "frame_20240314_215512.fits",
      "obs_time": "2024-03-14T21:55:12Z",
      "ra_center": 202.470,
      "dec_center": 47.195,
      "fov_deg": 1.25
    }
  ]
}
```

---

### POST /api/v1/frames/covering/batch

Batch lookup for frames covering multiple sky positions.

**Required fields:** `positions`, `before_time`

```json
{
  "positions": [
    {"ra": 202.461, "dec": 47.182},
    {"ra": 202.478, "dec": 47.201}
  ],
  "before_time": "2024-03-15T22:01:34Z"
}
```

---

### GET /api/v1/stats/objects

List all observed objects with aggregated statistics.

| Parameter | Description |
|-----------|-------------|
| `object` | Partial name filter |

**Response `200 OK`:**
```json
{
  "data": [
    {
      "object": "M51",
      "total_frames": 150,
      "total_exposure_sec": 18000.0,
      "total_exposure_hours": 5.0,
      "filters": ["L", "R", "G", "B", "Ha"],
      "first_obs_time": "2024-01-15T20:30:00Z",
      "last_obs_time": "2024-03-15T22:01:34Z"
    }
  ]
}
```

---

### GET /api/v1/stats/objects/{object}

Detailed statistics for a specific object, broken down by filter.

**Response `200 OK`:**
```json
{
  "object": "M51",
  "summary": {
    "total_frames": 150,
    "total_exposure_sec": 18000.0,
    "total_exposure_hours": 5.0,
    "first_obs_time": "2024-01-15T20:30:00Z",
    "last_obs_time": "2024-03-15T22:01:34Z"
  },
  "by_filter": [
    {
      "filter": "L",
      "frame_count": 50,
      "total_exposure_sec": 6000.0,
      "avg_fwhm": 2.8,
      "avg_airmass": 1.15,
      "first_obs_time": "2024-01-15T20:30:00Z",
      "last_obs_time": "2024-03-15T22:01:34Z"
    }
  ]
}
```

---

## Error Format

```json
{
  "error": "Human-readable error description",
  "details": {}
}
```

| Code | Reason |
|------|--------|
| `400` | Missing required fields |
| `401` | Invalid or missing API key |
| `404` | Resource not found |
| `422` | Validation failure |

---

## Database Schema

All tables use `CHAR(24)` primary keys generated via `uniqid('', true)` (no auto-increment).

```
┌─────────────────┐       ┌─────────────────────┐       ┌──────────────────┐
│     frames      │       │ source_observations │       │     sources      │
├─────────────────┤       ├─────────────────────┤       ├──────────────────┤
│ id (CHAR 24 PK) │◄──────│ frame_id (FK)       │       │ id (CHAR 24 PK)  │
│ filename        │       │ source_id (FK)      │──────►│ ra, dec          │
│ obs_time        │       │ mag, flux, fwhm     │       │ catalog_name     │
│ ra_center       │       │ snr, elongation     │       │ catalog_id       │
│ dec_center      │       │ obs_time            │       │ object_type      │
│ fov_deg         │       └─────────────────────┘       │ observation_count│
│ object, filter  │                                     └──────────────────┘
│ exptime, ...    │                                              ▲
└─────────────────┘                                             │
        │               ┌─────────────────┐                    │
        └──────────────►│  frame_sources  │◄───────────────────┘
                        ├─────────────────┤
                        │ frame_id (FK)   │
                        │ source_id (FK)  │
                        └─────────────────┘

┌─────────────────┐       ┌─────────────────┐
│    anomalies    │       │  object_stats   │
├─────────────────┤       ├─────────────────┤
│ frame_id (FK)   │       │ object          │
│ source_id (FK)  │       │ filter          │
│ anomaly_type    │       │ frame_count     │
│ ra, dec         │       │ total_exposure  │
│ is_alert        │       │ avg_fwhm        │
└─────────────────┘       └─────────────────┘
```

### Tables

| Table | Purpose |
|-------|---------|
| `frames` | Metadata for each FITS frame |
| `sources` | Catalog of unique celestial objects |
| `source_observations` | Photometric measurements (light curves) |
| `frame_sources` | Many-to-many link between frames and sources |
| `anomalies` | Classified anomalies per frame |
| `object_stats` | Pre-aggregated statistics per object/filter, updated on frame insert |

---

## CLI Commands

```bash
# Apply database migrations
php spark migrate

# Rebuild object statistics from scratch
php spark recalculate:object-stats

# Start the dev server
php spark serve

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
