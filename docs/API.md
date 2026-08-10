# Observatory API — API Reference

Single source of truth for the `/api/v1` REST contract: every endpoint, its request/response
shape, and its error codes. This document reflects the actual controller behavior — if it ever
disagrees with the code, the code wins and this file should be fixed.

For project setup and CLI commands see [`README.md`](../README.md).
For the database schema see [`DATABASE.md`](DATABASE.md).
For AI-assisted development context see [`CLAUDE.md`](../CLAUDE.md).

---

## Base URL

```
/api/v1
```

`GET /` (outside `/api/v1`, no auth) returns a small liveness payload:
```json
{ "name": "Observatory API", "version": "v1", "status": "ok", "base_url": "/api/v1" }
```
Any other unmatched route returns `404` with the standard error format below.

---

## Authentication

Every `/api/v1/*` request must include:

```
X-API-Key: <secret>
Content-Type: application/json
Accept: application/json
```

The key is compared against `app.apiKey` (from `.env`) with `hash_equals()`. Missing, empty, or
mismatched key → **`401 Unauthorized`**:

```json
{ "error": "Unauthorized", "details": {} }
```

---

## Error Format

All other errors use:

```json
{
  "error": "Human-readable error description",
  "details": {}
}
```

`details` carries structured context when available (e.g. `{"field": "ra"}`,
`{"missing": ["ra", "dec"]}`) and is empty otherwise.

| Code | Meaning |
|------|---------|
| `400` | Missing/malformed required field or query parameter |
| `401` | Invalid or missing API key |
| `404` | Referenced resource (frame/source/object) not found |
| `422` | Well-formed request that fails semantic validation (e.g. non-numeric field, all sources invalid) |
| `500` | Unexpected server-side failure (DB insert failed, etc.) |

---

## Endpoints

### Frames

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/frames` | Register a new FITS frame |
| `GET` | `/frames` | List frames, filtered by object and/or date range |
| `GET` | `/frames/covering` | Frames covering a sky point |
| `POST` | `/frames/covering/batch` | Batch version for multiple positions |
| `GET` | `/frames/nearest-before` | Most recent frame of an object before a given time |
| `GET` | `/frames/{id}` | A single frame's full stored record |
| `GET` | `/frames/{id}/sources` | Sources currently linked to a frame, with per-frame values |
| `POST` | `/frames/{id}/sources` | Save detected sources for a frame |
| `POST` | `/frames/{id}/anomalies` | Save classified anomalies for a frame (replaces the frame's anomaly set) |

### Tasks

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/tasks` | Create a task (ANALYZE / DETECT_ANOMALIES / GENERATE_CHARTS / PREVIEW_CATALOG_MATCH) with its item list |
| `GET` | `/tasks` | List tasks, filtered by status/type/object |
| `GET` | `/tasks/{id}` | Task detail, including its full item list |
| `PATCH` | `/tasks/{id}` | Update a task's status |
| `POST` | `/tasks/{id}/items/progress` | Report the outcome of one or more items |
| `POST` | `/tasks/{id}/items/{item_id}/chart` | Upload/replace a PREVIEW_CATALOG_MATCH item's diagnostic chart PNG |
| `GET` | `/tasks/{id}/items/{item_id}/chart.png` | Fetch a task item's stored diagnostic chart PNG |

### Sources

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/sources/near` | Cone search for sources |
| `POST` | `/sources/near/batch` | Batch cone search over historical observations |
| `GET` | `/sources/{id}/observations` | Observation history (light curve) for a source |
| `GET` | `/sources/{id}/frames` | Frames containing a source |
| `GET` | `/sources/{id}/track` | Per-epoch position track for a source |
| `POST` | `/sources/tracks/batch` | Batch version of `.../track` for multiple sources |
| `POST` | `/sources/{id}/chart` | Upload/replace a source's finder-chart PNG |
| `POST` | `/sources/charts/batch` | Batch version of `.../chart` for multiple sources |
| `GET` | `/sources/{id}/chart.png` | Fetch a source's stored finder-chart PNG |

### Settings

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/settings` | Fetch all pipeline configuration parameters |

