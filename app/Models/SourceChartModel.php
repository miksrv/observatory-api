<?php

namespace App\Models;

/**
 * Model for the `source_charts` table.
 *
 * One row per (source_id, style) pair (a finder/discovery chart) OR per
 * task_item_id (a PREVIEW_CATALOG_MATCH diagnostic chart, which has no
 * source at all) — exactly one of source_id/task_item_id is set per row,
 * never both. A single source_id can hold up to one row per distinct style
 * — see 2026-08-11-000001_SourceChartsUniqueByStyle.php for why: a source
 * classified with more than one anomaly_type over its lifetime (e.g. an
 * uncatalogued mover first seen as UNKNOWN, then MOVING_UNKNOWN once it had
 * moved) needs both the "track" and "stamp_strip" charts to coexist, not
 * one silently overwriting the other. Tracks only the style/frame_count of
 * the chart currently on disk at
 * writable/uploads/charts/{source_id}_{style}.{ext} (or
 * writable/uploads/charts/{task_item_id}.png for the task-item-keyed kind,
 * which never had a multi-style problem to begin with and keeps its
 * original un-suffixed filename); the image bytes themselves are
 * regenerated from scratch by observatory-pipeline on every request, so a
 * row is always fully replaced (upsertForSource()/upsertForTaskItem()),
 * never partially patched. `{ext}` is 'gif' for a GIF_STYLES member, 'png'
 * for every other style — see SourcesController::extensionForStyle().
 */
class SourceChartModel extends BaseModel
{
    protected $table      = 'source_charts';
    protected $primaryKey = 'id';

    // created_at is handled by the DB DEFAULT — no CI timestamp management needed.
    protected $useTimestamps = false;

    /**
     * Must match the ENUM constraint on the `style` column
     * (2026-08-06-000001_CreateSourceChartsTable.php, widened by
     * 2026-08-11-000002_AddGifStylesToSourceCharts.php). 'track'/'stamp_strip'/
     * 'before_after' are the three static rendering paths in
     * observatory-pipeline's modules/finder_chart.py (source_id-keyed);
     * 'catalog_preview' is modules/catalog_preview.py's diagnostic
     * (task_item_id-keyed) — the only style that doesn't pair with a
     * source_id. 'track_gif'/'stamp_strip_gif' are the animated GIF
     * counterparts of 'track'/'stamp_strip' (CHART_GIF_ENABLED) — see
     * GIF_STYLES below for the subset this applies to.
     */
    public const ALLOWED_STYLES = [
        'track', 'stamp_strip', 'before_after', 'catalog_preview',
        'track_gif', 'stamp_strip_gif',
    ];

    /**
     * The subset of ALLOWED_STYLES stored as an animated GIF rather than a
     * PNG — used by SourcesController to pick the right magic-byte
     * validation, file extension, and Content-Type for a given style,
     * instead of hardcoding "PNG" everywhere the way this table did before
     * GIF charts existed.
     */
    public const GIF_STYLES = ['track_gif', 'stamp_strip_gif'];

    /**
     * Display priority for a source-id chart lookup that doesn't name a
     * specific style (e.g. a consumer written before multi-style charts
     * existed) — used by Api\V1\SourcesController::chart() and
     * Web\ChartsController::image() to pick ONE chart when a source_id now
     * has more than one. "track" (motion evidence) wins over "stamp_strip"/
     * "before_after" (no motion evidence) since it's the more informative of
     * the two for an ambiguous/legacy request; 'catalog_preview' is excluded
     * — that style is task_item_id-keyed and never coexists with a
     * source_id-keyed row anyway.
     */
    public const STYLE_DISPLAY_PRIORITY = ['track', 'stamp_strip', 'before_after'];

    protected $allowedFields = [
        'id',
        'source_id',
        'task_item_id',
        'style',
        'frame_count',
        'updated_at',
    ];

    /**
     * Create or replace the chart record for a (source, style) pair.
     *
     * Keyed by (source_id, style) — NOT source_id alone — so uploading a
     * "stamp_strip" chart for a source that already has a "track" chart
     * updates/creates only the stamp_strip row, leaving the track row (and
     * its PNG on disk) untouched. See this model's class docblock and
     * 2026-08-11-000001_SourceChartsUniqueByStyle.php for why.
     *
     * @param string $sourceId   Source ID
     * @param string $style      One of ALLOWED_STYLES except 'catalog_preview' (task_item_id-keyed only)
     * @param int    $frameCount Number of epochs included in the current image
     *
     * @return array The resulting row
     */
    public function upsertForSource(string $sourceId, string $style, int $frameCount): array
    {
        // One statement, resolved by the unique key uq_source_charts_source_style
        // — see atomicUpsert() for why not SELECT-then-INSERT/UPDATE.
        $this->atomicUpsert([
            'id'          => $this->generateId(),
            'source_id'   => $sourceId,
            'style'       => $style,
            'frame_count' => $frameCount,
            'updated_at'  => date('Y-m-d H:i:s'),
        ], ['frame_count', 'updated_at']);

        return $this->where('source_id', $sourceId)->where('style', $style)->first();
    }

