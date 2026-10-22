<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Create `anomaly_reviews` — the operator's "looked at it, nothing interesting" mark on an
 * anomaly, with a note.
 *
 * A separate table rather than columns on `anomalies`, because POST /frames/{id}/anomalies
 * replaces a frame's whole anomaly set (CLAUDE.md): a mark stored on the row would vanish on the
 * next DETECT_ANOMALIES. A review is keyed by what survives a re-run instead — the frame
 * (`frames.id` is kept across re-registration by filename), the source (`sources.id` is resolved
 * by catalog identity or position) and the anomaly type. A re-run that classifies the same source
 * on the same frame the same way finds its review again; a new frame, or a new type, is new and
 * shows up unreviewed. An anomaly with no source cannot be reviewed.
 */
class CreateAnomalyReviewsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'       => 'CHAR',
                'constraint' => 24,
            ],
            'frame_id' => [
                'type'       => 'CHAR',
                'constraint' => 24,
                'null'       => false,
            ],
            'source_id' => [
                'type'       => 'CHAR',
                'constraint' => 24,
                'null'       => false,
            ],
            // Free-form VARCHAR rather than the anomalies ENUM: a review must outlive a type that
            // a later release renames or drops, and it only ever has to equal anomalies.anomaly_type.
            'anomaly_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => false,
            ],
            'note' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['frame_id', 'source_id', 'anomaly_type'], 'uq_anomaly_reviews_key');
        $this->forge->addKey('source_id');
        // A deleted frame or source (DELETE_FRAME, a source merge) takes its reviews with it.
        $this->forge->addForeignKey('frame_id', 'frames', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('source_id', 'sources', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('anomaly_reviews', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('anomaly_reviews', true);
    }
}
