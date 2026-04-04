<?php

namespace App\Models;

/**
 * Model for the `source_observations` table (Photometry History).
 *
 * Stores time-varying measurements of each source from individual frames.
 * This is the key table for analyzing variability, light curves, etc.
 */
class SourceObservationModel extends BaseModel
{
    protected $table      = 'source_observations';
    protected $primaryKey = 'id';

    // created_at is handled by the DB DEFAULT — no CI timestamp management needed.
    protected $useTimestamps = false;

    protected $allowedFields = [
        'id',
        'source_id',
        'frame_id',
        'ra',
        'dec',
        'mag',
        'mag_err',
        'flux',
        'flux_err',
        'fwhm',
        'snr',
        'elongation',
        'obs_time',
    ];

    /**
     * Get all observations for a source, ordered by observation time.
     *
     * @param string      $sourceId  Source ID
     * @param string|null $fromTime  Optional: only observations after this time (MySQL datetime)
     * @param string|null $toTime    Optional: only observations before this time (MySQL datetime)
     * @param int         $limit     Max observations to return (default 1000)
     *
     * @return array Array of observation records
     */
    public function getObservationsForSource(
        string $sourceId,
        ?string $fromTime = null,
        ?string $toTime = null,
        int $limit = 1000
    ): array {
        $builder = $this->where('source_id', $sourceId);

        if ($fromTime !== null) {
            $builder->where('obs_time >=', $fromTime);
        }

        if ($toTime !== null) {
            $builder->where('obs_time <', $toTime);
        }

        return $builder->orderBy('obs_time', 'ASC')
            ->limit($limit)
            ->findAll();
    }

    /**
     * Get all observations for a frame.
     *
     * @param string $frameId Frame ID
     *
     * @return array Array of observation records
     */
    public function getObservationsForFrame(string $frameId): array
    {
        return $this->where('frame_id', $frameId)
            ->findAll();
    }
}

