<?php

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;

/**
 * Model for `anomaly_reviews` — the operator's "looked at it, nothing interesting" mark.
 *
 * A review belongs to (frame_id, source_id, anomaly_type), not to an `anomalies` row, so it
 * survives the delete-and-insert of every DETECT_ANOMALIES re-run and a re-ANALYZE (see
 * 2026-10-22-000001_CreateAnomalyReviewsTable.php). An anomaly is reviewed when a review with its
 * three keys exists; `joinReviews()` is the one place that join is spelled out.
 */
class AnomalyReviewModel extends BaseModel
{
    protected $table      = 'anomaly_reviews';
    protected $primaryKey = 'id';

    protected $useTimestamps = false;

    protected $allowedFields = [
        'id',
        'frame_id',
        'source_id',
        'anomaly_type',
        'note',
        'created_at',
    ];

    /**
     * LEFT JOIN each anomaly's review (alias `anomaly_reviews`) onto a builder over `anomalies`,
     * and optionally keep only the reviewed (`true`) or unreviewed (`false`) ones.
     */
    public static function joinReviews(BaseBuilder $builder, ?bool $reviewed = null): BaseBuilder
    {
        $builder->join(
            'anomaly_reviews',
            'anomaly_reviews.frame_id = anomalies.frame_id'
            . ' AND anomaly_reviews.source_id = anomalies.source_id'
            . ' AND anomaly_reviews.anomaly_type = anomalies.anomaly_type',
            'left'
        );

        if ($reviewed === true) {
            $builder->where('anomaly_reviews.id IS NOT NULL', null, false);
        } elseif ($reviewed === false) {
            $builder->where('anomaly_reviews.id IS NULL', null, false);
        }

        return $builder;
    }

    /**
     * Mark these anomalies reviewed with `note`, replacing the note of any already marked.
     * Anomalies without a source are skipped — there is nothing stable to key their review on.
     *
     * @param list<array{frame_id: string, source_id: ?string, anomaly_type: string}> $anomalies
     *
     * @return int how many anomalies were marked
     */
    public function markReviewed(array $anomalies, ?string $note): int
    {
        $note = $note !== null && trim($note) !== '' ? trim($note) : null;
        $now  = date('Y-m-d H:i:s');
        $rows = [];

        foreach ($anomalies as $a) {
            if (empty($a['source_id']) || empty($a['frame_id']) || empty($a['anomaly_type'])) {
                continue;
            }
            $key        = $a['frame_id'] . '|' . $a['source_id'] . '|' . $a['anomaly_type'];
            $rows[$key] = [$this->generateId(), $a['frame_id'], $a['source_id'], $a['anomaly_type'], $note, $now];
        }

        if ($rows === []) {
            return 0;
        }

        foreach (array_chunk(array_values($rows), 200) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?)'));
            $this->db->query(
                'INSERT INTO anomaly_reviews (id, frame_id, source_id, anomaly_type, note, created_at) VALUES '
                . $placeholders
                . ' ON DUPLICATE KEY UPDATE note = VALUES(note), created_at = VALUES(created_at)',
                array_merge(...$chunk)
            );
        }

        return count($rows);
    }

    /**
     * Take the review mark off these anomalies.
     *
     * @param list<array{frame_id: string, source_id: ?string, anomaly_type: string}> $anomalies
     *
     * @return int how many reviews were removed
     */
    public function unmarkReviewed(array $anomalies): int
    {
        $removed = 0;

        foreach ($anomalies as $a) {
            if (empty($a['source_id'])) {
                continue;
            }
            $this->builder()
                ->where('frame_id', $a['frame_id'])
                ->where('source_id', $a['source_id'])
                ->where('anomaly_type', $a['anomaly_type'])
                ->delete();
            $removed += $this->db->affectedRows();
        }

        return $removed;
    }
}
