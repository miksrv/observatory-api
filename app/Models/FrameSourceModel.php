<?php

namespace App\Models;

/**
 * Model for the `frame_sources` table (Many-to-Many Link).
 *
 * Quick lookup table linking frames to sources without the full observation data.
 */
class FrameSourceModel extends BaseModel
{
    protected $table      = 'frame_sources';
    protected $primaryKey = 'id';

    // created_at is handled by the DB DEFAULT — no CI timestamp management needed.
    protected $useTimestamps = false;

    protected $allowedFields = [
        'id',
        'frame_id',
        'source_id',
    ];

    /**
     * Link a source to a frame (if not already linked).
     *
     * @param string $frameId  Frame ID
     * @param string $sourceId Source ID
     *
     * @return bool True if link was created, false if already existed
     */
    public function linkSourceToFrame(string $frameId, string $sourceId): bool
    {
        // Check if link already exists
        $existing = $this->where('frame_id', $frameId)
            ->where('source_id', $sourceId)
            ->first();

        if ($existing !== null) {
            return false;
        }

        // Create new link
        $this->insert([
            'frame_id'  => $frameId,
            'source_id' => $sourceId,
        ]);

        return true;
    }

    /**
     * Get all source IDs for a frame.
     *
     * @param string $frameId Frame ID
     *
     * @return array Array of source IDs
     */
    public function getSourceIdsForFrame(string $frameId): array
    {
        $results = $this->select('source_id')
            ->where('frame_id', $frameId)
            ->findAll();

        return array_column($results, 'source_id');
    }

    /**
     * Get all frame IDs for a source.
     *
     * @param string $sourceId Source ID
     *
     * @return array Array of frame IDs
     */
    public function getFrameIdsForSource(string $sourceId): array
    {
        $results = $this->select('frame_id')
            ->where('source_id', $sourceId)
            ->findAll();

        return array_column($results, 'frame_id');
    }
}