    /**
     * INSERT ... ON DUPLICATE KEY UPDATE against whichever unique key the row
     * hits — `(source_id, style)` for a source chart, `task_item_id` for a
     * catalog-preview chart.
     *
     * Both upserts used to be a SELECT followed by an INSERT or UPDATE. Two
     * concurrent uploads for the same key — a client retry racing the
     * original request, or two workers — could both pass the "doesn't exist
     * yet" check and both INSERT; the loser's unique-key violation surfaced
     * as a DatabaseException → 500 under DBDebug = true, which the
     * pipeline's 5xx-only retry policy then resolved through the UPDATE
     * branch. It only self-healed because of that configuration: with
     * DBDebug off, or the exception caught somewhere, the loser's upload
     * would have been silently lost (API audit 2026-08-20, finding M3). A
     * single statement has no window to lose. The freshly generated `id` is
     * simply discarded when the row already exists.
     *
     * @param array<string, mixed> $row           Full row to insert
     * @param list<string>         $updateColumns Columns to refresh from the new values on conflict
     */
    private function atomicUpsert(array $row, array $updateColumns): void
    {
        $columns = array_keys($row);
        $quoted  = array_map(fn (string $c): string => $this->db->escapeIdentifiers($c), $columns);
        $updates = array_map(
            fn (string $c): string => $this->db->escapeIdentifiers($c) . ' = VALUES(' . $this->db->escapeIdentifiers($c) . ')',
            $updateColumns,
        );

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
            $this->db->escapeIdentifiers($this->table),
            implode(', ', $quoted),
            implode(', ', array_fill(0, count($columns), '?')),
            implode(', ', $updates),
        );

        $this->db->query($sql, array_values($row));
    }

    /**
     * All chart rows currently stored for a source_id — 0, 1, or (since
     * multi-style support) up to count(ALLOWED_STYLES)-1 rows (excludes
     * 'catalog_preview', which never pairs with a source_id). Used wherever
     * a caller needs to know every style available for a source rather than
     * picking just one — e.g. the "/ui/charts" gallery, or a style-less
     * legacy chart lookup falling back through STYLE_DISPLAY_PRIORITY.
     *
     * @return array<int, array> Rows ordered by style priority order, not any particular column
     */
    public function getAllForSource(string $sourceId): array
    {
        $rows = $this->where('source_id', $sourceId)->findAll();

        usort($rows, static function (array $a, array $b): int {
            $pa = array_search($a['style'], self::STYLE_DISPLAY_PRIORITY, true);
            $pb = array_search($b['style'], self::STYLE_DISPLAY_PRIORITY, true);

            return ($pa === false ? PHP_INT_MAX : $pa) <=> ($pb === false ? PHP_INT_MAX : $pb);
        });

        return $rows;
    }

    /**
     * Create or replace the chart record for a PREVIEW_CATALOG_MATCH task
     * item — the task_item_id-keyed counterpart of upsertForSource() above.
     * Always style='catalog_preview', frame_count=1 (a single-frame chart,
     * not an epoch series) — kept as explicit parameters anyway rather than
     * hardcoded here, so this method's shape mirrors upsertForSource()'s
     * and doesn't quietly assume something the caller didn't ask for.
     *
     * @param string $taskItemId Task item ID
     * @param string $style      Always 'catalog_preview' today; parameterized for symmetry
     * @param int    $frameCount Always 1 today; parameterized for symmetry
     *
     * @return array The resulting row
     */
    public function upsertForTaskItem(string $taskItemId, string $style, int $frameCount): array
    {
        // Resolved by uq_source_charts_task_item; `style` is refreshed too,
        // since it is not part of that key. See atomicUpsert().
        $this->atomicUpsert([
            'id'           => $this->generateId(),
            'task_item_id' => $taskItemId,
            'style'        => $style,
            'frame_count'  => $frameCount,
            'updated_at'   => date('Y-m-d H:i:s'),
        ], ['style', 'frame_count', 'updated_at']);

        return $this->where('task_item_id', $taskItemId)->first();
    }
}
