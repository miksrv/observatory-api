<?php

namespace App\Models;

/**
 * Model for the `source_charts` table.
 *
 * One row per source_id, tracking the style/frame_count of the finder chart
 * currently on disk at writable/uploads/charts/{source_id}.png. The image
 * bytes are regenerated from scratch by observatory-pipeline's
 * modules/finder_chart.py on every new epoch, so this row is always fully
 * replaced (upsertForSource()), never partially patched.
 */
class SourceChartModel extends BaseModel
{
    protected $table      = 'source_charts';
    protected $primaryKey = 'id';

    // created_at is handled by the DB DEFAULT — no CI timestamp management needed.
    protected $useTimestamps = false;

    /**
     * Must match the ENUM constraint on the `style` column
     * (2026-08-06-000001_CreateSourceChartsTable.php) and the three rendering
     * paths in observatory-pipeline's modules/finder_chart.py.
     */
    public const ALLOWED_STYLES = ['track', 'stamp_strip', 'before_after'];

    protected $allowedFields = [
        'id',
        'source_id',
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
}
