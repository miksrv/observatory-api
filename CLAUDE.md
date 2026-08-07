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
