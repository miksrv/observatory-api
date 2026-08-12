<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration 12 — add 'track_gif' / 'stamp_strip_gif' to the `source_charts`.`style` ENUM.
 *
 * Background: observatory-pipeline's modules/finder_chart.py now renders an animated GIF
 * alongside the existing static PNG whenever a source's chart style is 'track' (moving objects —
 * ASTEROID/COMET/MOVING_UNKNOWN/SPACE_DEBRIS) or 'stamp_strip' (stationary anomalies with 2+
 * epochs) — see CHART_GIF_ENABLED there. The GIF is uploaded as its own chart, via the SAME
 * `POST /sources/{id}/chart` endpoint, keyed by its own style value ('track_gif'/'stamp_strip_gif')
 * rather than overwriting the static chart's row — same one-row-per-(source_id, style) design
 * 2026-08-11-000001_SourceChartsUniqueByStyle.php already established for 'track' vs
 * 'stamp_strip' coexisting on one source. There is deliberately no '_gif' counterpart for
 * 'before_after' or 'catalog_preview' — see that pipeline module's docstring for why.
 *
 * This migration only widens the ENUM; SourcesController::uploadChart()/chart() and
 * resolveChartPath() are updated separately (same commit) to actually accept, store, and serve
 * GIF bytes with the correct file extension and Content-Type — a wider ENUM alone would let
 * upsertForSource() write a 'track_gif' row, but the file on disk would still be misnamed
 * `{id}_track_gif.png` and re-served as `image/png` without that other change.
 */
class AddGifStylesToSourceCharts extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "ALTER TABLE `source_charts` MODIFY `style` "
            . "ENUM('track', 'stamp_strip', 'before_after', 'catalog_preview', 'track_gif', 'stamp_strip_gif') NOT NULL"
        );
    }

    /**
     * Dev-only corrective revert, same caveat as this table's other style-ENUM migrations
     * (2026-08-07-000002_AddTaskItemIdToSourceCharts.php, 2026-08-11-000001_
     * SourceChartsUniqueByStyle.php): rolling back after real 'track_gif'/'stamp_strip_gif' rows
     * exist will fail (or, outside strict SQL mode, silently blank the column) rather than
     * migrating that data anywhere — reverting is only meaningful before any GIF chart has ever
     * been uploaded.
     */
    public function down(): void
    {
        $this->db->query(
            "ALTER TABLE `source_charts` MODIFY `style` "
            . "ENUM('track', 'stamp_strip', 'before_after', 'catalog_preview') NOT NULL"
        );
    }
}
