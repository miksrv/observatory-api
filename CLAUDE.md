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
- **A `source_id` can hold one `source_charts` row PER STYLE**, not one row total — unique key is
  `(source_id, style)` (`2026-08-11-000001_SourceChartsUniqueByStyle.php`), not `source_id` alone.
  observatory-pipeline's `modules/anomaly_detector.py` classifies a source independently on every
  frame it appears on, so the same source can collect anomalies of more than one `anomaly_type`
  over its lifetime (e.g. `UNKNOWN` on first detection, `MOVING_UNKNOWN` once it had moved) — real
  incident, 2026-08-11: source_id `6a7be36b4d7578.98132403`, 12 `MOVING_UNKNOWN` + 1 `UNKNOWN`
  anomalies, but the old `source_id`-only unique key meant only ONE chart ever survived, whichever
  style was uploaded last. `SourceChartModel::upsertForSource()` now upserts by `(source_id,
  style)`; the on-disk filename carries the style too (`{source_id}_{style}.png` —
  `SourcesController::uploadChart()`/`chart()`, `Web\ChartsController::image()`), with a fallback
  to the old un-suffixed `{source_id}.png` path for any chart uploaded before this migration and
  never re-rendered since. `Web\AnomaliesController::createTask()` submits one `GENERATE_CHARTS`
  task item PER DISTINCT `anomaly_type` within a selected group (not one item per group) so every
  style actually gets (re)generated. `task_item_id`-keyed rows are unaffected — that style
  (`catalog_preview`) never coexists with more than one per task_item_id anyway.
