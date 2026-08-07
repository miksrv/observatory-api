# Observatory API — Database Schema

Single source of truth for the database schema: every table, column, index, and foreign key.
Migrations in `app/Database/Migrations/` are the actual ground truth — this file summarizes them
for quick reference and should be kept in sync whenever a migration changes.

All tables use `CHAR(24)` primary keys generated via `uniqid('', true)` (no auto-increment).

For the API surface built on top of this schema, see [`API.md`](API.md). For project setup and
CLI commands, see [`../README.md`](../README.md).

---

## Diagram

```
┌─────────────────┐       ┌─────────────────────┐       ┌──────────────────┐
│     frames      │       │ source_observations │       │     sources      │
├─────────────────┤       ├─────────────────────┤       ├──────────────────┤
│ id (CHAR 24 PK) │◄──────│ frame_id (FK)       │       │ id (CHAR 24 PK)  │
│ filename        │       │ source_id (FK)      │──────►│ catalog_name     │
│ obs_time        │       │ ra, dec (measured)  │       │ catalog_id       │
│ ra_center       │       │ mag, flux, fwhm     │       │ catalog_mag      │
│ dec_center      │       │ snr, elongation     │       │ object_type      │
│ fov_deg         │       │ obs_time            │       │ observation_count│
│ object, filter  │       └─────────────────────┘       │ first/last_obs   │
│ exptime, ...    │                                     └──────────────────┘
└─────────────────┘                                              ▲
        │               ┌─────────────────┐                     │
        └──────────────►│  frame_sources  │◄────────────────────┘
                        ├─────────────────┤
                        │ frame_id (FK)   │
                        │ source_id (FK)  │
                        └─────────────────┘

┌─────────────────┐       ┌─────────────────┐       ┌──────────────────┐
│    anomalies    │       │  object_stats   │       │  source_charts   │
├─────────────────┤       ├─────────────────┤       ├──────────────────┤
│ frame_id (FK)   │       │ object          │       │ source_id (FK)   │──► sources (unique, cascade)
│ source_id (FK)  │       │ filter          │       │ style            │
│ anomaly_type    │       │ frame_count     │       │ frame_count      │
│ ra, dec         │       │ total_exposure  │       │ updated_at       │
│ is_alert        │       │ avg_fwhm        │       └──────────────────┘
└─────────────────┘       └─────────────────┘
```

**Key relationships:**
- One **source** = one celestial object, identified primarily by stable catalog identity
  (`catalog_name` + `catalog_id`). `sources` intentionally has **no `ra`/`dec` of its own** — a
  fixed position doesn't make sense for a catalog that also holds moving objects (MPC-matched
  asteroids/comets). Per-epoch positions live in `source_observations`; `GET /sources/near` and
  `GET /sources/{id}/observations` resolve a source's "current" position from its nearest/latest
  observation row.
