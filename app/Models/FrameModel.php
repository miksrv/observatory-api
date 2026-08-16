<?php

namespace App\Models;

/**
 * Model for the `frames` table.
 *
 * Stores metadata for FITS image frames processed by the pipeline.
 */
class FrameModel extends BaseModel
{
    protected $table      = 'frames';
    protected $primaryKey = 'id';

    // created_at is handled by the DB DEFAULT — no CI timestamp management needed.
    protected $useTimestamps = false;

    protected $allowedFields = [
        'id',
        'filename',
        'obs_time',
        'ra_center',
        'dec_center',
        'fov_deg',
        'position_angle_deg',
        'pointing_error_arcsec',
        'pointing_error_ra_arcsec',
        'pointing_error_dec_arcsec',
        'quality_flag',
        'object',
        'exptime',
        'filter',
        'frame_type',
        'airmass',
        'telescope',
        'camera',
        'focal_length_mm',
        'aperture_mm',
        'sensor_temp',
        'sensor_temp_setpoint',
        'binning_x',
        'binning_y',
        'gain',
        'offset',
        'width_px',
        'height_px',
        'observer_name',
        'site_name',
        'site_lat',
        'site_lon',
        'site_elev_m',
        'software_capture',
        'qc_fwhm_median',
        'qc_elongation',
        'qc_snr_median',
        'qc_sky_background',
        'qc_star_count',
        'qc_eccentricity',
    ];

    /**
     * Find the frame previously registered for a given FITS filename, if any.
     *
     * `filename` carries a UNIQUE constraint (see the migration) precisely
     * so this lookup is meaningful: one FITS file corresponds to exactly one
     * `frames` row, no matter how many times ANALYZE re-processes it (e.g.
     * after improving the detection algorithm). Used by
     * FramesController::create() to upsert instead of always inserting.
     *
     * @return array|null The existing frame row, or null if this filename
     *                     has never been registered.
     */
    public function findByFilename(string $filename): ?array
    {
        return $this->where('filename', $filename)->first();
    }

    /**
     * Delete this frame and everything that only makes sense in relation to
     * it, then purge any source left with zero observations anywhere as a
     * result.
     *
     * Backs the DELETE_FRAME task type (see observatory-pipeline's
     * worker.py::_run_delete_frame_task() and that repo's CLAUDE.md
     * job-queue table) — called from
     * TasksController::postItemsProgress() once the pipeline reports the
     * frame's file successfully relocated to FITS_REJECTED. The file itself
     * is never touched here — only DB rows.
     *
     * Order, and why it's safe to lean on `ON DELETE CASCADE` here (unlike
     * SourceModel::mergeSources()/purgeIfOrphaned(), which delete anomalies/
     * charts by hand — see that model's docblocks for why a source needs
     * that extra care and a frame doesn't):
     *   1. Snapshot every source_id currently linked to this frame via
     *      FrameSourceModel::getSourceIdsForFrame() — needed *before* the
     *      delete below, since this frame's own frame_sources rows are
     *      about to disappear.
     *   2. Delete the `frames` row itself. `source_observations`,
     *      `frame_sources`, and `anomalies` all carry `frame_id` foreign
     *      keys with `ON DELETE CASCADE` (see the migrations), so deleting
     *      the frame row cascades all three automatically — no need to
     *      delete them by hand first.
     *   3. For each snapshotted source_id: SourceModel::purgeIfOrphaned() —
     *      the exact same helper FramesController::saveSources()'s
     *      reconciliation pass already calls when a re-analysis stops
     *      detecting a source anywhere. Deletes the source (+ its own
     *      anomalies/charts) only if this frame was its last remaining
     *      observation; a source still observed on another frame is left
     *      completely untouched.
     *
     * Wrapped in a transaction: either the frame and every now-orphaned
     * source disappear together, or nothing changes.
     *
     * @return array{deleted: bool, purged_source_ids: string[]}
     *
     * @throws \RuntimeException if the transaction fails
     */
    public function deleteWithDependents(string $frameId): array
    {
        $frameSourceModel = new FrameSourceModel();
        $sourceModel      = new SourceModel();

        $sourceIds = $frameSourceModel->getSourceIdsForFrame($frameId);

        $this->db->transStart();

        $deleted = $this->delete($frameId);

        $purgedSourceIds = [];
        foreach ($sourceIds as $sourceId) {
            if ($sourceModel->purgeIfOrphaned($sourceId)) {
                $purgedSourceIds[] = $sourceId;
            }
        }

        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            throw new \RuntimeException("Failed to delete frame {$frameId} (transaction rolled back).");
        }

        return [
            'deleted'           => (bool) $deleted,
            'purged_source_ids' => $purgedSourceIds,
        ];
    }
}
