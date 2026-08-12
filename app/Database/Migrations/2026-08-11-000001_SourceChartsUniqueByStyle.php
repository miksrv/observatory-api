<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration 11 — allow a source to hold one chart PER STYLE instead of exactly one chart total.
 *
 * Background: `modules/anomaly_detector.py` (observatory-pipeline) classifies a source
 * independently on every frame it appears on, so the SAME `source_id` can legitimately collect
 * anomalies of more than one `anomaly_type` over its lifetime — e.g. a fast-moving uncatalogued
 * object first seen with no history at all (`UNKNOWN`) and, once it has moved, classified
 * `MOVING_UNKNOWN` on a later frame. Both are real, distinct classifications of the same source,
 * not a duplicate of one frame (that case is `4c5f046`'s `_dedupe_uncatalogued_subtraction_pair()`,
 * already fixed on the pipeline side).
 *
 * `uq_source_charts_source` (2026-08-06-000001_CreateSourceChartsTable.php) enforced ONE row per
 * `source_id` regardless of `style` — `SourceChartModel::upsertForSource()` looked a row up by
 * `source_id` alone, so requesting a chart for a second `anomaly_type` on the same source
 * (real incident, 2026-08-11: source_id `6a7be36b4d7578.98132403`, 12 `MOVING_UNKNOWN` + 1
 * `UNKNOWN` anomalies) silently overwrote whichever chart/style was rendered first — the operator
 * only ever saw one PNG (whichever `AnomaliesController::index()`'s grouping happened to submit
 * last), never both the "track" (motion) and "stamp_strip" (blink) evidence the two classification
 * types actually warrant.
 *
 * This migration drops that single-column unique key and replaces it with a composite
 * `(source_id, style)` unique key, so `upsertForSource()` (updated alongside this migration to key
 * its lookup the same way) can hold one row per style per source without either colliding on
 * insert or silently clobbering a different style's row. No data migration is needed: the old
 * key already guaranteed at most one row per `source_id`, so every existing row trivially still
 * satisfies the new, looser composite constraint.
 *
 * `uq_source_charts_task_item` (the PREVIEW_CATALOG_MATCH diagnostic chart's key) is untouched —
 * that chart type is not source_id-keyed at all and was never affected by this problem.
 */
class SourceChartsUniqueByStyle extends Migration
{
    public function up(): void
    {
        // Add the new composite key BEFORE dropping the old single-column one — `source_id`
        // carries a FOREIGN KEY to `sources`.`id` (see CreateSourceChartsTable.php), and
        // MySQL/MariaDB requires SOME index covering a FK column at all times; dropping
        // uq_source_charts_source first would leave source_id momentarily uncovered and fail with
        // "Cannot drop index ... needed in a foreign key constraint". The new composite key's
        // leftmost column (source_id) satisfies that requirement throughout, so this order never
        // leaves the column uncovered.
        $this->db->query('ALTER TABLE `source_charts` ADD UNIQUE KEY `uq_source_charts_source_style` (`source_id`, `style`)');
        $this->db->query('ALTER TABLE `source_charts` DROP KEY `uq_source_charts_source`');
    }

    /**
     * Reverting is only meaningful if every source_id still has at most one chart row at the
     * point this runs — same dev-only-corrective-migration caveat as
     * 2026-08-07-000002_AddTaskItemIdToSourceCharts.php's own down(). Rolling this back after
     * real multi-style data has accumulated will fail on the ADD UNIQUE KEY step (duplicate
     * source_id values) rather than silently dropping rows — that failure is the correct
     * behavior here, not a bug to work around. Same add-before-drop ordering as up(), for the
     * same FK-index reason.
     */
    public function down(): void
    {
        $this->db->query('ALTER TABLE `source_charts` ADD UNIQUE KEY `uq_source_charts_source` (`source_id`)');
        $this->db->query('ALTER TABLE `source_charts` DROP KEY `uq_source_charts_source_style`');
    }
}
