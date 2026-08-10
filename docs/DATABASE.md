# Observatory API — Database Schema

Single source of truth for the database schema: every table, column, index, and foreign key.
Migrations in `app/Database/Migrations/` are the actual ground truth — this file summarizes them
for quick reference and should be kept in sync whenever a migration changes.

All tables use `CHAR(24)` primary keys generated via `uniqid('', true)` (no auto-increment),
except `settings` which uses a plain auto-increment `INT` PK.

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
        │                 ┌─────────────────┐                    │
        └────────────────►│  frame_sources  │◄───────────────────┘
                          ├─────────────────┤
                          │ frame_id (FK)   │
                          │ source_id (FK)  │
                          └─────────────────┘

┌─────────────────┐       ┌─────────────────┐       ┌──────────────────┐
│    anomalies    │       │  object_stats   │       │  source_charts   │
├─────────────────┤       ├─────────────────┤       ├──────────────────┤
│ frame_id (FK)   │       │ object          │       │ source_id (null) │──► sources (unique, cascade)
│ source_id (FK)  │       │ filter          │       │ task_item_id     │──► task_items (unique, no FK)
│ anomaly_type    │       │ frame_count     │       │ style            │
│ ra, dec         │       │ total_exposure  │       │ frame_count      │
│ is_alert        │       │ avg_fwhm        │       │ updated_at       │
└─────────────────┘       └─────────────────┘       └──────────────────┘

┌─────────────────────┐       ┌──────────────────────┐
│        tasks        │       │      task_items      │
├─────────────────────┤       ├──────────────────────┤
│ id (CHAR 24 PK)     │◄──────│ task_id (FK)         │
│ type, status        │       │ seq                  │
│ scope_object/dates  │       │ filename             │
│ total/completed/    │       │ frame_id (FK, null)  │──► frames (SET NULL)
│   failed_items      │       │ source_id (FK, null) │──► sources (SET NULL)
│ parent_task_id (FK) │       │ status, error        │
└─────────────────────┘       └──────────────────────┘

