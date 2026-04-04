# CLAUDE.md — Observatory API

This file provides full context for AI-assisted development of the `observatory-api` project.
Always read this file at the start of a session before writing any code.

---

## Project Overview

A REST API server built with **CodeIgniter 4 / PHP** running on cloud hosting.
It is the central persistence layer for the Observatory FITS Analysis Pipeline system.

- Owns the **MariaDB** database and its schema
- Consumed by:
  - `observatory-pipeline` (Python, runs on observatory server) — writes frames, sources, anomalies
  - Observatory website (future) — reads data for display
- Pipeline has **no direct DB access** — everything goes through this API
- Authentication: **API Key** via `X-API-Key` header for the pipeline

---

## Architecture Context

```
[Observatory Server]                    [Cloud Hosting — this repo]
┌─────────────────────────┐            ┌──────────────────────────┐
│  observatory-pipeline   │            │  CodeIgniter 4 API        │
│  (Python)               │  HTTPS +   │  ┌────────────────────┐  │
│                         │──API Key──▶│  │  REST endpoints    │  │
└─────────────────────────┘            │  └────────────────────┘  │
                                        │           │               │
                                        │  ┌────────▼───────────┐  │
                                        │  │  MariaDB           │  │
                                        │  └────────────────────┘  │
                                        └──────────────────────────┘
```

---

## Infrastructure

### Docker (local dev / production)

`docker-compose.yml` is already in the repo — it starts a **MariaDB 10.5.8** container:

```
container: observatory-api
host port: 3306
database:  db
user:      user
password:  password
root pw:   password
```

Start the database:
```bash
docker compose up -d
```

The CodeIgniter 4 application itself runs via a PHP web server (Apache/Nginx + PHP-FPM or
built-in `spark serve` for development). Add its service to docker-compose.yml when ready.

---

## API Base URL

```
/api/v1
```

All routes are prefixed with `/api/v1`.

---

## Authentication

Every request from the pipeline includes:

```
X-API-Key: <secret>
Content-Type: application/json
Accept: application/json
```

The API must validate the key on every request. Invalid key → `401 Unauthorized`.
The key is stored in `.env` / config, never hardcoded.

---

## Endpoints

### 1. POST /api/v1/frames

Register a newly processed FITS frame. Returns a `frame_id` used by subsequent calls.