### Statistics

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/stats/objects` | List all observed objects with aggregated stats |
| `GET` | `/stats/objects/{object}` | Detailed stats for one object, by filter |

---

## Endpoint Reference

### POST /api/v1/frames

Register a processed FITS frame. Returns a `frame_id` used by subsequent calls. If
`observation.object` is present, also increments the corresponding `object_stats` row.

**Required fields:** `filename`, `obs_time`, `ra_center`, `dec_center`, `fov_deg`, `quality_flag`.
Everything else — `observation`, `instrument`, `sensor`, `observer`, `software`, `qc` — is optional;
any missing sub-field is stored as `NULL`.

<details>
<summary>Request example</summary>

```json
{
  "filename": "frame_20240315_220134.fits",
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
</details>

**Response `201 Created`:**
```json
{ "id": "42", "message": "Frame registered successfully" }
```

**Errors:** `400` missing required field · `422` a required numeric field (`ra_center`,
`dec_center`, `fov_deg`) isn't numeric · `500` insert failed

---

### GET /api/v1/frames/covering

Returns frames whose field of view covered a sky point, observed before a given time. A frame
covers a point if the angular distance from its center `(ra_center, dec_center)` to the point is
`<= fov_deg / 2`. See [Implementation Notes](#implementation-notes) for how the search works.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `ra` | float | yes | Right ascension (degrees) |
| `dec` | float | yes | Declination (degrees) |
| `before_time` | ISO 8601 | yes | Strictly-before upper bound |

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
Returns `{"data": []}` when no prior coverage exists (also `frames` table empty).

**Errors:** `400` missing/non-numeric `ra`/`dec`, or unparseable `before_time`

---

### POST /api/v1/frames/covering/batch

Batch version of the above — one bounding-box query covers every position.

**Required fields:** `positions` (array of `{ra, dec}`), `before_time`.

<details>
<summary>Request example</summary>

```json
{
  "positions": [
    {"ra": 202.461, "dec": 47.182},
    {"ra": 202.478, "dec": 47.201}
  ],
  "before_time": "2024-03-15T22:01:34Z"
}
```
</details>

**Response `200 OK`** — object keyed by position index (as a string), same shape as `covering`'s
`data` entries:
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
    "1": []
  }
}
```
`{"positions": []}` → `{"results": {}}`.

**Errors:** `400` missing `positions`/`before_time`, non-numeric position, or unparseable `before_time`

---

### POST /api/v1/frames/{id}/sources

Save all detected sources for a previously registered frame.

**Required:** `filename`, `sources` (array; `[]` is valid). Each source requires `ra`, `dec`
(numeric); all other source fields are optional.

**Matching logic:** for each source, look up an existing catalog row first by stable catalog
identity (`catalog_name` + `catalog_id`) when both are present, then fall back to a 2 arcsec
position match (against `source_observations`) only when there's no catalog identity to match on.
Catalog-identity matching is required for anything that moves between frames (an MPC-matched
asteroid can shift tens of arcsec/hour) — position-only matching would otherwise mint a new
`sources` row for it on every frame.

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
      "object_type": "STAR",
      "saturated": false,
      "from_subtraction": false
    }
  ]
}
```
</details>

`sources[].saturated` and `sources[].from_subtraction` (both optional, default `false`) mirror
observatory-pipeline's own transient per-source flags (`astrometry.py`'s `saturated`,
`subtraction.py`'s `_from_subtraction`) into `source_observations`, so a later, standalone
`DETECT_ANOMALIES` task can reconstruct `anomaly_detector.py`'s saturated-suppression and
subtraction-coverage-bypass rules for this observation from stored data alone — see
`GET /frames/{id}/sources` below.

**Response `201 Created`:**
```json
{
  "message": "Sources saved successfully",
  "count": 287,
  "new_sources": 12,
  "matched_sources": 275,
  "source_ids": ["6612f8a5e3b9c9.12345678", "6612f8a5e3ba01.87654321", null]
}
```

`source_ids` is positionally parallel to the request's `sources[]` (same length, same order) —
each entry is the resolved `sources.id`, or `null` for a skipped entry (invalid `ra`/`dec`, or an
insert failure). Use this to populate `anomalies[].source_id` on a subsequent
`POST /frames/{id}/anomalies` call for the same frame.

**Errors:** `400` missing `filename`/`sources` · `404` frame not found · `422` every source was
invalid (missing numeric `ra`/`dec`)

---

### POST /api/v1/frames/{id}/anomalies

