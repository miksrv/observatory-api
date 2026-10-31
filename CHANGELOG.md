# Changelog

## 1.4.1

### Patch Changes

- Pipelines: a pipeline reports whether each online catalog it uses (Simbad, Gaia, VizieR, SkyBot, JPL, …) answers with `POST /pipelines/catalogs`; the dashboard shows the list in the pipeline's card with response times, the error of an unreachable one on hover, and marks a check older than an hour as out of date — stored in `pipelines.catalog_status` (migration: run `php spark migrate` on the hosting by hand after deploying; until then the endpoint answers 503 and nothing else is affected) (sent by observatory-pipeline 1.6.3)

## 1.4.0

### Minor Changes

- Web UI: anomalies can be marked «Проверено» with a note — from the charts gallery (checked charts: every current anomaly of those sources) or the anomalies list. A reviewed anomaly leaves the default anomalies list («Не проверенные»; «Проверенные» and «Все» tabs show the rest, «Снять отметку» undoes it) and the dashboard's anomaly and alert counts. The mark is kept in a new `anomaly_reviews` table keyed by frame, source and anomaly type, so it survives `DETECT_ANOMALIES` and `ANALYZE` re-runs; a later anomaly of the same source (another frame or type) comes in unreviewed — run `php spark migrate` on the hosting by hand after deploying
- Web UI: on the anomalies list a group's count is the selected tab's, while its actions (charts, delete, review) cover all of its anomalies; Enter in the review note presses «Проверено»; a page restored with the back button no longer sends the next action to the previous button's address
- Web UI: the charts gallery deletes the checked sources' charts in one go («Удалить графики»; their anomalies and review marks stay), shows on each source chart whether its anomalies are reviewed, and filters by that

## 1.3.1

### Patch Changes

- Sources: `POST /sources/near/batch` streams the matching observations instead of loading the whole search box into memory, so heavily observed fields (M51, ~300 frames) no longer exhaust PHP's 256 MB `memory_limit`; the response is unchanged (a read that fails midway is a 500, never a truncated 200)
- Sources: a retried `POST /frames/{id}/sources` running alongside the first call for the same frame (observatory-pipeline before 1.4.1 retried after a 30 s timeout) no longer fails with 500 "Duplicate entry … uk_srcobs_frame_source / uk_frame_source": calls for one frame are serialized, the observation and link writes are atomic upserts and `observation_count` is still bumped once; sources are written in transactions of 50 instead of one autocommit per statement, retried on a deadlock / lock wait timeout with another pipeline
- Settings: `STAR_FWHM_MIN_ARCSEC` becomes `STAR_FWHM_MIN_PX` (1.2 px) and `NARROWBAND_FILTERS` gains the multi-band OSC filters (`LeNhance,LeXtreme,LuLtimate,NBZ,QuadBand,TriBand,DuoBand`), matching observatory-pipeline; an operator-edited filter list or an existing `STAR_FWHM_MIN_PX` row is left alone — run `php spark migrate` on the hosting by hand after deploying
- Web UI: the frames list filters by QC — «OK», «Не OK» or one flag
- Web UI: with a whole page of frames checked, the selection bar offers «Выбрать все N кадров по фильтру» — the task then covers every frame matching the filter across pages (at most 5000), still split per owning pipeline; the delete confirmation states N
- Tasks: a task and its items are created in one transaction, so a worker can never claim a task whose items are still being inserted

## 1.3.0

### Minor Changes

- Pipelines: the version a pipeline reports in the `X-Pipeline-Version` header is stored with its heartbeat (`pipelines.version`, migration) and shown beside the pipeline's name on the dashboard and in `php spark pipeline:list` (sent by observatory-pipeline 1.4.0)

## 1.2.0

### Minor Changes

- Tasks: a task item can be reported `RUNNING` via `POST /tasks/{id}/items/progress` while the pipeline works on it; it never counts as done and a late `RUNNING` never reopens a `DONE`/`FAILED` item; migration adds the status (needed by observatory-pipeline 1.3.0)
- Web UI: the task page marks the item being processed as «обрабатывается»

## 1.1.0

### Minor Changes

- Web UI: sign-in with the account from `.env` (`ui.username`, `ui.password` — set both before deploying, or nobody can sign in); idle sign-out, a lock after repeated wrong passwords, CSRF on every form; `php spark ui:password` hashes the password
- Web UI: redesigned as a permanent console — overview of pipelines, tasks and alerts, selection bars for creating tasks, a chart gallery with a viewer, settings by group, day / night / red themes, phone layout; no Bootstrap
- Web UI: the sidebar shows the running version, read from `CHANGELOG.md`, which the deploy now uploads too
- Web UI: frames, sources, anomalies and charts sort by their columns and are paged, so every row is reachable — the row limit is gone, and anomalies are no longer cut off at the latest 500

### Patch Changes

- Deploy: GitHub Actions uploads the API to the shared hosting over FTP on every push to `main`; `.env` on the hosting is never overwritten
- Web UI: deleting anomalies also deletes their sources' GIF charts from disk

## 1.0.0

### Major Changes

- API: REST API for observatory-pipeline with API-key authentication
- Frames: registration with observation, instrument and quality metadata; re-analysis of a file updates its frame instead of duplicating it
- Sources: catalogue of detected objects with per-frame photometry history, matched by catalog identity or position
- Anomalies: stored per frame with an alert flag; a re-run replaces the frame's previous results
- Sky queries: historical sources near a position and frames covering a position, single and batch
- Charts: source tracks and finder chart storage (PNG and GIF), one chart per style
- Tasks: job queue for analysis, anomaly detection, charts, catalog preview, frame deletion and restart, with per-item progress
- Settings: pipeline configuration stored in the database and served to the pipeline
- Statistics: per-object frame count, total exposure and image quality
- Debug UI: frames, sources, tasks, charts, anomalies and settings pages; create tasks from a selection, merge sources
