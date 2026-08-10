# CLAUDE.md — Observatory API

AI-assisted development context for `observatory-api`. Read this first — but for anything covered
elsewhere, this file only points there rather than repeating it, so there's one place to update:

- **Setup, running, Docker, CLI commands, testing** → [`README.md`](README.md)
- **Full API endpoint reference** (requests/responses, error codes, anomaly types) →
  [`docs/API.md`](docs/API.md)
- **Full database schema** (columns, indexes, foreign keys, ER diagram) →
  [`docs/DATABASE.md`](docs/DATABASE.md)

---

## Project Overview

A REST API server built with **CodeIgniter 4 / PHP**, the central persistence layer for the
Observatory FITS Analysis Pipeline system. It owns the **MariaDB** schema; `observatory-pipeline`
(Python, runs on the observatory server) is its only current client and has **no direct DB
access** — everything goes through this API, authenticated via an `X-API-Key` header. See
README.md's Architecture section for the full picture.

---

## Data-model gotchas (things that aren't obvious from the schema tables alone)

- **`sources` has no `ra`/`dec` of its own.** A single static position doesn't make sense for a
  catalog that also holds moving objects (MPC-matched asteroids/comets shift well beyond any
  reasonable fixed-position radius between frames). Per-epoch positions live in
  `source_observations`; anything that needs "the" position of a source resolves it from the
  nearest or latest observation row instead (see `SourceModel`, `SourceObservationModel`).
- **Source matching priority**: `SourceModel::findByCatalogIdentity()` (exact match on
  `catalog_name` + `catalog_id`) first, `findByCoordinates()` (2 arcsec position fallback) only
  when there's no catalog identity to match on at all. Don't swap this order — position-only
  matching would mint a new `sources` row for a moving object on every frame.
- **`anomaly_type` is a closed set** (`AnomalyModel::ALLOWED_TYPES`, mirrored by an `ENUM` column
  constraint) that **must stay in sync** with the `AnomalyType` enum in observatory-pipeline's
  `modules/anomaly_detector.py`. Adding a type means updating both repos plus the migration.
- **Cone-search / sky-coverage math is centralized in `app/Libraries/SkyMath.php`** — declination
  -scaled RA margins and RA=0°/360° seam handling, shared by every endpoint that searches by sky
  position. Don't reintroduce ad-hoc Haversine/bounding-box code elsewhere; extend `SkyMath`
  instead. Behavioral details are in `docs/API.md`'s Implementation Notes.
- Batch endpoints (`.../near/batch`, `.../covering/batch`, `.../tracks/batch`) return `results` as
  a JSON **object** keyed by index or id, never an array — the controllers explicitly cast to
  `(object)` before encoding because PHP re-canonicalizes numeric string keys. Preserve that cast
  in any new batch endpoint.
- **`POST /frames/{id}/anomalies` replaces, it doesn't append.** It deletes every existing
  `anomalies` row for that `frame_id` before inserting the new batch (after `anomaly_type`
  validation passes, so a malformed request can't wipe existing data first). This exists so a
  standalone `DETECT_ANOMALIES` task re-run for an already-classified frame supersedes the
  previous run's anomalies instead of accumulating duplicates alongside them.
- **`source_observations.saturated`/`from_subtraction` exist so anomaly detection can be decoupled
  from astrometry/photometry.** They mirror observatory-pipeline's own transient per-source flags
  — without persisting them, a standalone `DETECT_ANOMALIES` task re-run purely from stored data
  (no local FITS access) couldn't reconstruct `anomaly_detector.py`'s saturated-suppression or
  subtraction-coverage-bypass rules for that observation.
- **`source_observations` has no `filter` column of its own** — a photometric filter is a property
  of the *frame*, not the observation row. `SourcesController::nearBatch()` therefore `LEFT JOIN`s
  `frames` on `frame_id` to resolve each historical detection's filter into the `filter` field of
  its response (`docs/API.md` section on `POST /sources/near/batch`). observatory-pipeline's
  `anomaly_detector.py` needs this to restrict its Δmag comparison to same-filter epochs — a star's
  brightness in one filter isn't directly comparable to another (a color term, not real
  variability). `GET /sources/near` (the older single-position endpoint, no longer called from the
  pipeline) was intentionally left as-is rather than also joining `frames` for a field nothing
  reads.
- **`tasks`/`task_items` scope is authoritative on `task_items`, not on `tasks`' descriptive
  `scope_object`/`scope_date_from`/`scope_date_to` columns.** Those three exist only so a task
  list can be filtered/displayed without joining and aggregating `task_items` every time — never
  query "which frames does this task cover" from them; resolve it from `task_items` itself.
  Exception: `RESTART` is a signal task with no items at all — `total_items` = 0, no `task_items`
  rows; the API auto-marks it `COMPLETED` upon creation since there are no items to resolve.
- **`source_charts` is dual-keyed: `source_id` OR `task_item_id`, never both.** A finder/discovery
  chart (`track`/`stamp_strip`/`before_after`) has a source; a `PREVIEW_CATALOG_MATCH` diagnostic
  chart (`catalog_preview`) doesn't — it's a whole-frame image, not tied to any celestial object —
  so it keys on `task_item_id` instead. There's no FK from `task_item_id` to `task_items.id`: this
  migration (2026-08-06) runs before `CreateTasksTable` (2026-08-07) in migration order, so adding
  one would fail `CREATE TABLE` on a fresh database. Don't "fix" that by adding the FK in place —
  either move this migration's timestamp after `CreateTasksTable`'s, or add the FK via a follow-up
  migration.
- **`POST /tasks/{id}/items/progress`'s `payload` field is bidirectional**, not input-only despite
  `TasksController::create()` being where it's first mentioned: `GENERATE_CHARTS` reads it as input
  at task-creation time; `PREVIEW_CATALOG_MATCH` overwrites it with a result
  (`{"matched", "total", "quality_flag", "chart_uploaded"}`) at completion time via this endpoint.
  Same column, opposite direction, depending entirely on the task type — never assume "payload" is
  always caller-supplied input.
- **`settings.type` discriminates configurable vs. system-managed rows.** `config` rows are
  user-tunable and served via `GET /settings`; `internal` rows (e.g. `pipeline_last_seen_at`) are
  written/read by the API itself and excluded from the settings endpoint and any configuration UI.
  `SettingModel::getAllAsMap()` and `getConfigurable()` filter on `type = 'config'` automatically;
  `getAll()` returns everything including internal rows.
- **Pipeline heartbeat** (`pipeline_last_seen_at`) is updated in `ApiKeyFilter::after()` on every
  successful (2xx) authenticated API request — not in any controller. This is a cross-cutting
  concern handled entirely in the filter layer; adding a new controller or endpoint requires no
  extra work for the heartbeat to keep working.

---

## CodeIgniter 4 Conventions

- **PHP 8.2+** (pinned by `composer.json`: `"php": "^8.2"`)
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