Save classified anomalies for a frame. **This call replaces the frame's entire anomaly set** —
any anomalies already stored for this `frame_id` are deleted before the new batch is inserted,
so re-running anomaly detection for an already-classified frame (e.g. after fixing the classifier,
or via a standalone `DETECT_ANOMALIES` task) never leaves stale anomalies from the previous run
sitting alongside the new ones. The delete only happens after `anomaly_type` validation passes for
every entry, so a malformed request never wipes existing data. An empty `anomalies` array is valid
(`count: 0, alerts: 0`) and correctly represents "re-ran, found nothing this time".

**Required:** `filename`, `anomalies` (array). Per anomaly: `anomaly_type` (must be one of the
allowed values below — an unrecognized value rejects the **whole batch** with `400`, nothing is
inserted). `ra`/`dec` default to `0.0` if omitted; all other fields are optional/nullable.

**Anomaly types** — the full set (`AnomalyModel::ALLOWED_TYPES`), enforced by an `ENUM` column
constraint. Must stay in sync with the `AnomalyType` enum in observatory-pipeline's
`modules/anomaly_detector.py`:

| `anomaly_type` | When assigned | Alert? |
|---|---|---|
| `FIRST_OBSERVATION` | Sky area never observed before | No |
| `KNOWN_CATALOG_NEW` | Not in history, but found in a catalog (Simbad/Gaia/2MASS/Pan-STARRS) — was simply below the detection threshold before | No |
| `VARIABLE_STAR` | Has history, Δmag > threshold, Simbad classifies it as a known variable | No (logged) |
| `BINARY_STAR` | Has history, Δmag > threshold, Simbad classifies it as a known binary | No (logged) |
| `ASTEROID` | Shifted source, matched in MPC/SkyBot as an asteroid | No (logged + ephemeris) |
| `COMET` | Shifted source, matched in MPC/SkyBot as a comet | No (logged + ephemeris) |
| `SUPERNOVA_CANDIDATE` | New point source with no history near a Simbad galaxy, or a known galaxy brightening beyond threshold | **YES** |
| `MOVING_UNKNOWN` | Shifted source, not in MPC, elongation ≤ 3.0 | **YES** |
| `SPACE_DEBRIS` | Shifted source, not in MPC, elongation > 3.0 (fast trail) | **YES** |
| `UNKNOWN` | New point source, not in any catalog, area covered — or detected via image subtraction regardless of coverage | **YES** |

Types marked **YES** are the alert-worthy subset (`AnomalyModel::ALERT_TYPES`) and set
`is_alert = 1`. `source_id` (optional) links the anomaly to an existing `sources` row — typically
the id returned for that same source in `source_ids` from the preceding
`POST /frames/{id}/sources` call; omitted or `null` persists as `NULL`.

<details>
<summary>Request example</summary>