┌─────────────────────┐
│      settings       │
├─────────────────────┤
│ id (INT PK, auto)   │
│ param (UNIQUE)      │
│ value, description  │
│ type (ENUM)         │
└─────────────────────┘
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
- One **source_charts** row = the current chart PNG for one source (1:1, cascade-deleted with its
  source) OR one task item (1:1, no cascade — see the table's own notes); the image bytes live on
  disk at `writable/uploads/charts/{source_id|task_item_id}.png`, not in this table.
- **object_stats** = pre-aggregated statistics per object+filter, updated incrementally whenever
  a frame is registered (`POST /frames`).
- One **task** = one stage's unit of work for observatory-pipeline's granular job queue (`ANALYZE`
  / `DETECT_ANOMALIES` / `GENERATE_CHARTS`), submitted with an explicit, itemized scope rather
  than run inline per frame. One **task_item** = one unit inside that scope — a filename (for
  `ANALYZE`, before a frame exists yet), a `frame_id` (`DETECT_ANOMALIES`), or a `source_id`
  (`GENERATE_CHARTS`). `parent_task_id` links a re-run to the task it re-runs, so re-processing
  history stays as new rows rather than mutating an old task in place.

---

## Tables

### `frames`

| Column | Type | Notes |
|--------|------|-------|
| `id` | CHAR(24) PK | |
| `filename` | VARCHAR(255) NOT NULL | |
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
| `saturated` | TINYINT(1) DEFAULT 0 | Mirrors observatory-pipeline's `astrometry.py` saturation flag — persisted so `anomaly_detector.py`'s saturated-artifact suppression rule can be reconstructed for this observation later, purely from stored data |
| `from_subtraction` | TINYINT(1) DEFAULT 0 | Mirrors observatory-pipeline's `subtraction.py` `_from_subtraction` flag — persisted so `anomaly_detector.py`'s coverage-check bypass for subtraction candidates can be reconstructed later, purely from stored data |
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

One row per chart — either a per-source finder/discovery chart (`source_id` set) or a
`PREVIEW_CATALOG_MATCH` diagnostic chart with no source at all (`task_item_id` set instead).
Exactly one of the two is set per row, never both.

| Column | Type | Notes |
|--------|------|-------|
| `id` | CHAR(24) PK | |
| `source_id` | CHAR(24) NULL UNIQUE FK→sources.id, `ON DELETE CASCADE` | One chart per source; set for `track`/`stamp_strip`/`before_after` |
| `task_item_id` | CHAR(24) NULL UNIQUE | One chart per task item; set for `catalog_preview`. **No FK** — this migration (2026-08-06) predates `CreateTasksTable` (2026-08-07) in migration order, so a FK to `task_items.id` here would fail at `CREATE TABLE` time on a fresh database; see the migration's docblock |
| `style` | ENUM('track', 'stamp_strip', 'before_after', 'catalog_preview') NOT NULL | `catalog_preview` is the only style paired with `task_item_id` instead of `source_id` |
| `frame_count` | INT DEFAULT 0 | Epochs actually included in the current image; always 1 for `catalog_preview` (a single-frame chart, not an epoch series) |
| `updated_at` | DATETIME NULL | Set on every upload |
| `created_at` | DATETIME | |

**Indexes:** `source_id` (unique), `task_item_id` (unique)

### `tasks` (Pipeline Job Queue)

| Column | Type | Notes |
|--------|------|-------|
| `id` | CHAR(24) PK | |
| `type` | ENUM('ANALYZE', 'DETECT_ANOMALIES', 'GENERATE_CHARTS', 'PREVIEW_CATALOG_MATCH') NOT NULL | `PREVIEW_CATALOG_MATCH` is a diagnostic tool, not part of the production chain — see observatory-pipeline's CLAUDE.md |
| `status` | ENUM('PENDING', 'RUNNING', 'COMPLETED', 'FAILED', 'CANCELLED') DEFAULT 'PENDING' | |
| `scope_object` | VARCHAR(100) NULL | Descriptive only — `task_items` is the authoritative scope |
| `scope_date_from`, `scope_date_to` | DATETIME NULL | Descriptive only, same as above |
| `total_items`, `completed_items`, `failed_items` | INT DEFAULT 0 | Progress counters, bumped atomically by `TaskModel::bumpProgress()` |
| `parent_task_id` | CHAR(24) NULL FK→tasks.id, `ON DELETE SET NULL` | Links a re-run to the task it re-runs |
| `error` | TEXT NULL | |
| `started_at`, `finished_at` | DATETIME NULL | |
| `created_at` | DATETIME | |

**Indexes:** `status`, `type`, `scope_object`, `parent_task_id`

Status reaches `COMPLETED` automatically once `completed_items + failed_items >= total_items`
(see `TaskModel::bumpProgress()`) — `FAILED`/`CANCELLED` are always set explicitly via
`PATCH /tasks/{id}`.

### `task_items`

| Column | Type | Notes |
|--------|------|-------|
| `id` | CHAR(24) PK | |
| `task_id` | CHAR(24) NOT NULL FK→tasks.id, cascade | |
| `seq` | INT NOT NULL | Position within the task, UNIQUE with `task_id` |
| `filename` | VARCHAR(255) NULL | Populated for `ANALYZE` items before a frame exists, and for `PREVIEW_CATALOG_MATCH` items (which never resolve a frame at all) |
| `frame_id` | CHAR(24) NULL FK→frames.id, `ON DELETE SET NULL` | Populated for `DETECT_ANOMALIES` items, or once an `ANALYZE` item resolves a frame |
| `source_id` | CHAR(24) NULL FK→sources.id, `ON DELETE SET NULL` | Populated for `GENERATE_CHARTS` items |
| `payload` | TEXT NULL (JSON) | Bidirectional: `GENERATE_CHARTS` reads it as *input* at creation time (`{"anomaly_type", "designation"}`); `PREVIEW_CATALOG_MATCH` writes it as a *result* at completion time (`{"output_path", "matched", "total", "quality_flag"}`) via `POST /tasks/{id}/items/progress`. Opaque to the API either way — stored and echoed back, never inspected |
| `status` | ENUM('PENDING', 'DONE', 'FAILED') DEFAULT 'PENDING' | |
| `error` | TEXT NULL | |
| `processed_at` | DATETIME NULL | |
| `created_at` | DATETIME | |

**Indexes:** `task_id`, `frame_id`, `source_id`, UNIQUE `(task_id, seq)`

Exactly one of `filename` / `frame_id` / `source_id` is meaningful per row — which one depends on
the parent task's `type`, not on the row itself; there's no discriminator column because the
parent already disambiguates it.

### `settings` (Pipeline Configuration)

Flat key-value store for pipeline configuration parameters. Seeded with defaults matching
observatory-pipeline's `config.py` on first migration; updated via admin SQL or a future UI.
`API_BASE_URL` and `API_KEY` are intentionally absent — those stay local to the pipeline's `.env`.

Each row has a `type` discriminator: `config` parameters are user-tunable and served to the
pipeline via `GET /settings`; `internal` parameters are system-managed (e.g.
`pipeline_last_seen_at` — the pipeline heartbeat timestamp, written automatically by
`ApiKeyFilter::after()` on every successful API request) and excluded from the settings endpoint
and any future configuration UI.

| Column | Type | Notes |
|--------|------|-------|
| `id` | INT(11) UNSIGNED PK, auto-increment | Unlike every other table, uses auto-increment — no need for uniqid-style IDs on a small config table |
| `param` | VARCHAR(255) NOT NULL UNIQUE | Parameter name (e.g. `QC_FWHM_MAX_ARCSEC`) |
| `value` | TEXT NULL | Current value, always stored as a string |
| `description` | TEXT NULL | Human-readable description of the parameter |
| `type` | ENUM('config', 'internal') NOT NULL DEFAULT 'config' | `config` = user-configurable, shown in UI and served via `GET /settings`; `internal` = system-managed, hidden from UI and the settings endpoint |
| `created_at` | DATETIME | `DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | DATETIME | `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**Indexes:** `param` (unique), `type`, `created_at`, `updated_at`

