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
     * One atomic statement against uk_frame_source, not a lookup followed by
     * an insert: two concurrent POST /frames/{id}/sources calls for the same
     * frame (the pipeline retrying a request that timed out client-side while
     * the first was still running) both saw "no link yet" and the second
     * insert failed on the unique key with a 500. `ON DUPLICATE KEY UPDATE
     * id = id` changes nothing on a duplicate and, unlike INSERT IGNORE,
     * still fails on any other error.
     *
     * @param string $frameId  Frame ID
     * @param string $sourceId Source ID
     *
     * @return bool True if link was created, false if already existed
     */
    public function linkSourceToFrame(string $frameId, string $sourceId): bool
    {
        $this->db->query(
            'INSERT INTO ' . $this->db->protectIdentifiers($this->table, true)
            . ' (id, frame_id, source_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE id = id',
            [$this->generateId(), $frameId, $sourceId],
        );

        // MySQL/MariaDB report 1 for an inserted row and 0 for an unchanged
        // duplicate (the MySQLi driver's foundRows option is off).
        return $this->db->affectedRows() === 1;
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