```json
{
  "filename": "frame_20240315_220134.fits",
  "anomalies": [
    {
      "anomaly_type": "ASTEROID",
      "source_id": "6612f8a5e3b9c9.12345678",
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
</details>

**Response `201 Created`:**
```json
{ "message": "Anomalies saved successfully", "count": 4, "alerts": 2 }
```

**Errors:** `400` missing `filename`/`anomalies`, or an unrecognized `anomaly_type` (batch
rejected atomically) · `404` frame not found · `500` batch insert failed

---

### GET /api/v1/frames

List frames, oldest first by `obs_time`, optionally filtered by object and/or an `obs_time` range.
This is the scope-resolution query behind a standalone task:
turning `object=M51` into a concrete list of frame ids covers that object's **entire** observation
history, not just frames from a particular pipeline run — e.g. re-running anomaly detection across
frames taken a year apart, which the inline per-frame pipeline flow has no way to express.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `object` | string | no | Exact match on `frames.object` |
| `date_from` | ISO 8601 | no | `obs_time >=` this |
| `date_to` | ISO 8601 | no | `obs_time <` this |
| `limit` | int | no | Max rows (default 100, capped at 1000) |
| `offset` | int | no | Pagination offset (default 0) |

**Response `200 OK`** — ordered oldest-first by `obs_time`; each entry has the same full shape as
`GET /frames/{id}` below:
```json
{ "data": [ { "id": "42", "filename": "...", "object": "M51", "obs_time": "...", "...": "..." } ] }
```

**Errors:** `400` unparseable `date_from`/`date_to`

---

### GET /api/v1/frames/{id}

A single previously registered frame's full stored record — every field `POST /frames` accepted,
echoed back flat (no nested `observation`/`instrument`/... groups). Lets a standalone task
reconstruct the `frame_meta` `anomaly_detector.py` needs without any local filesystem access to
the original FITS file.

**Response `200 OK`:**
```json
{
  "frame": {
    "id": "42",
    "filename": "frame_20240315_220134.fits",
    "obs_time": "2024-03-15T22:01:34Z",
    "ra_center": 202.4696,
    "dec_center": 47.1952,
    "fov_deg": 1.25,
    "quality_flag": "OK",
    "object": "M51",
    "exptime": 120.0,
    "filter": "V",
    "frame_type": "Light",
    "airmass": 1.23,
    "telescope": "Celestron EdgeHD 11",
    "camera": "ZWO ASI2600MM Pro",
    "qc_fwhm_median": 3.2,
    "...": "... every other frames column, same field names as POST /frames' flattened form"
  }
}
```

**Errors:** `404` frame not found

---

### GET /api/v1/frames/{id}/sources

The sources currently linked to a frame, each with **this frame's own** measured values
(`source_observations` — not a static catalog position) plus that source's catalog identity. This
is the piece a standalone `DETECT_ANOMALIES` task needs to reconstruct `anomaly_detector.py`'s
per-source input for an already-processed frame entirely from stored data — no re-running
astrometry/photometry, no local FITS access required.

**Response `200 OK`:**
```json
{
  "frame_id": "42",
  "data": [
    {
      "source_id": "6612f8a5e3b9c9.12345678",
      "ra": 202.461,
      "dec": 47.182,
      "mag": 14.23,
      "mag_err": 0.02,
      "flux": 45230.5,
      "flux_err": 120.0,
      "fwhm": 3.1,
      "snr": 125.5,
      "elongation": 1.1,
      "saturated": false,
      "from_subtraction": false,
      "catalog_name": "Gaia DR3",
      "catalog_id": "Gaia DR3 1234567890123456789",
      "catalog_mag": 14.15,
      "object_type": "STAR"
    }
  ]
}
```
Returns `{"data": []}` if the frame has no linked sources.

**Errors:** `404` frame not found

---

### Tasks

The granular pipeline job queue. observatory-pipeline submits one task per stage (`ANALYZE` /
`DETECT_ANOMALIES` / `GENERATE_CHARTS`) instead of running all three inline per file, so any stage
can be re-run later for an explicit scope — an object, a date range, or exactly the frame/source
ids a prior stage produced — without re-running whatever came before it.

### POST /api/v1/tasks

Create a task with its full, fixed item list.

**Required:** `type` (one of `ANALYZE`, `DETECT_ANOMALIES`, `GENERATE_CHARTS`,
`PREVIEW_CATALOG_MATCH`), `items` (array, at least one entry — each entry needs exactly one of
`filename` / `frame_id` / `source_id`, matching what the task's `type` operates over).
**Optional:** `scope` (`object`, `date_from`, `date_to` — descriptive only, not queried against;
the `items` array is the authoritative scope), `parent_task_id` (links a re-run to the task it
re-runs — must refer to an existing task).

`PREVIEW_CATALOG_MATCH` is observatory-pipeline's diagnostic tool (not part of the
ANALYZE/DETECT_ANOMALIES/GENERATE_CHARTS production chain) — its items use `filename` like
`ANALYZE`. It uploads its rendered chart via `POST /tasks/{id}/items/{item_id}/chart` below (there
is no `frame_id`/`source_id` to key a chart on for this task type — see `source_charts`' schema in
docs/DATABASE.md) and writes its summary back onto each item's own `payload`
(`{"matched", "total", "quality_flag", "chart_uploaded"}`) via `POST /tasks/{id}/items/progress`
below rather than just logging it, since the whole point is a result an operator goes and looks
at.

<details>
<summary>Request example</summary>

```json
{
  "type": "DETECT_ANOMALIES",
  "scope": { "object": "M51" },
  "items": [
    { "frame_id": "42" },
    { "frame_id": "57" }
  ]
}
```
</details>

**Response `201 Created`:**
```json
{ "id": "6612f9...", "type": "DETECT_ANOMALIES", "status": "PENDING", "total_items": 2, "message": "Task created successfully" }
```

**Errors:** `400` invalid/missing `type`, missing/empty `items`, an item with none of
`filename`/`frame_id`/`source_id`, unparseable `scope.date_from`/`scope.date_to`, or
`parent_task_id` doesn't refer to an existing task

---

### GET /api/v1/tasks

List tasks, most recent first. Filters (all optional): `status`, `type`, `object` (matches
`scope_object` exactly). `limit` (default 50, capped at 500).

**Response `200 OK`:**
```json
{
  "data": [
    {
      "id": "6612f9...",
      "type": "DETECT_ANOMALIES",
      "status": "RUNNING",
      "scope_object": "M51",
      "scope_date_from": null,
      "scope_date_to": null,
      "total_items": 2,
      "completed_items": 1,
      "failed_items": 0,
      "parent_task_id": null,
      "error": null,
      "created_at": "2024-03-15T22:01:34Z",
      "started_at": "2024-03-15T22:01:40Z",
      "finished_at": null
    }
  ]
}
```

---

### GET /api/v1/tasks/{id}

Task detail, including its full item list.

**Response `200 OK`:**
```json
{
  "task": { "...": "same shape as GET /tasks' data entries" },
  "items": [
    {
      "id": "6612fa...",
      "seq": 0,
      "filename": null,
      "frame_id": "42",
      "source_id": null,
      "status": "DONE",
      "error": null,
      "processed_at": "2024-03-15T22:01:45Z"
    }
  ]
}
```

**Errors:** `404` task not found

---

### PATCH /api/v1/tasks/{id}

Update a task's own status directly — e.g. the pipeline's worker flips `PENDING` → `RUNNING` when
it picks the task up, or an operator sets `CANCELLED`. Reaching `COMPLETED` is normally automatic
(driven by every item resolving via `POST /tasks/{id}/items/progress` below); this endpoint can
also set it directly (e.g. a zero-item task) or force a state.

**Required:** `status` (one of `PENDING`, `RUNNING`, `COMPLETED`, `FAILED`, `CANCELLED`).
**Optional:** `error` (message, typically set alongside `status: "FAILED"`).

**Response `200 OK`:** `{ "task": { "...": "same shape as GET /tasks/{id}'s task" } }`

**Errors:** `400` invalid/missing `status` · `404` task not found

---

### POST /api/v1/tasks/{id}/items/progress

Report the outcome of one or more items in a single call. The pipeline can call this after every
individual item for maximum progress granularity, or batch many at once to cut request count —
this endpoint doesn't enforce either; that trade-off lives entirely on the pipeline side. Updates
each item row and the parent task's aggregate counters, and auto-completes the task once every
item has resolved.

**Request body:**
```json
{
  "items": [
    { "item_id": "6612fa...", "status": "DONE", "frame_id": "42" },
    { "item_id": "6612fb...", "status": "FAILED", "error": "QC rejected: BLUR" },
    { "item_id": "6612fc...", "status": "DONE", "payload": { "matched": 82, "total": 98, "quality_flag": "OK", "chart_uploaded": true } }
  ]
}
```
`frame_id` is only meaningful when reporting an `ANALYZE` item, once `POST /frames` has resolved
one for it — `DETECT_ANOMALIES`/`GENERATE_CHARTS` items already carry their `frame_id`/`source_id`
from task creation. `payload` here is a RESULT overwriting the item's stored payload (e.g. a
`PREVIEW_CATALOG_MATCH` item's summary, shown above) — the same column `GENERATE_CHARTS` reads as
*input* at task-creation time; which direction depends on the task type, not the field itself.

**Response `200 OK`** — positionally parallel to the request's `items[]`:
```json
{
  "results": [
    { "item_id": "6612fa...", "status": "ok" },
    { "item_id": "6612fb...", "status": "ok" }
  ],
  "task": { "...": "same shape as GET /tasks/{id}'s task, reflecting the updated counters" }
}
```
An item already resolved (retry, duplicate delivery) reports back `status: "ok"` without
double-counting the task's counters. An unknown `item_id`, or one belonging to a different task,
fails only that entry (`status: "error"`) — it never blocks the rest of the batch.

**Errors:** `400` missing `items` (must be an array) · `404` task not found

---

### POST /api/v1/tasks/{task_id}/items/{item_id}/chart

Store the diagnostic chart PNG for a `PREVIEW_CATALOG_MATCH` task item, fully replacing any
previous one for that item — the `task_item_id`-keyed counterpart of
`POST /sources/{id}/chart` (section below), for a chart with no source to key on at all (see
`source_charts`' schema in docs/DATABASE.md: `task_item_id` is nullable and used instead of
`source_id` for this one style). Same raw-PNG-body shape as that endpoint, for the same reason —
the request body is entirely consumed by the image.

| Parameter | Type | Required |
|-----------|------|----------|
| `style` | must be `catalog_preview` | yes |
| `frame_count` | int (positive) | yes |

**Request body:** raw PNG bytes, `Content-Type: image/png`. Validated by its 8-byte signature
(`\x89PNG\r\n\x1a\n`), same as `POST /sources/{id}/chart`.

**Response `200 OK`:**
```json
{
  "task_item_id": "6612fa...",
  "style": "catalog_preview",
  "frame_count": 1,
  "updated_at": "2024-03-15T22:05:00Z"
}
```

**Errors:** `400` invalid `{item_id}`, missing/invalid `style` or `frame_count`, or body is not a
valid PNG · `404` task item not found (on this task)

---

### GET /api/v1/tasks/{task_id}/items/{item_id}/chart.png

Serve the stored diagnostic chart PNG for a task item as raw image bytes (`Content-Type:
image/png`) — the `task_item_id`-keyed counterpart of `GET /sources/{id}/chart.png`. Not called by
the pipeline itself; served for a future consumer such as the observatory website/dashboard.

**Errors:** `400` malformed `{item_id}` · `404` no chart uploaded yet for this task item

---

### GET /api/v1/sources/near

Cone search for sources in the catalog near a sky position. Positions come from each source's
nearest matching row in `source_observations` (`sources` itself has no static `ra`/`dec`).

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `ra` | float | yes | Right ascension (degrees) |
| `dec` | float | yes | Declination (degrees) |
| `radius_arcsec` | float | yes | Search radius (arcseconds) |

**Response `200 OK`** — nearest first:
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

**Errors:** `400` missing/non-numeric `ra`, `dec`, or `radius_arcsec`

---

### POST /api/v1/sources/near/batch

Batch cone search for multiple positions — reduces O(N) API calls to O(1). Unlike
`GET /sources/near`, this searches raw **historical observations** (not deduplicated by source),
so the pipeline can compare a newly-detected point against everything previously seen at that
position for anomaly detection.

**Required fields:** `positions`, `radius_arcsec`. **Optional:** `before_time` (restricts to
observations strictly before this time).

<details>
<summary>Request example</summary>

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
</details>

**Response `200 OK`** — object keyed by position index (as a string); each match is a raw
observation, **not** a catalog/source record:
```json
{
  "results": {
    "0": [
      {
        "ra": 202.4612,
        "dec": 47.1819,
        "mag": 14.21,
        "flux": 44850.0,
        "frame_id": "6612f7b2a1234.87654321",
        "obs_time": "2024-03-14T21:55:12Z",
        "filter": "L"
      }
    ],
    "1": []
  }
}
```
`filter` is the normalized filter of the frame that produced that observation (`string|null`) —
`source_observations` has no filter column of its own, so `SourcesController::nearBatch()` resolves
it with a `LEFT JOIN frames` on `frame_id`. Added for the observatory-pipeline's
`modules/anomaly_detector.py`, which restricts its historical Δmag comparison to same-filter
detections only (comparing across filters is a color-term artifact, not real variability). Not
present on `GET /sources/near`'s response — that endpoint predates this field.

`{"positions": []}` → `{"results": {}}`.

**Errors:** `400` missing `positions`, missing/non-numeric `radius_arcsec`, non-numeric position, or unparseable `before_time`

---

### GET /api/v1/sources/{id}/observations

Observation history (light curve data) for a specific source.

| Parameter | Type | Description |
|-----------|------|-------------|
| `from_time` | ISO 8601 | Observations after this time |
| `to_time` | ISO 8601 | Observations before this time |
| `limit` | int | Max records to return (default 1000, capped at 10000) |

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
`source.ra`/`source.dec` are taken from the most recent observation (`sources` has no fixed
position of its own).

**Errors:** `404` source not found

---

### GET /api/v1/sources/{id}/frames

All frames that contain a specific source, oldest first.

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

### GET /api/v1/sources/{id}/track

Full chronological position track for a source — one entry per frame it was detected on, each
with the (RA, Dec) it was *actually* detected at on that frame (`source_observations.ra/dec` — a
moving object's position differs epoch to epoch, unlike the fixed catalog position). Used by
observatory-pipeline's `modules/finder_chart.py` to build a source's finder/discovery chart. Kept
separate from `GET /sources/{id}/observations` so that endpoint's light-curve consumers are
unaffected.

**Response `200 OK`** — ordered chronologically, oldest first:
```json
{
  "source_id": "6612f8a5e3b9c9.12345678",
  "epochs": [
    {
      "frame_id": "6612f7b2a1234.87654321",
      "filename": "Vesta_A807_FA_Light_L_60_2021-03-14T16-54-55.fits",
      "object": "Vesta_A807_FA",
      "obs_time": "2021-03-14T16:54:55Z",
      "ra": 123.461,
      "dec": 45.682,
      "mag": 8.1
    }
  ]
}
```
Returns `{"epochs": []}` when the source has no observations.

**Errors:** `404` source not found

---

### POST /api/v1/sources/tracks/batch

Batch version of `GET /sources/{id}/track` for multiple sources in one round trip — lets
`modules/finder_chart.py` fetch every anomaly's source track for a frame at once instead of one
`GET` per `source_id`.

**Request body:**
```json
{ "source_ids": ["6612f8a5e3b9c9.12345678", "6612f8a5e3ba01.87654321"] }
```

**Response `200 OK`** — object keyed by the *requested* `source_id` (not index), each value the
same `epochs` array as the single-source endpoint:
```json
{
  "results": {
    "6612f8a5e3b9c9.12345678": [ { "frame_id": "...", "filename": "...", "object": "...", "obs_time": "...", "ra": 123.461, "dec": 45.682, "mag": 8.1 } ],
    "6612f8a5e3ba01.87654321": []
  }
}
```
An unknown or malformed `source_id` resolves to `[]` for that key rather than failing the whole
batch. `{"source_ids": []}` → `{"results": {}}`.

**Errors:** `400` missing `source_ids` (must be an array)

---

### POST /api/v1/sources/{id}/chart

Store the finder-chart PNG for a source, fully replacing any previous one — the pipeline always
regenerates the whole image from the source's current track (`GET .../track`) rather than
patching an existing file. The request body is the **raw PNG bytes** — not JSON, not multipart —
since the body is entirely consumed by the image; `style` and `frame_count` travel as query
parameters instead.

| Parameter | Type | Required |
|-----------|------|----------|
| `style` | `track` \| `stamp_strip` \| `before_after` | yes |
| `frame_count` | int (positive) | yes |

**Request body:** raw PNG bytes, `Content-Type: image/png`. Validated by its 8-byte signature
(`\x89PNG\r\n\x1a\n`) rather than fully decoded — the API does not otherwise inspect the image.

**Response `200 OK`:**
```json
{
  "source_id": "6612f8a5e3b9c9.12345678",
  "style": "track",
  "frame_count": 5,
  "updated_at": "2024-03-15T22:05:00Z"
}
```
Stored on disk at `writable/uploads/charts/{source_id}.png`; tracked in the `source_charts` table
(upserted by `source_id` — one row per source).

**Errors:** `400` invalid `{id}`, missing/invalid `style` or `frame_count`, or body is not a valid
PNG · `404` source not found

---

### POST /api/v1/sources/charts/batch

Batch version of `POST /sources/{id}/chart`. Since a raw-bytes body can't carry more than one PNG
at once, this endpoint takes a JSON envelope with each image base64-encoded instead.

**Request body:**
```json
{
  "charts": [
    { "source_id": "6612f8a5e3b9c9.12345678", "style": "track", "frame_count": 5, "png_base64": "iVBORw0KGgo..." },
    { "source_id": "6612f8a5e3ba01.87654321", "style": "stamp_strip", "frame_count": 3, "png_base64": "iVBORw0KGgo..." }
  ]
}
```

**Response `200 OK`** — a plain array, positionally parallel to the request's `charts[]` (same
length/order, unlike `tracks/batch`'s id-keyed object):
```json
{
  "results": [
    { "source_id": "6612f8a5e3b9c9.12345678", "status": "ok", "style": "track", "frame_count": 5, "updated_at": "2024-03-15T22:05:00Z" },
    { "source_id": "unknownsource.00000000", "status": "error", "error": "Source not found" }
  ]
}
```
A bad entry (invalid/unknown `source_id`, bad `style`, bad `frame_count`, bad PNG) fails only
that entry (`status: "error"`, with an `error` message) — it never blocks the rest of the batch.
`{"charts": []}` → `{"results": []}`.

**Errors:** `400` missing `charts` (must be an array)

---

### GET /api/v1/sources/{id}/chart.png

Serve the stored finder-chart PNG for a source as raw image bytes (`Content-Type: image/png`).
Not called by the pipeline itself — served for a future consumer such as the observatory website.

**Errors:** `400` malformed `{id}` · `404` no chart uploaded yet for this source

---

### GET /api/v1/settings

Fetch all pipeline configuration parameters as a flat key-value map. The pipeline calls this on
startup (and optionally periodically) to pull its configuration from the central database instead
of relying on local `.env` overrides for every tunable — one place to update a threshold, one
place it takes effect from.

The response is a plain `{ param: value }` object — every parameter stored in the `settings` table,
sorted alphabetically by name. Values are always strings (the pipeline casts to the appropriate
type on its side, same as it does with `os.getenv()` today). `API_BASE_URL` and `API_KEY` are
**not** stored — those remain local to the pipeline's own `.env`.

**Response `200 OK`:**
```json
{
  "data": {
    "ASTAP_BINARY": "/usr/local/bin/astap",
    "ASTAP_CATALOGS": "/astap/catalogs",
    "ASTAP_FOV_HINT": "0",
    "CACHE_TTL_HOURS": "1.0",
    "CHART_ENABLED": "true",
    "CHART_MAX_EPOCHS": "12",
    "CHART_STAMP_SIZE_ARCSEC": "60.0",
    "DELTA_MAG_ALERT": "0.5",
    "EDGE_MARGIN_FRAC": "0.1",
    "FITS_ARCHIVE": "/fits/archive",
    "FITS_INCOMING": "/fits/incoming",
    "FITS_REJECTED": "/fits/rejected",
    "LOG_LEVEL": "INFO",
    "MATCH_CONE_ARCSEC": "5.0",
    "...": "... all other parameters"
  }
}
```

This endpoint is read-only — there is no `PATCH`/`PUT` counterpart. Configuration updates go
directly into the `settings` table (via a future admin UI or manual SQL) and take effect on the
pipeline's next fetch.

---

### GET /api/v1/stats/objects

List all observed objects with aggregated statistics, summed across all filters.

| Parameter | Description |
|-----------|-------------|
| `object` | Optional partial-match filter on object name |

**Response `200 OK`** — sorted by object name; `filters` sorted alphabetically with
`"(unfiltered)"` always last:
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
Returns `{"data": []}` when no statistics exist. `total_exposure_hours` is rounded to 2 decimals.

---

### GET /api/v1/stats/objects/{object}

Detailed statistics for one object, broken down by filter (`{object}` is URL-decoded).

**Response `200 OK`** — `by_filter` sorted alphabetically by filter, with a null (unfiltered)
filter last:
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
`avg_fwhm`/`avg_airmass` are rounded to 2 decimals.

**Errors:** `404` object not found in statistics

---

## Implementation Notes

**Cone search / sky-coverage math** lives in `app/Libraries/SkyMath.php`, shared by every
endpoint above that searches by sky position (`sources/near*`, `frames/covering*`). Each does a
cheap bounding-box pre-filter on an indexed column (fast, uses the index), then an exact
`haversineArcsec()` filter in PHP. The bounding box itself accounts for two things a naive
`ra BETWEEN ra-Δ AND ra+Δ` gets wrong:
- **Declination-scaled RA margin** (`raMargin()`) — a fixed angular radius spans more RA degrees
  as `|dec|` grows (meridians converge toward the poles).
- **The RA=0°/360° seam** (`raRanges()` / `combinedRaRanges()`) — a query near RA=0 must also
  match sources near RA=360, which a plain `BETWEEN` silently misses.

**Batch response key typing.** `.../near/batch`, `.../covering/batch`, and `.../tracks/batch`
return `results` as a JSON **object** keyed by position index or source id (e.g.
`{"0": [...], "1": [...]}` or `{"<source_id>": [...]}`), never a JSON array — even though PHP
internally re-canonicalizes purely-numeric string keys back to a sequential array. The controllers
explicitly cast to `(object)` before encoding to guarantee this. Client code should always index
`results` by key (`results["0"]`, `results[source_id]`), not assume array order.
