<?php

namespace App\Models;

/**
 * Model for the `source_charts` table.
 *
 * One row per source_id (a finder/discovery chart) OR per task_item_id (a
 * PREVIEW_CATALOG_MATCH diagnostic chart, which has no source at all) —
 * exactly one of the two is set per row, never both. Tracks only the
 * style/frame_count of the chart currently on disk at
 * writable/uploads/charts/{source_id|task_item_id}.png; the image bytes
 * themselves are regenerated from scratch by observatory-pipeline on every
 * request, so a row is always fully replaced (upsertForSource()/
 * upsertForTaskItem()), never partially patched.
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

    protected $allowedFields = [
        'id',
        'source_id',
        'task_item_id',
        'style',
        'frame_count',
        'updated_at',
    ];

    /**
     * Create or replace the chart record for a source.
     *
     * @param string $sourceId   Source ID
     * @param string $style      'track', 'stamp_strip', or 'before_after'
     * @param int    $frameCount Number of epochs included in the current image
     *
     * @return array The resulting row
     */
    public function upsertForSource(string $sourceId, string $style, int $frameCount): array
    {
        $existing = $this->where('source_id', $sourceId)->first();
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
