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
 * writable/uploads/charts/{source_id}_{style}.png (or
 * writable/uploads/charts/{task_item_id}.png for the task-item-keyed kind,
 * which never had a multi-style problem to begin with and keeps its
 * original un-suffixed filename); the image bytes themselves are
 * regenerated from scratch by observatory-pipeline on every request, so a
 * row is always fully replaced (upsertForSource()/upsertForTaskItem()),
 * never partially patched.
 */
class SourceChartModel extends BaseModel
{
    protected $table      = 'source_charts';
    protected $primaryKey = 'id';

    // created_at is handled by the DB DEFAULT — no CI timestamp management needed.
    protected $useTimestamps = false;

    /**
     * Must match the ENUM constraint on the `style` column
     * (2026-08-06-000001_CreateSourceChartsTable.php). 'track'/'stamp_strip'/
     * 'before_after' are the three rendering paths in observatory-pipeline's
     * modules/finder_chart.py (source_id-keyed); 'catalog_preview' is
     * modules/catalog_preview.py's diagnostic (task_item_id-keyed) — the
     * only style that doesn't pair with a source_id.
     */
    public const ALLOWED_STYLES = ['track', 'stamp_strip', 'before_after', 'catalog_preview'];

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
     * @param string $style      'track', 'stamp_strip', or 'before_after'
     * @param int    $frameCount Number of epochs included in the current image
     *
     * @return array The resulting row
     */
    public function upsertForSource(string $sourceId, string $style, int $frameCount): array
    {
        $existing = $this->where('source_id', $sourceId)->where('style', $style)->first();
        $now      = date('Y-m-d H:i:s');

        if ($existing !== null) {
            $this->update($existing['id'], [
                'style'       => $style,
                'frame_count' => $frameCount,
                'updated_at'  => $now,
            ]);

            return $this->find($existing['id']);
        }

        $id = $this->insert([
            'source_id'   => $sourceId,
            'style'       => $style,
            'frame_count' => $frameCount,
            'updated_at'  => $now,
        ]);

        return $this->find($id);
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
        $existing = $this->where('task_item_id', $taskItemId)->first();
        $now      = date('Y-m-d H:i:s');

        if ($existing !== null) {
            $this->update($existing['id'], [
                'style'       => $style,
                'frame_count' => $frameCount,
                'updated_at'  => $now,
            ]);

            return $this->find($existing['id']);
        }

        $id = $this->insert([
            'task_item_id' => $taskItemId,
            'style'        => $style,
            'frame_count'  => $frameCount,
            'updated_at'   => $now,
        ]);

        return $this->find($id);
    }
}