- One **source_observation** = one measurement of a source in one frame (the light-curve table).
- One **frame** can contain many sources and vice versa, via `frame_sources`.
- **anomalies** link to a frame (cascade delete) and optionally to a source (`SET NULL` on
  delete — an anomaly's detection history outlives the source being removed from the catalog).
- One **source_charts** row = the current finder-chart PNG for one source (1:1, cascade-deleted
  with its source); the image bytes live on disk at `writable/uploads/charts/{source_id}.png`,
  not in this table.
- **object_stats** = pre-aggregated statistics per object+filter, updated incrementally whenever
  a frame is registered (`POST /frames`).

---

## Tables

### `frames`

| Column | Type | Notes |
|--------|------|-------|
| `id` | CHAR(24) PK | |
| `filename` | VARCHAR(255) NOT NULL | |
| `original_filepath` | VARCHAR(500) | Full path after archiving |
| `obs_time` | DATETIME NOT NULL | Observation start time UTC |
| `ra_center`, `dec_center` | DOUBLE NOT NULL | Frame center (degrees) |
| `fov_deg` | FLOAT NOT NULL | Field of view (degrees) |
| `quality_flag` | VARCHAR(20) DEFAULT 'OK' | |
| `object`, `filter`, `frame_type` | VARCHAR | Target name / filter name / Light-Dark-Flat-Bias |
| `exptime`, `airmass` | FLOAT | Exposure time (s) / atmospheric airmass |
| `telescope`, `camera` | VARCHAR(255) | |
| `focal_length_mm`, `aperture_mm` | INT | |
| `sensor_temp`, `sensor_temp_setpoint` | FLOAT | Actual / target CCD temp (°C) |
| `binning_x`, `binning_y` | TINYINT | |
| `gain`, `offset`, `width_px`, `height_px` | INT | |
| `observer_name`, `site_name` | VARCHAR(255) | |
| `site_lat`, `site_lon` | DOUBLE | |
| `site_elev_m` | INT | |
| `software_capture` | VARCHAR(255) | Capture software |
| `qc_fwhm_median`, `qc_elongation`, `qc_snr_median`, `qc_sky_background`, `qc_eccentricity` | FLOAT | |
| `qc_star_count` | INT | |
| `created_at` | DATETIME | |

**Indexes:** `(ra_center, dec_center)`, `obs_time`, `filename`

### `sources` (Source Catalog)

| Column | Type | Notes |
|--------|------|-------|
| `id` | CHAR(24) PK | |
| `catalog_name` | VARCHAR(50) NULL | Gaia DR3 / Simbad / MPC / APASS / null |
| `catalog_id` | VARCHAR(255) NULL | Catalog's own identifier/designation |
| `catalog_mag` | FLOAT NULL | Reference magnitude from catalog |
| `object_type` | VARCHAR(50) NULL | STAR / GALAXY / V* / ASTEROID / etc. |
| `first_observed_at`, `last_observed_at` | DATETIME NULL | |
| `observation_count` | INT DEFAULT 0 | |
| `created_at` | DATETIME | |

**Indexes:** `catalog_name`, `object_type`, UNIQUE `(catalog_name, catalog_id)`

**Matching logic** (`SourceModel`): prefer `findByCatalogIdentity()` — exact match on
`(catalog_name, catalog_id)` — for anything a catalog cross-match actually identified; fall back
to `findByCoordinates()` (2 arcsec position match against `source_observations`) only when there's
no catalog identity at all.

### `source_observations` (Photometry History)

| Column | Type | Notes |
|--------|------|-------|
| `id` | CHAR(24) PK | |
| `source_id` | CHAR(24) NOT NULL FK→sources.id, cascade | |
| `frame_id` | CHAR(24) NOT NULL FK→frames.id, cascade | |
| `ra`, `dec` | DOUBLE NOT NULL | Measured position for this epoch |
| `mag`, `mag_err` | FLOAT NULL | Calibrated magnitude ± error |
| `flux`, `flux_err` | FLOAT NULL | Aperture flux (ADU) ± error |
| `fwhm`, `snr`, `elongation` | FLOAT NULL | |
| `obs_time` | DATETIME NOT NULL | |
| `created_at` | DATETIME | |

**Indexes:** `source_id`, `frame_id`, `obs_time`, `(source_id, obs_time)`, `(ra, dec)`

### `frame_sources` (Many-to-Many Link)

| Column | Type | Notes |
|--------|------|-------|
| `id` | CHAR(24) PK | |
| `frame_id` | CHAR(24) NOT NULL FK→frames.id, cascade | |
| `source_id` | CHAR(24) NOT NULL FK→sources.id, cascade | |
| `created_at` | DATETIME | |

**Indexes:** `frame_id`, `source_id`, UNIQUE `(frame_id, source_id)`

### `anomalies`

| Column | Type | Notes |
|--------|------|-------|
| `id` | CHAR(24) PK | |
| `frame_id` | CHAR(24) NOT NULL FK→frames.id, cascade | |
| `source_id` | CHAR(24) NULL FK→sources.id, `ON DELETE SET NULL` | |
| `anomaly_type` | ENUM(10 values) NOT NULL | See [`API.md`](API.md#post-apiv1framesidanomalies) for the full type table |
| `ra`, `dec` | DOUBLE NOT NULL | |
| `magnitude`, `delta_mag` | FLOAT NULL | |
| `mpc_designation` | VARCHAR(100) NULL | |
| `ephemeris_predicted_ra`, `ephemeris_predicted_dec` | DOUBLE NULL | |
| `ephemeris_predicted_mag`, `ephemeris_distance_au`, `ephemeris_angular_velocity` | FLOAT NULL | |
| `notes` | TEXT NULL | |
| `is_alert` | TINYINT(1) DEFAULT 0 | |
| `created_at` | DATETIME | |

**Indexes:** `frame_id`, `source_id`, `anomaly_type`, `is_alert`, `(ra, dec)`

### `object_stats` (Pre-aggregated Statistics)

| Column | Type | Notes |
|--------|------|-------|
| `id` | CHAR(24) PK | |
| `object` | VARCHAR(100) NOT NULL | |
| `filter` | VARCHAR(50) NULL | NULL for unfiltered |
| `frame_count` | INT DEFAULT 0 | |
| `total_exposure_sec` | FLOAT DEFAULT 0 | |
| `first_obs_time`, `last_obs_time` | DATETIME NULL | |
| `avg_fwhm`, `avg_airmass` | FLOAT NULL | Running average, updated incrementally |
| `created_at`, `updated_at` | DATETIME | |

**Indexes:** `object`, `filter`, `(object, filter)` (not a hard UNIQUE — see migration comment;
uniqueness for the increment is enforced in `ObjectStatsModel` via an advisory lock instead)

Run `php spark recalculate:object-stats` to rebuild this table from scratch.

### `source_charts`

| Column | Type | Notes |
|--------|------|-------|
| `id` | CHAR(24) PK | |
| `source_id` | CHAR(24) NOT NULL UNIQUE FK→sources.id, `ON DELETE CASCADE` | One chart per source |
| `style` | ENUM('track', 'stamp_strip') NOT NULL | |
| `frame_count` | INT DEFAULT 0 | Epochs actually included in the current image |
| `updated_at` | DATETIME NULL | Set on every upload |
| `created_at` | DATETIME | |

**Indexes:** `source_id` (unique)