- **`track_gif`/`stamp_strip_gif` are GIF, every other style is PNG — the file extension and
  Content-Type follow `style`, never a hardcoded `.png`/`image/png`.** Added
  (`2026-08-11-000002_AddGifStylesToSourceCharts.php`) when observatory-pipeline's
  `modules/finder_chart.py` grew `CHART_GIF_ENABLED`: an animated companion chart uploaded via the
  SAME `POST /sources/{id}/chart` endpoint as its static counterpart, keyed by its own style value
  rather than overwriting it (same one-row-per-style mechanism as the bullet above).
  `SourcesController::uploadChart()` validates the body's magic bytes against what `style` implies
  (PNG's 8-byte signature, or GIF's 6-byte `GIF87a`/`GIF89a`) rather than trusting the request's own
  `Content-Type` header; `extensionForStyle()`/`contentTypeForChartPath()` are the single place both
  `uploadChart()` (write) and `chart()`/`resolveChartPath()` (read) derive `.gif` vs `.png` from, so
  they can't disagree. `Web\ChartsController` duplicates this same style→extension logic rather than
  sharing it (see that class's own docblock for why). `SourceChartModel::STYLE_DISPLAY_PRIORITY`
  deliberately excludes both GIF styles — a style-less `GET .../chart.png` request must keep
  resolving to a static chart, never surprise a caller written before GIF charts existed with an
  animation; fetch `?style=track_gif`/`?style=stamp_strip_gif` explicitly to get one.
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
- **Pipeline heartbeat** (`pipeline_last_seen_at`, and per pipeline the `pipelines` row) is updated
  in `ApiKeyFilter::after()` on every successful (2xx) authenticated API request — not in any
  controller. This is a cross-cutting concern handled entirely in the filter layer; adding a new
  controller or endpoint requires no extra work for the heartbeat to keep working.
- **Several pipelines share one database** (one per observatory server; each holds its own FITS
  files). Each has its own API key (`pipelines.key_hash`, SHA-256; issued by
  `php spark pipeline:*`, never by the web UI); `ApiKeyFilter` resolves the key to
  the caller and stores it in `App\Libraries\PipelineIdentity` for the request. `app.apiKey` in
  .env is only read once, by the migration, to register it as pipeline `default`.
  `frames.pipeline_id` = who holds the file; `tasks.pipeline_id` = the only pipeline that may claim
  the task (NULL = any) — enforced in `TasksController::update()`'s claim; the worker polls
  `GET /tasks?claimable=1`, and `POST /tasks` targets the caller. Anything that creates a task needing
  local files must target it: the Web controllers go through `TaskModel::createPerPipeline()`
  (frame tasks → frame owner, `GENERATE_CHARTS` → owner of the source's latest frame,
  `SourceObservationModel::getChartPipelineForSources()`), `RESTART` → `TaskModel::queueRestart()`
  per known pipeline. `POST /frames` refuses (`409`) to let one pipeline overwrite another's
  `filename`. Feature tests: `DatabaseTestCase` registers phpunit's `app.apiKey` as `default`.
- **The web UI (`/ui`, `App\Controllers\Web\*`) is signed in to with ONE account from `.env`**
  (`ui.username`/`ui.password`, `Config\Ui`; the password may be a `password_hash()`, made with
  `php spark ui:password`) — not with pipeline API keys, and there is no users table. The `ui`
  route group runs `csrf` + `ui_auth` (`UiAuthFilter` → `App\Libraries\UiAuth`): every page but
  `/ui/login` needs a session, which holds a fingerprint of the configured credentials (changing
  them signs everyone out) and expires after `ui.idleMinutes` idle; wrong passwords lock the address
  (`ui.maxFailures` within `ui.lockMinutes`, kept in the cache). CSRF is session-backed with one
  token per session (`Config\Security`), so every UI form needs `csrf_field()`. Feature tests that
  hit `/ui` use `Tests\Support\SignsInToUi` (`signInToUi()` in setUp, POST bodies through
  `uiForm()`). Views extend `web/layout`, set `title`/`active` with `$this->setVar()`, and format
  through `app/Helpers/ui_helper.php`; styles and behaviour are plain files in `public/assets/ui`
  (no build step) — `app.js` is progressive, every form must still work without it.

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

## Versioning and releases

The version lives in one place: the top `## X.Y.Z` heading of `CHANGELOG.md` (repo root). There
is no other version field to keep in sync.

**While working on a branch:** every change worth noting gets one short line in the top section
of `CHANGELOG.md`. If the top section is already released (its `vX.Y.Z` tag exists), start a new
one above it first. Format, as in the existing sections:

```markdown
## 1.2.0

### Minor Changes

- Tasks: each pipeline polls only the tasks it may claim
```

- `### Patch Changes` by default; `### Minor Changes` for noticeable new features or behaviour
  changes (a new endpoint or task type, a schema migration, a changed contract); a major bump
  practically never.
- One line per change, `- Area: what changed`, written for someone deploying it, English only.
- A change that needs the matching release of observatory-pipeline says so on its line, e.g.
  `(requires observatory-pipeline 1.2.0)` — the two are deployed separately.

**Pull request:** from the feature branch into `main`, titled with the new version (`v1.2.0`),
the body summarising that version's `CHANGELOG.md` section.

## Release

Only when asked, and only after the version's PR is merged into `main`:

1. `git checkout main && git pull` — release what is on `origin/main`, nothing local.
2. Tag the merge commit with `v` + the top `CHANGELOG.md` version and push the tag:
   `git tag v1.2.0 && git push origin v1.2.0`.
3. Create the GitHub release for that tag: title is the tag name, body is that version's section
   from `CHANGELOG.md` without the `## X.Y.Z` heading (it starts with `### Minor Changes` /
   `### Patch Changes`), marked as latest:
   `gh release create v1.2.0 --title v1.2.0 --notes-file <section.md> --latest`.

---

## Related Repository

`observatory-pipeline` — Python pipeline that is the primary API consumer.
Full context in: `/Users/mik/Projects/observatory-pipeline/CLAUDE.md`
API contract defined in: `/Users/mik/Projects/observatory-pipeline/API.md`