**Request body:**
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
    "temp_setpoint_celsius": -10.0,
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

  "software": {
    "capture": "N.I.N.A. 2.1"
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

**Required fields:** `filename`, `obs_time`, `ra_center`, `dec_center`, `fov_deg`, `quality_flag`

**Response `201 Created`:**
```json
{ "id": "42", "message": "Frame registered successfully" }
```

**Errors:** `400` missing required fields, `401` invalid key, `422` validation failure

---

### 2. POST /api/v1/frames/{id}/sources

Save all detected sources for a previously registered frame.

**Request body:**
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

**Required:** `filename`, `sources` (empty array `[]` is valid).
Each source requires: `ra`, `dec`. All other source fields are optional (nullable).

**Response `201 Created`:**
```json
{ 
  "message": "Sources saved successfully", 
  "count": 287,
  "new_sources": 12,
  "matched_sources": 275
}
```

**Errors:** `400` missing fields, `401` invalid key, `404` frame not found

---

### 3. POST /api/v1/frames/{id}/anomalies

Save classified anomalies for a frame. Empty list is valid.

**Request body:**
```json
{
  "filename": "frame_20240315_220134.fits",
  "anomalies": [
    {
      "anomaly_type": "ASTEROID",
      "ra": 202.489,
      "dec": 47.201,
      "magnitude": 17.8,
      "delta_mag": null,
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

**Anomaly types:**
`VARIABLE_STAR`, `BINARY_STAR`, `ASTEROID`, `COMET`, `SUPERNOVA_CANDIDATE` (alert),
`MOVING_UNKNOWN` (alert), `SPACE_DEBRIS` (alert), `UNKNOWN` (alert)

**Response `201 Created`:**
```json
{ "message": "Anomalies saved successfully", "count": 4, "alerts": 2 }
```

The `alerts` count is the number of alert-worthy anomaly types:
`SUPERNOVA_CANDIDATE`, `MOVING_UNKNOWN`, `SPACE_DEBRIS`, `UNKNOWN`.

---

### 4. GET /api/v1/sources/near

Cone search for sources in the catalog near a sky position.

**Query parameters:**
- `ra` (float, required) — right ascension in decimal degrees
- `dec` (float, required) — declination in decimal degrees
- `radius_arcsec` (float, required) — search radius in arcseconds

**Implementation note:** Use bounding-box WHERE clause on indexed `(ra, dec)` columns for
speed, then filter precisely with the Haversine formula in PHP.

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

Returns `{"data": []}` when no sources found.

---

### 5. GET /api/v1/sources/{id}/observations

Get the observation history (light curve data) for a specific source.

**Query parameters (optional):**
- `from_time` (ISO 8601) — observations after this time
- `to_time` (ISO 8601) — observations before this time
- `limit` (int) — max observations to return (default 1000)

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
      "mag_err": 0.02,
      "flux": 44850.0,
      "fwhm": 3.1,
      "snr": 125.5
    }
  ]
}
```

**Errors:** `404` source not found

---

### 6. GET /api/v1/sources/{id}/frames

Get all frames that contain a specific source.

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

**Errors:** `404` source not found

---

### 7. GET /api/v1/frames/covering

Returns frames whose field of view covered a sky point, observed before a given time.

**Query parameters:**
- `ra` (float, required)
- `dec` (float, required)
- `before_time` (ISO 8601, required)

**Implementation note:** A frame covers a sky point if the angular distance from the frame
center `(ra_center, dec_center)` to the query point is less than `fov_deg / 2`.
Use bounding-box pre-filter then Haversine in PHP.

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

Returns `{"data": []}` when no prior coverage exists.

---

### 8. POST /api/v1/sources/near/batch

Batch cone search for sources near multiple sky positions.
Reduces API calls from O(N) to O(1) when processing frames with many sources.

**Request body:**
```json
{
  "positions": [
    {"ra": 202.461, "dec": 47.182},
    {"ra": 202.478, "dec": 47.201},
    {"ra": 202.490, "dec": 47.195}
  ],
  "radius_arcsec": 5.0,
  "before_time": "2024-03-15T22:01:34Z"
}
```

**Required fields:** `positions`, `radius_arcsec`
**Optional fields:** `before_time`

**Response `200 OK`:**
```json
{
  "results": {
    "0": [
      {
        "id": "6612f8a5e3b9c9.12345678",
        "ra": 202.4612,
        "dec": 47.1819,
        "catalog_name": "Gaia DR3",
        "object_type": "STAR",
        "observation_count": 15,
        "last_observed_at": "2024-03-14T21:55:12Z"
      }
    ],
    "1": [],
    "2": [...]
  }
}
```

**Errors:** `400` missing required fields, `401` invalid key

---

### 9. POST /api/v1/frames/covering/batch

Batch lookup for frames covering multiple sky positions.
Reduces API calls from O(N) to O(1).

**Request body:**
```json
{
  "positions": [
    {"ra": 202.461, "dec": 47.182},
    {"ra": 202.478, "dec": 47.201}
  ],
  "before_time": "2024-03-15T22:01:34Z"
}
```

**Required fields:** `positions`, `before_time`

**Response `200 OK`:**
```json
{
  "results": {
    "0": [
      {
        "id": "6612f7b2a1234.87654321",
        "filename": "frame_20240314_215512.fits",
        "obs_time": "2024-03-14T21:55:12Z",
        "ra_center": 202.470,
        "dec_center": 47.195,
        "fov_deg": 1.25
      }
    ],
    "1": [...]
  }
}
```

**Errors:** `400` missing required fields, `401` invalid key

---

### 10. GET /api/v1/stats/objects

Get a list of all observed objects with their aggregated statistics.

**Query parameters (optional):**
- `object` — partial match filter on object name

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

Returns `{"data": []}` when no statistics exist.

---

### 11. GET /api/v1/stats/objects/{object}

Get detailed statistics for a specific object, broken down by filter.

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

**Errors:** `404` object not found in statistics

---

## Database Schema

All tables use `CHAR(24)` primary keys with `uniqid('', true)` generated IDs (no auto_increment).

### Table: `frames`

| Column | Type | Notes |
|--------|------|-------|
| `id` | CHAR(24) PK | Generated by BaseModel |
| `filename` | VARCHAR(255) NOT NULL | FITS filename |
| `original_filepath` | VARCHAR(500) | Full path after archiving |
| `obs_time` | DATETIME NOT NULL | Observation start time UTC |
| `ra_center` | DOUBLE NOT NULL | Frame center RA (degrees) |
| `dec_center` | DOUBLE NOT NULL | Frame center Dec (degrees) |
| `fov_deg` | FLOAT NOT NULL | Field of view (degrees) |
| `quality_flag` | VARCHAR(20) DEFAULT 'OK' | Always 'OK' from pipeline |
| `object` | VARCHAR(100) | Target name |
| `exptime` | FLOAT | Exposure time (seconds) |
| `filter` | VARCHAR(50) | Filter name |
| `frame_type` | VARCHAR(20) | Light/Dark/Flat/Bias |
| `airmass` | FLOAT | Atmospheric airmass |
| `telescope` | VARCHAR(255) | Telescope name |
| `camera` | VARCHAR(255) | Camera name |
| `focal_length_mm` | INT | Focal length (mm) |
| `aperture_mm` | INT | Aperture (mm) |
| `sensor_temp` | FLOAT | Actual CCD temp (°C) |
| `sensor_temp_setpoint` | FLOAT | Target CCD temp (°C) |
| `binning_x` | TINYINT | H binning |
| `binning_y` | TINYINT | V binning |
| `gain` | INT | Camera gain |
| `offset` | INT | Camera offset |
| `width_px` | INT | Image width (px) |
| `height_px` | INT | Image height (px) |
| `observer_name` | VARCHAR(255) | Observer name |
| `site_name` | VARCHAR(255) | Site name |
| `site_lat` | DOUBLE | Site latitude (degrees) |
| `site_lon` | DOUBLE | Site longitude (degrees) |
| `site_elev_m` | INT | Site elevation (m) |
| `software_capture` | VARCHAR(255) | Capture software |
| `qc_fwhm_median` | FLOAT | Median FWHM (arcsec) |
| `qc_elongation` | FLOAT | Median elongation |
| `qc_snr_median` | FLOAT | Median SNR |
| `qc_sky_background` | FLOAT | Sky background (ADU) |
| `qc_star_count` | INT | Detected star count |
| `qc_eccentricity` | FLOAT | Median PSF eccentricity |
| `created_at` | DATETIME | Record creation time |

**Indexes:** `(ra_center, dec_center)`, `obs_time`, `filename`

---

### Table: `sources` (Source Catalog)

Master catalog of unique celestial sources. One record = one celestial object.

| Column | Type | Notes |
|--------|------|-------|
| `id` | CHAR(24) PK | Generated by BaseModel |
| `ra` | DOUBLE NOT NULL | Canonical RA (degrees) |
| `dec` | DOUBLE NOT NULL | Canonical Dec (degrees) |
| `catalog_name` | VARCHAR(50) | Gaia DR3 / Simbad / APASS / null |
| `catalog_id` | VARCHAR(255) | Catalog identifier |
| `catalog_mag` | FLOAT | Reference magnitude from catalog |
| `object_type` | VARCHAR(50) | STAR / GALAXY / V* / ASTEROID / etc. |
| `first_observed_at` | DATETIME | When first detected |
| `last_observed_at` | DATETIME | When last detected |
| `observation_count` | INT DEFAULT 0 | Number of observations |
| `created_at` | DATETIME | |

**Indexes:** `(ra, dec)` — critical for cone search, `catalog_name`, `object_type`

**Matching logic:** When saving sources, search within 2 arcsec radius. If found, reuse existing source.

---

### Table: `source_observations` (Photometry History)

Time-varying measurements of each source. Key table for light curves and variability analysis.

| Column | Type | Notes |
|--------|------|-------|
| `id` | CHAR(24) PK | Generated by BaseModel |
| `source_id` | CHAR(24) NOT NULL FK→sources.id | |
| `frame_id` | CHAR(24) NOT NULL FK→frames.id | |
| `ra` | DOUBLE NOT NULL | Measured RA (may differ slightly) |
| `dec` | DOUBLE NOT NULL | Measured Dec |
| `mag` | FLOAT | Calibrated magnitude |
| `mag_err` | FLOAT | Magnitude error |
| `flux` | FLOAT | Aperture flux (ADU) |
| `flux_err` | FLOAT | Flux error |
| `fwhm` | FLOAT | PSF FWHM (arcsec) |
| `snr` | FLOAT | Signal-to-noise ratio |
| `elongation` | FLOAT | PSF elongation |
| `obs_time` | DATETIME NOT NULL | Observation timestamp |
| `created_at` | DATETIME | |

**Indexes:** `source_id`, `frame_id`, `obs_time`, `(source_id, obs_time)`

---

### Table: `frame_sources` (Many-to-Many Link)

Quick lookup linking frames to sources.

| Column | Type | Notes |
|--------|------|-------|
| `id` | CHAR(24) PK | Generated by BaseModel |
| `frame_id` | CHAR(24) NOT NULL FK→frames.id | |
| `source_id` | CHAR(24) NOT NULL FK→sources.id | |
| `created_at` | DATETIME | |

**Indexes:** `frame_id`, `source_id`, UNIQUE `(frame_id, source_id)`

---

### Table: `anomalies`

| Column | Type | Notes |
|--------|------|-------|
| `id` | CHAR(24) PK | Generated by BaseModel |
| `frame_id` | CHAR(24) NOT NULL FK→frames.id | |
| `source_id` | CHAR(24) NULL FK→sources.id | Link to source if identified |
| `anomaly_type` | VARCHAR(30) NOT NULL | Classification type |
| `ra` | DOUBLE NOT NULL | |
| `dec` | DOUBLE NOT NULL | |
| `magnitude` | FLOAT | Observed magnitude |
| `delta_mag` | FLOAT | Magnitude change vs history |
| `mpc_designation` | VARCHAR(100) | MPC id for asteroids/comets |
| `ephemeris_predicted_ra` | DOUBLE | JPL predicted RA |
| `ephemeris_predicted_dec` | DOUBLE | JPL predicted Dec |
| `ephemeris_predicted_mag` | FLOAT | JPL predicted magnitude |
| `ephemeris_distance_au` | FLOAT | Distance (AU) |
| `ephemeris_angular_velocity` | FLOAT | Angular velocity (arcsec/hr) |
| `notes` | TEXT | Classification notes |
| `is_alert` | TINYINT(1) DEFAULT 0 | 1 for alert-worthy types |
| `created_at` | DATETIME | |

**Indexes:** `frame_id`, `source_id`, `anomaly_type`, `is_alert`, `(ra, dec)`

---

### Table: `object_stats` (Pre-aggregated Statistics)

Stores aggregated statistics per object+filter combination. Updated incrementally when frames are registered.

| Column | Type | Notes |
|--------|------|-------|
| `id` | CHAR(24) PK | Generated by BaseModel |
| `object` | VARCHAR(100) NOT NULL | Target name (M51, NGC 7000, etc.) |
| `filter` | VARCHAR(50) NULL | Filter name (L, R, Ha, NULL for unfiltered) |
| `frame_count` | INT DEFAULT 0 | Number of frames |
| `total_exposure_sec` | FLOAT DEFAULT 0 | Sum of all exptime values |
| `first_obs_time` | DATETIME | Earliest observation time |
| `last_obs_time` | DATETIME | Latest observation time |
| `avg_fwhm` | FLOAT | Average FWHM across frames |
| `avg_airmass` | FLOAT | Average airmass |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | Auto-updated on change |

**Indexes:** `object`, `filter`, `(object, filter)`

**Note:** Statistics are updated automatically when frames are registered via POST /api/v1/frames.
Use `php spark recalculate:object-stats` to rebuild statistics from scratch.

---

## Database Schema Diagram

```
┌─────────────────┐       ┌─────────────────────┐       ┌──────────────────┐
│     frames      │       │ source_observations │       │     sources      │
├─────────────────┤       ├─────────────────────┤       ├──────────────────┤
│ id (CHAR 24 PK) │◄──────│ frame_id (FK)       │       │ id (CHAR 24 PK)  │
│ filename        │       │ source_id (FK)      │──────►│ ra, dec          │
│ obs_time        │       │ ra, dec (measured)  │       │ catalog_name     │
│ ra_center       │       │ mag, flux, fwhm     │       │ catalog_id       │
│ dec_center      │       │ snr, elongation     │       │ object_type      │
│ fov_deg         │       │ obs_time            │       │ observation_count│
│ ...             │       └─────────────────────┘       │ first/last_obs   │
└─────────────────┘                                     └──────────────────┘
        │                                                        ▲
        │           ┌─────────────────┐                          │
        └──────────►│  frame_sources  │◄─────────────────────────┘
                    ├─────────────────┤
                    │ frame_id (FK)   │
                    │ source_id (FK)  │
                    └─────────────────┘

┌─────────────────┐
│    anomalies    │
├─────────────────┤
│ id (CHAR 24 PK) │
│ frame_id (FK)   │──────► frames
│ source_id (FK)  │──────► sources (optional)
│ anomaly_type    │
│ ra, dec         │
│ magnitude       │
│ is_alert        │
│ ...             │
└─────────────────┘
```

**Key relationships:**
- One **source** = one celestial object at fixed coordinates
- One **source_observation** = one measurement of a source in one frame
- One **frame** can contain many sources (via `frame_sources`)
- One **source** can appear in many frames (via `frame_sources`)
- **anomalies** are linked to frames, optionally to sources
- **object_stats** = pre-aggregated statistics per object+filter (updated on frame insert)

---

## Alert-worthy Anomaly Types

These types set `is_alert = 1`:
- `SUPERNOVA_CANDIDATE`
- `MOVING_UNKNOWN`
- `SPACE_DEBRIS`
- `UNKNOWN`

---

## Error Response Format

```json
{
  "error": "Human-readable error description",
  "details": {}
}
```

---

## Cone Search — Haversine Formula (PHP)

```php
// Haversine distance in arcseconds between two sky points
function haversine_arcsec(float $ra1, float $dec1, float $ra2, float $dec2): float
{
    $ra1  = deg2rad($ra1);  $dec1 = deg2rad($dec1);
    $ra2  = deg2rad($ra2);  $dec2 = deg2rad($dec2);
    $dra  = $ra2 - $ra1;
    $ddec = $dec2 - $dec1;
    $a    = sin($ddec/2)**2 + cos($dec1) * cos($dec2) * sin($dra/2)**2;
    return 2 * asin(sqrt($a)) * (180.0 / M_PI) * 3600.0;
}
```

Bounding box pre-filter (fast, uses index):
```sql
WHERE ra  BETWEEN :ra  - :deg AND :ra  + :deg
  AND dec BETWEEN :dec - :deg AND :dec + :deg
  AND obs_time < :before_time
```
Where `:deg = radius_arcsec / 3600.0`. Then apply Haversine in PHP to get precise distances.

---

## CodeIgniter 4 Conventions

- **PHP 8.1+**
- Namespace: `App\Controllers`, `App\Models`, etc.
- Routes: `app/Config/Routes.php`
- Environment: `.env` file at project root (copy from `env` template)
- Database config: `app/Config/Database.php` (reads from `.env`)
- Migrations: `app/Database/Migrations/`
- Run migrations: `php spark migrate`
- Dev server: `php spark serve`

---

## Related Repository

`observatory-pipeline` — Python pipeline that is the primary API consumer.
Full context in: `/Users/mik/Projects/observatory-pipeline/CLAUDE.md`
API contract defined in: `/Users/mik/Projects/observatory-pipeline/API.md`
