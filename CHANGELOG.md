# Changelog

## 1.3.0

### Minor Changes

- Pipelines: the version a pipeline reports in the `X-Pipeline-Version` header is stored with its heartbeat (`pipelines.version`, migration) and shown on the dashboard and in `php spark pipeline:list` (sent by observatory-pipeline 1.4.0)

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
