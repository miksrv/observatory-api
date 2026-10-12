# Changelog

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
